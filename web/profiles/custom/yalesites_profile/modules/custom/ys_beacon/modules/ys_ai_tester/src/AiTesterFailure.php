<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ys_beacon\Exception\BeaconStageException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads everything knowable about a failed question from its exception.
 *
 * Two separate problems live here, and both come from the same root cause -
 * the exception classes these APIs throw put almost nothing in their message:
 *
 * - **Classification.** Whether a retry could plausibly succeed. Decided from
 *   HTTP status codes and the AI module's typed exceptions, never from message
 *   wording, for the reason already documented in
 *   \Drupal\ys_beacon_portkey\Plugin\AiProvider\PortkeyProvider: Portkey fronts
 *   several upstream providers, so the wording varies by provider and by
 *   openai-php version while the status code stays stable.
 * - **Description.** A log line with enough in it to diagnose the failure once.
 *   openai-php's ServerException message is literally "Server error (HTTP 500)
 *   occurred." and discards the response body it is holding; Guzzle truncates
 *   response bodies to 120 characters when building an exception message, which
 *   is how a production 400 was recorded as cut off mid-word with its cause
 *   lost.
 *
 * Everything is static and dependency-free so it can be unit tested directly,
 * matching how the rest of this module keeps its decisions testable.
 */
class AiTesterFailure {

  /**
   * Maximum response-body characters to include in a log line.
   *
   * Generously above Guzzle's 120-character summary, but still bounded: this
   * lands in watchdog, once per failed question.
   */
  const MAX_BODY_LENGTH = 2000;

  /**
   * How deep to follow ::getPrevious() before giving up.
   *
   * These chains are two or three links in practice; the cap only exists so a
   * pathological cycle cannot hang a logging call.
   */
  const MAX_CHAIN_DEPTH = 10;

  /**
   * Gateway correlation headers, in the order they are reported.
   *
   * Without one of these there is no way to find a failed request in Portkey's
   * own logs, which is what made "no errors in Portkey" impossible to confirm
   * or refute.
   */
  const TRACE_HEADERS = ['x-portkey-trace-id', 'x-portkey-request-id'];

  /**
   * Returns whether retrying this failure could plausibly succeed.
   *
   * The exception chain is walked rather than just the outermost exception,
   * which is essential rather than defensive: LegacyConversationClient::ask()
   * rethrows every Guzzle failure wrapped in a \RuntimeException, and
   * BeaconAnswerService wraps its own in a BeaconStageException. Reading only
   * the top of either chain would classify every one of those failures as
   * permanent and retry none of them.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return bool
   *   TRUE when the same request might succeed on a later attempt.
   */
  public static function isTransient(\Throwable $e): bool {
    foreach (self::chain($e) as $link) {
      // The AI module's typed exceptions are unambiguous, so they settle it
      // without looking at any status code. A rate limit clears on its own; an
      // exhausted quota does not, and retrying it only burns time.
      if ($link instanceof AiRateLimitException) {
        return TRUE;
      }
      if ($link instanceof AiQuotaException) {
        return FALSE;
      }

      $code = self::statusCode($link);
      // Only an error status settles it. statusCode() reports whatever the
      // response carried, and a success status can reach here: openai-php
      // raises UnserializableResponse on an unparseable body, and its own
      // ErrorException docblock warns a streamed request "might be 200 even in
      // case of an error". Treating 200 as decisive would both answer "not
      // transient" on no evidence and abort the walk before the wrapped cause
      // and the transport checks below were ever consulted.
      if ($code !== NULL && $code >= 400) {
        // 5xx is the far end breaking on a well-formed request. 429 is
        // throttling. 408 is the server giving up on a slow request. Every
        // other 4xx - a filtered completion, a malformed request, a bad key -
        // will fail identically however many times it is sent.
        return $code >= 500 || $code === 429 || $code === 408;
      }

      // No status code means no HTTP response came back at all, so the request
      // never completed: a connection failure or a read timeout. Both are worth
      // one more attempt.
      if ($link instanceof ConnectException || $link instanceof RequestException) {
        return TRUE;
      }
    }

    // Nothing in the chain looks like a transport failure. This is the path a
    // configuration error takes - "the assistant is not available on this site"
    // is a bare \RuntimeException - and it must fail on the first attempt
    // rather than being retried three times per question.
    return FALSE;
  }

