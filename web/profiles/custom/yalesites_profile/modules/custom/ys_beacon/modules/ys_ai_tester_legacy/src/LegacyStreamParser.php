<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester_legacy;

/**
 * Parses the legacy Azure app's newline-delimited JSON answer stream.
 *
 * The wire format is the one the shipped React widget already consumes: one
 * JSON envelope per line, each carrying choices[0].messages[]. Assistant
 * messages hold answer deltas that concatenate in arrival order; a single
 * role "tool" message holds the citation list as a JSON *string*.
 *
 * Static because it is a pure function over a wire format with no
 * collaborators, which also makes it directly testable without a container.
 */
final class LegacyStreamParser {

  /**
   * Parses a response body into an answer and its citations.
   *
   * @param string $body
   *   The newline-delimited JSON response body.
   *
   * @return array
   *   An array with 'answer' (string) and 'citations' (the legacy citation
   *   list, untouched — its keys already match what the tester's citation
   *   formatter consumes).
   *
   * @throws \RuntimeException
   *   When the stream carries an error instead of an answer.
   */
  public static function parse(string $body): array {
    $answer = '';
    $citations = [];
    // Text is accumulated until it parses rather than assuming one complete
    // object per line. The caller buffers the whole body, so a mid-object TCP
    // chunk boundary cannot reach here — what this tolerates is a producer that
    // spreads an envelope over several lines (e.g. pretty-printed JSON), and it
    // keeps the parser correct if the caller is ever switched to a real stream.
    $pending = '';
    $parsed = 0;

    foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
      if ($line === '' || $line === '{}') {
        continue;
      }

      $candidate = $pending . $line;
      $envelope = json_decode($candidate, TRUE);

      // A line that opens a new object means the text accumulated so far can
      // never complete, so it is abandoned rather than prefixed onto this one.
      // Without this, a single unparsable line mid-body (a keep-alive, an SSE
      // "data:" prefix) would poison every envelope after it and silently
      // truncate the answer.
      if (!is_array($envelope) && $pending !== '' && str_starts_with(ltrim($line), '{')) {
        $candidate = $line;
        $envelope = json_decode($candidate, TRUE);
      }

      if (!is_array($envelope)) {
        // Incomplete — keep accumulating. Trailing text that never completes is
        // simply dropped when the loop ends.
        $pending = $candidate;
        continue;
      }
      $pending = '';
      $parsed++;

      if (isset($envelope['error'])) {
        throw new \RuntimeException(self::errorMessage($envelope['error']));
      }

      foreach ($envelope['choices'][0]['messages'] ?? [] as $message) {
        $role = $message['role'] ?? '';
        if ($role === 'assistant') {
          $answer .= (string) ($message['content'] ?? '');
        }
        elseif ($role === 'tool') {
          // Matches the widget: the last tool message seen wins, and unparsable
          // tool content yields no citations rather than failing the answer.
          $citations = self::decodeCitations((string) ($message['content'] ?? ''));
        }
      }
    }

    // A body that carried content but yielded no envelope is a failure, not an
    // empty answer — an Azure sign-in page or a proxy interstitial returned
    // with a 200 would otherwise be stored as "the assistant answered
    // nothing", exactly the ambiguity the run's error column exists to remove.
    if ($parsed === 0 && trim($body) !== '') {
      throw new \RuntimeException('The legacy assistant returned an unreadable response.');
    }

    return ['answer' => $answer, 'citations' => $citations];
  }

  /**
   * Renders an envelope's error payload as a readable message.
   *
   * The upstream may report either a bare string or a structured object (the
   * Azure OpenAI shape, `{code, message}`); casting the latter to string would
   * record the literal "Array" and lose the diagnosis.
   *
   * @param mixed $error
   *   The envelope's error value.
   *
   * @return string
   *   The message to record against the question.
   */
  private static function errorMessage(mixed $error): string {
    if (is_scalar($error)) {
      return (string) $error;
    }
    if (is_array($error) && isset($error['message']) && is_scalar($error['message'])) {
      return (string) $error['message'];
    }
    return (string) json_encode($error);
  }

  /**
   * Decodes a tool message's JSON-string content into its citation list.
   *
   * @param string $content
   *   The tool message content.
   *
   * @return array
   *   The citation list, or an empty array when it cannot be read.
   */
  private static function decodeCitations(string $content): array {
    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded) || !is_array($decoded['citations'] ?? NULL)) {
      return [];
    }
    return $decoded['citations'];
  }

}
