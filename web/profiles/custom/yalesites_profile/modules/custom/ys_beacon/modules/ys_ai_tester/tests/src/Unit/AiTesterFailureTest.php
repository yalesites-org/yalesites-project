<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterFailure;
use Drupal\ys_ai_tester\UpstreamStatusInterface;
use Drupal\ys_beacon\Exception\BeaconStageException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException as GuzzleServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException as OpenAiErrorException;
use OpenAI\Exceptions\ServerException as OpenAiServerException;

/**
 * Tests what the tester can learn about a failed question from its exception.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\AiTesterFailure
 *
 * @group ys_beacon
 */
class AiTesterFailureTest extends UnitTestCase {

  /**
   * Builds a stand-in for a backend exception carrying its own status code.
   *
   * A local double rather than ys_ai_tester_legacy's LegacyStreamException: a
   * backend submodule depends on this module, so this module's tests must not
   * depend back on one. What is under test is the interface contract, not any
   * particular backend's implementation of it.
   *
   * @param string $message
   *   The exception message.
   * @param int|null $status
   *   The status code the payload reported.
   *
   * @return \Throwable
   *   The stand-in exception.
   */
  protected static function streamedFailure(string $message, ?int $status = NULL): \Throwable {
    return new class($message, $status) extends \RuntimeException implements UpstreamStatusInterface {

      /**
       * Constructs the stand-in.
       *
       * @param string $message
       *   The exception message.
       * @param int|null $statusCode
       *   The reported status code.
       */
      public function __construct(string $message, protected ?int $statusCode) {
        parent::__construct($message);
      }

      /**
       * {@inheritdoc}
       */
      public function getUpstreamStatusCode(): ?int {
        return $this->statusCode;
      }

    };
  }

  /**
   * @covers ::isTransient
   * @dataProvider provideTransienceCases
   */
  public function testIsTransient(\Throwable $e, bool $expected): void {
    $this->assertSame($expected, AiTesterFailure::isTransient($e));
  }

  /**
   * Exception, and whether retrying it could plausibly succeed.
   *
   * The wrapped cases are the ones that matter most in practice:
   * LegacyConversationClient::ask() rethrows every Guzzle failure inside a
   * \RuntimeException, so a classifier that only looked at the top-level
   * exception would treat every legacy failure as permanent and never retry
   * the transient ones.
   */
  public static function provideTransienceCases(): array {
    $request = new Request('POST', 'https://example.test/conversation');

    return [
      // Server-side faults: the request was well-formed, the far end broke.
      'openai 500' => [new OpenAiServerException(new Response(500)), TRUE],
      'openai 503' => [new OpenAiServerException(new Response(503)), TRUE],
      'guzzle 502' => [new GuzzleServerException('bad gateway', $request, new Response(502)), TRUE],

      // Throttling and timeouts clear on their own.
      'openai 429' => [new OpenAiErrorException(['message' => 'slow down'], new Response(429)), TRUE],
      'request timeout 408' => [new OpenAiErrorException(['message' => 'timeout'], new Response(408)), TRUE],
      'ai rate limit' => [new AiRateLimitException('rate limited'), TRUE],
      'connect failure' => [new ConnectException('cURL error 28: timed out', $request), TRUE],
      // No response at all means the request never completed - a network-level
      // failure, which is the shape a read timeout arrives in.
      'request exception with no response' => [new RequestException('cURL error 28', $request), TRUE],

      // Deterministic failures: the same request will fail the same way, so a
      // retry only burns time and quota.
      'openai 400' => [new OpenAiErrorException(['message' => 'content filtered'], new Response(400)), FALSE],
      'guzzle 400' => [new ClientException('bad request', $request, new Response(400)), FALSE],
      'openai 401' => [new OpenAiErrorException(['message' => 'bad key'], new Response(401)), FALSE],
      'openai 404' => [new OpenAiErrorException(['message' => 'no model'], new Response(404)), FALSE],
      // An exhausted quota is not a rate limit: waiting does not refill it.
      'ai quota' => [new AiQuotaException('out of credits'), FALSE],
      // The tester's own "assistant is not available on this site" guard. A
      // misconfiguration must fail on the first attempt, not three.
      'bare runtime exception' => [
        new \RuntimeException('The "beacon" assistant is not available on this site.'),
        FALSE,
      ],

      // Reported by a backend that read its status out of a streamed body,
      // where the HTTP status was 200 and carries no information at all.
      'in-stream 429' => [self::streamedFailure('Rate limit exceeded', 429), TRUE],
      'in-stream 500' => [self::streamedFailure('Upstream failed', 500), TRUE],
      'in-stream 400' => [self::streamedFailure('Bad request', 400), FALSE],
      // A stream error with no usable code stays permanent rather than being
      // retried on a guess.
      'in-stream with no code' => [self::streamedFailure('Something went wrong'), FALSE],

      // Wrapped, as the legacy client and the staged Beacon path rethrow.
      'runtime wrapping a 500' => [
        new \RuntimeException('The legacy ai_engine request failed: ...', 0, new GuzzleServerException('boom', $request, new Response(500))),
        TRUE,
      ],
      'runtime wrapping a 400' => [
        new \RuntimeException('The legacy ai_engine request failed: ...', 0, new ClientException('boom', $request, new Response(400))),
        FALSE,
      ],
      'stage exception wrapping a 500' => [
        new BeaconStageException('retrieval', 'boom', new OpenAiServerException(new Response(500))),
        TRUE,
      ],
      'stage exception wrapping a 400' => [
        new BeaconStageException('chat', 'boom', new OpenAiErrorException(['message' => 'filtered'], new Response(400))),
        FALSE,
      ],
    ];
  }