  /**
   * Returns the HTTP status carried by an exception, if any.
   *
   * @param \Throwable $e
   *   The failure. Not walked - callers that want the chain use ::chain().
   *
   * @return int|null
   *   The status code, or NULL when this exception carries no HTTP response.
   */
  public static function statusCode(\Throwable $e): ?int {
    // A backend that parsed its status out of a streamed body reports it
    // directly: there is no HTTP response to read it off, because the response
    // itself was a 200.
    if ($e instanceof UpstreamStatusInterface) {
      return $e->getUpstreamStatusCode();
    }

    return self::responseOf($e)?->getStatusCode();
  }

  /**
   * Returns the milliseconds a Retry-After header asks the caller to wait.
   *
   * Only the delta-seconds form is honored. The HTTP-date form is legal but
   * rare from these APIs, and misreading a date as a number of seconds would
   * produce an absurd wait, so anything unparseable defers to normal backoff.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return int|null
   *   Milliseconds to wait, or NULL when the response gave no usable hint.
   */
  public static function retryAfterMs(\Throwable $e): ?int {
    foreach (self::chain($e) as $link) {
      $response = self::responseOf($link);
      if ($response === NULL) {
        continue;
      }
      $header = trim($response->getHeaderLine('Retry-After'));
      if ($header !== '' && ctype_digit($header)) {
        return (int) $header * 1000;
      }
    }

    return NULL;
  }

  /**
   * Returns which upstream call failed, when the chain says so.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return string|null
   *   A BeaconStageException stage name, or NULL for a backend that does not
   *   label its stages (the legacy assistant is a single request, so there is
   *   nothing to disambiguate).
   */
  public static function stageOf(\Throwable $e): ?string {
    foreach (self::chain($e) as $link) {
      if ($link instanceof BeaconStageException) {
        return $link->getStage();
      }
    }

    return NULL;
  }

  /**
   * Builds a diagnosable one-line description of a failure.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return string
   *   The exception class and message, the failing stage where known, and the
   *   HTTP status, correlation headers, and response body of the first response
   *   found in the chain.
   */
  public static function describe(\Throwable $e): string {
    $parts = [self::shortClass($e) . ': ' . $e->getMessage()];

    $stage = self::stageOf($e);
    if ($stage !== NULL) {
      $parts[] = 'stage: ' . self::stageHint($stage);
    }

    $response = NULL;
    foreach (self::chain($e) as $link) {
      $response = self::responseOf($link);
      if ($response !== NULL) {
        break;
      }
    }

    if ($response !== NULL) {
      $parts[] = 'HTTP ' . $response->getStatusCode();
      foreach (self::TRACE_HEADERS as $header) {
        $value = trim($response->getHeaderLine($header));
        if ($value !== '') {
          $parts[] = $header . ': ' . $value;
        }
      }
      $parts[] = 'body: ' . self::bodyOf($response);
    }

    return implode(' | ', $parts);
  }

  /**
   * Explains a stage, and which credential to check for it.
   *
   * The two Portkey calls authenticate with different keys
   * (ys_beacon_portkey.settings: api_key for chat, embeddings_api_key for
   * embeddings), so they can sit in different gateway workspaces. Naming the
   * key in the log is the difference between checking the right log and
   * concluding the gateway saw nothing.
   *
   * @param string $stage
   *   A BeaconStageException stage name.
   *
   * @return string
   *   The stage with its credential hint.
   */
  protected static function stageHint(string $stage): string {
    return match ($stage) {
      // No credential hint here on purpose. RagRetriever swallows a failed
      // query and degrades to an empty citation list, so an embeddings or
      // Azure AI Search outage never reaches this stage - it is logged
      // separately as 'Beacon retrieval failed'. This firing at all means
      // something escaped that handling, which is worth saying plainly rather
      // than pointing at a credential the failure probably has nothing to do
      // with.
      BeaconStageException::STAGE_RETRIEVAL => $stage . ' (unexpected: a failed retrieval normally degrades to an empty citation list instead of raising)',
      BeaconStageException::STAGE_CHAT => $stage . ' (Portkey api_key)',
      BeaconStageException::STAGE_CHAT_FOLLOW_UP => $stage . ' (Portkey api_key, tool follow-up turn)',
      default => $stage,
    };
  }