  /**
   * @covers ::statusCode
   */
  public function testStatusCodeReadsEveryClientShape(): void {
    $request = new Request('POST', 'https://example.test/x');

    // openai-php exposes the response as a public property on ServerException
    // and both a property and a getter on ErrorException; Guzzle exposes it via
    // getResponse(). All three have to be readable or the classification above
    // silently degrades to "not transient".
    $this->assertSame(500, AiTesterFailure::statusCode(new OpenAiServerException(new Response(500))));
    $this->assertSame(429, AiTesterFailure::statusCode(new OpenAiErrorException(['message' => 'x'], new Response(429))));
    $this->assertSame(404, AiTesterFailure::statusCode(new ClientException('x', $request, new Response(404))));
    $this->assertNull(AiTesterFailure::statusCode(new \RuntimeException('no http here')));
    $this->assertNull(AiTesterFailure::statusCode(new ConnectException('x', $request)));
    // Reported by the exception itself rather than read off a response.
    $this->assertSame(429, AiTesterFailure::statusCode(self::streamedFailure('throttled', 429)));
    $this->assertNull(AiTesterFailure::statusCode(self::streamedFailure('no code')));
  }

  /**
   * @covers ::retryAfterMs
   * @dataProvider provideRetryAfterCases
   */
  public function testRetryAfterMs(?string $header, ?int $expected): void {
    $headers = $header === NULL ? [] : ['Retry-After' => $header];
    $e = new OpenAiErrorException(['message' => 'slow down'], new Response(429, $headers));

    $this->assertSame($expected, AiTesterFailure::retryAfterMs($e));
  }

  /**
   * Retry-After header value, and the milliseconds it should mean.
   *
   * Only the delta-seconds form is honored. The HTTP-date form is legal but
   * rare from these APIs, and misreading one as seconds would produce an
   * absurd wait, so an unparseable value defers to the normal backoff instead.
   */
  public static function provideRetryAfterCases(): array {
    return [
      'absent' => [NULL, NULL],
      'seconds' => ['3', 3000],
      'zero' => ['0', 0],
      'empty' => ['', NULL],
      'http date' => ['Wed, 21 Oct 2015 07:28:00 GMT', NULL],
      'not a number' => ['soon', NULL],
      'negative' => ['-5', NULL],
    ];
  }

  /**
   * A success status neither settles the verdict nor hides the real cause.
   *
   * The status code reported is whatever the response carried, and a 200 can
   * arrive on an exception - openai-php raises on an unparseable body, and
   * warns that a streamed request may report 200 even in the error case. A 2xx
   * must therefore not be read as evidence of a permanent failure, and must
   * not stop the walk before the wrapped cause is examined.
   *
   * @covers ::isTransient
   */
  public function testSuccessStatusDoesNotMaskTheWrappedCause(): void {
    $carrying200 = new OpenAiServerException(new Response(200));

    // On its own: no evidence either way, so not retried.
    $this->assertFalse(AiTesterFailure::isTransient($carrying200));

    // Wrapping a real server fault: the 200 must not shadow the 503 beneath it.
    $this->assertTrue(AiTesterFailure::isTransient(
      new \RuntimeException('wrapper', 0, new OpenAiServerException(new Response(503)))
    ));
    $this->assertTrue(AiTesterFailure::isTransient(
      new BeaconStageException('chat', 'wrapper', new OpenAiServerException(new Response(502)))
    ));
  }

  /**
   * @covers ::stageOf
   */
  public function testStageOfNamesTheFailingUpstreamCall(): void {
    $this->assertSame(
      'retrieval',
      AiTesterFailure::stageOf(new BeaconStageException('retrieval', 'boom', new \RuntimeException('inner')))
    );
    // Wrapped one level deeper, as it would be if the backend rethrew.
    $this->assertSame(
      'chat',
      AiTesterFailure::stageOf(new \RuntimeException('outer', 0, new BeaconStageException('chat', 'boom', NULL)))
    );
    $this->assertNull(AiTesterFailure::stageOf(new \RuntimeException('no stage')));
  }

  /**
   * @covers ::describe
   */
  public function testDescribeCarriesTheDetailTheOldLogDiscarded(): void {
    $body = '{"error":{"code":"upstream_failure","message":"the gateway could not reach the model"}}';
    $response = new Response(500, [
      'x-portkey-trace-id' => 'trace-abc-123',
      'x-portkey-request-id' => 'req-def-456',
    ], $body);

    $detail = AiTesterFailure::describe(new OpenAiServerException($response));

    // The status code is all the old log ever carried.
    $this->assertStringContainsString('500', $detail);
    $this->assertStringContainsString('ServerException', $detail);
    // The body is the part that was thrown away entirely.
    $this->assertStringContainsString('the gateway could not reach the model', $detail);
    // Without a trace id there is no way to find the request in Portkey's logs,
    // which is why "no errors in Portkey" was unfalsifiable.
    $this->assertStringContainsString('trace-abc-123', $detail);
    $this->assertStringContainsString('req-def-456', $detail);
  }

  /**
   * @covers ::describe
   */
  public function testDescribeBoundsAnEnormousBody(): void {
    $response = new Response(500, [], str_repeat('x', 50000));

    $detail = AiTesterFailure::describe(new OpenAiServerException($response));

    // Bounded, but far above Guzzle's 120-character summary, which cut the
    // legacy error off mid-word at "'Ser" and lost the cause.
    $this->assertLessThan(4000, strlen($detail));
    $this->assertGreaterThan(2000, strlen($detail));
    $this->assertStringContainsString('truncated', $detail);
  }