  /**
   * Returns the response body, bounded, without ever throwing.
   *
   * This runs only on a path that is already handling a failure, so it must not
   * become the thing that fails. Reading consumes the stream, which is safe
   * here: nothing downstream reads the response of a failed request.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response to read.
   *
   * @return string
   *   The body, truncated on a byte boundary with an explicit marker when
   *   oversized, or a stated reason it could not be read - never a silent
   *   empty string.
   */
  protected static function bodyOf(ResponseInterface $response): string {
    try {
      $stream = $response->getBody();
      if ($stream->isSeekable()) {
        $stream->rewind();
      }
      // Bound the read itself, not just the reported length: a gateway or
      // WAF answering with a large HTML error page would otherwise be pulled
      // into memory in full - once per attempt - only to be cut down
      // afterwards. One byte past the limit is all it takes to know the body
      // was longer than the limit.
      //
      // Everything here is measured in BYTES, deliberately and consistently:
      // Utils::copyToString() bounds on strlen(), so mixing in a character
      // count below would compare two different units and let a body that is
      // over the byte limit but under the character limit through as though it
      // were complete - silently truncated with no marker, which is the exact
      // failure this class exists to stop.
      $body = trim(Utils::copyToString($stream, self::MAX_BODY_LENGTH + 1));
    }
    catch (\Throwable $unreadable) {
      return '(unreadable: ' . $unreadable->getMessage() . ')';
    }

    if ($body === '') {
      return '(empty)';
    }
    if (strlen($body) > self::MAX_BODY_LENGTH) {
      // mb_strcut(), not mb_substr(): it cuts on a byte count without ever
      // splitting a multi-byte character. A partial sequence here would be
      // worse than a long line - Drupal escapes log placeholders through
      // htmlspecialchars(), which returns an empty string for invalid UTF-8,
      // so a torn character would blank the whole message.
      return mb_strcut($body, 0, self::MAX_BODY_LENGTH)
        . ' (truncated at ' . self::MAX_BODY_LENGTH . ' bytes)';
    }

    return $body;
  }

  /**
   * Returns the HTTP response an exception carries, if it carries one.
   *
   * Covers both client libraries in play. openai-php exposes the response as a
   * public property - ServerException::$response and ErrorException::$response,
   * the latter readonly - while Guzzle exposes it through
   * BadResponseException::getResponse(). Both accesses are visibility-aware on
   * purpose: get_object_vars() returns only public properties when called from
   * outside the class, and is_callable() resolves a method against this scope,
   * so neither a private property nor a protected method of the same name can
   * turn a logging call into a second failure.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response, or NULL when there is none.
   */
  protected static function responseOf(\Throwable $e): ?ResponseInterface {
    $public = get_object_vars($e);
    if (($public['response'] ?? NULL) instanceof ResponseInterface) {
      return $public['response'];
    }

    // is_callable(), not method_exists(): method_exists() answers TRUE for a
    // protected or private method too, so calling it would raise an Error from
    // inside a catch block - making this the thing that fails. is_callable()
    // resolves visibility from here, the same reason get_object_vars() is used
    // for the property above.
    if (is_callable([$e, 'getResponse'])) {
      $response = $e->getResponse();
      if ($response instanceof ResponseInterface) {
        return $response;
      }
    }

    return NULL;
  }

  /**
   * Returns an exception's class name without its namespace.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return string
   *   The short class name.
   */
  protected static function shortClass(\Throwable $e): string {
    $parts = explode('\\', get_class($e));
    return end($parts);
  }

  /**
   * Returns an exception and its causes, outermost first.
   *
   * @param \Throwable $e
   *   The failure.
   *
   * @return \Throwable[]
   *   The chain, capped at ::MAX_CHAIN_DEPTH.
   */
  protected static function chain(\Throwable $e): array {
    $chain = [];
    $link = $e;
    while ($link !== NULL && count($chain) < self::MAX_CHAIN_DEPTH) {
      $chain[] = $link;
      $link = $link->getPrevious();
    }

    return $chain;
  }

}