  /**
   * A multi-byte body is still marked as truncated, and stays valid UTF-8.
   *
   * This is the case a pure-ASCII fixture cannot reach. The read is bounded in
   * bytes, so a body over the byte limit but under the character limit is
   * exactly where comparing the two units would let a silently cut-off body
   * through with no marker - and where a cut could land mid-character, which
   * matters because Drupal escapes log placeholders through htmlspecialchars()
   * and that returns an empty string for invalid UTF-8, blanking the message.
   *
   * @covers ::describe
   */
  public function testDescribeBoundsMultibyteBodyWithoutLosingTheMarker(): void {
    // Three bytes per character: 900 characters is 2700 bytes - over the byte
    // limit, comfortably under the same number of characters.
    $response = new Response(500, [], str_repeat("\u{4e2d}", 900));

    $detail = AiTesterFailure::describe(new OpenAiServerException($response));

    $this->assertStringContainsString('truncated', $detail);
    $this->assertTrue(mb_check_encoding($detail, 'UTF-8'), 'The log line must stay valid UTF-8.');
  }

  /**
   * @covers ::describe
   */
  public function testDescribeReadsThroughWrappedExceptions(): void {
    $inner = new ClientException(
      'Client error',
      new Request('POST', 'https://askyaleyalesites.azurewebsites.net/conversation'),
      new Response(400, [], '{"error":"Error code: 400 - the part Guzzle used to cut off"}')
    );
    $outer = new \RuntimeException('The legacy ai_engine request failed: truncated junk', 0, $inner);

    $detail = AiTesterFailure::describe($outer);

    $this->assertStringContainsString('400', $detail);
    $this->assertStringContainsString('the part Guzzle used to cut off', $detail);
  }

  /**
   * An inaccessible getResponse() must not turn logging into a second failure.
   *
   * A method_exists() check answers TRUE for a protected or private method, and
   * calling one raises an Error - from inside the catch block that is trying to
   * report the original failure, so the whole batch operation would die
   * reporting an error rather than recording it.
   *
   * @covers ::statusCode
   * @covers ::describe
   */
  public function testNonPublicGetResponseIsIgnoredRatherThanCalled(): void {
    $hostile = new class('boom') extends \RuntimeException {

      /**
       * Deliberately not public, as some libraries declare it.
       *
       * Throws rather than returning, so a regression that calls it fails
       * loudly instead of quietly handing back a plausible value.
       */
      protected function getResponse() {
        throw new \LogicException('This must never be called.');
      }

    };

    $this->assertNull(AiTesterFailure::statusCode($hostile));
    $this->assertStringContainsString('boom', AiTesterFailure::describe($hostile));
  }

  /**
   * @covers ::describe
   */
  public function testDescribeSurvivesAnExceptionWithNoResponse(): void {
    // A logging path must never be the thing that throws.
    $detail = AiTesterFailure::describe(new \RuntimeException('The "beacon" assistant is not available on this site.'));

    $this->assertStringContainsString('not available on this site', $detail);
    $this->assertStringContainsString('RuntimeException', $detail);
  }

  /**
   * @covers ::describe
   */
  public function testDescribeNamesTheStageAndTheCredentialToCheck(): void {
    // Chat is the reachable case, and naming its key is the diagnostic win:
    // chat and embeddings authenticate with different Portkey keys and can sit
    // in different workspaces, so "no errors in Portkey" is unfalsifiable
    // without knowing which one to look in.
    $chat = AiTesterFailure::describe(
      new BeaconStageException('chat', 'boom', new OpenAiServerException(new Response(500)))
    );
    $this->assertStringContainsString('chat', $chat);
    $this->assertStringContainsString('api_key', $chat);

    // Retrieval must NOT claim an embeddings failure. RagRetriever swallows a
    // failed query and degrades to an empty citation list, so an embeddings or
    // Azure AI Search outage never surfaces here - pointing at the embeddings
    // credential would send someone to the wrong log.
    $retrieval = AiTesterFailure::describe(
      new BeaconStageException('retrieval', 'boom', new OpenAiServerException(new Response(500)))
    );
    $this->assertStringContainsString('retrieval', $retrieval);
    $this->assertStringNotContainsString('embedding', strtolower($retrieval));
  }

}
