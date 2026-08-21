<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester_legacy\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester_legacy\LegacyStreamParser;

/**
 * Tests parsing the legacy Azure app's newline-delimited JSON answer stream.
 *
 * The fixtures mirror the contract the shipped React widget already relies on:
 * an envelope per line with choices[0].messages[], assistant messages carrying
 * answer deltas and a single role "tool" message whose content is a JSON string
 * holding the citation list (see ai_engine_chat/react/src/api/models.ts).
 *
 * @coversDefaultClass \Drupal\ys_ai_tester_legacy\LegacyStreamParser
 * @group ys_beacon
 */
class LegacyStreamParserTest extends UnitTestCase {

  /**
   * Builds one NDJSON envelope line.
   *
   * @param array $messages
   *   The messages the envelope carries.
   *
   * @return string
   *   The encoded envelope, without a trailing newline.
   */
  private static function envelope(array $messages): string {
    return json_encode([
      'id' => 'resp-1',
      'model' => 'gpt-4',
      'created' => 1700000000,
      'object' => 'chat.completion.chunk',
      'choices' => [['messages' => $messages]],
    ]);
  }

  /**
   * Builds an assistant delta message.
   */
  private static function assistant(string $content): array {
    return ['role' => 'assistant', 'content' => $content];
  }

  /**
   * Builds a tool message whose content is the encoded citation payload.
   */
  private static function tool(array $citations, string $intent = 'office hours'): array {
    return [
      'role' => 'tool',
      'content' => json_encode(['citations' => $citations, 'intent' => $intent]),
    ];
  }

  /**
   * Builds one legacy citation in the Azure "on your data" shape.
   */
  private static function citation(string $title, ?string $url): array {
    return [
      'content' => 'Body text for ' . $title,
      'id' => NULL,
      'title' => $title,
      'filepath' => NULL,
      'url' => $url,
      'metadata' => NULL,
      'chunk_id' => '0',
      'reindex_id' => NULL,
    ];
  }

  /**
   * @covers ::parse
   */
  public function testConcatenatesAssistantDeltasInOrder(): void {
    $body = implode("\n", [
      self::envelope([self::assistant('Office hours are ')]),
      self::envelope([self::assistant('in the handbook. ')]),
      self::envelope([self::assistant('[doc1]')]),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Office hours are in the handbook. [doc1]', $result['answer']);
  }

  /**
   * Citations must survive with every key the tester's formatter consumes.
   *
   * @covers ::parse
   */
  public function testExtractsCitationsFromTheToolMessage(): void {
    $citation = self::citation('Handbook', 'https://example.com/handbook');
    $body = implode("\n", [
      self::envelope([self::tool([$citation])]),
      self::envelope([self::assistant('See the handbook. [doc1]')]),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertCount(1, $result['citations']);
    $this->assertSame($citation, $result['citations'][0]);
    $this->assertSame(
      ['content', 'id', 'title', 'filepath', 'url', 'metadata', 'chunk_id', 'reindex_id'],
      array_keys($result['citations'][0]),
      'The 8-key legacy citation shape is preserved for CitationFormatter.'
    );
  }

  /**
   * Tests that an envelope spread over several lines is reassembled.
   *
   * The caller buffers the whole response, so this is not defending against a
   * mid-object network chunk boundary — it covers a producer that does not emit
   * exactly one complete object per line, and keeps the parser correct if the
   * caller is ever switched to a real stream.
   *
   * @covers ::parse
   */
  public function testReassemblesAnEnvelopeSpreadOverLines(): void {
    $envelope = self::envelope([self::assistant('Split answer.')]);
    $cut = (int) (strlen($envelope) / 2);
    $body = substr($envelope, 0, $cut) . "\n" . substr($envelope, $cut);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Split answer.', $result['answer']);
  }

  /**
   * @covers ::parse
   */
  public function testIgnoresBlankAndEmptyObjectLines(): void {
    $body = implode("\n", [
      '',
      '{}',
      self::envelope([self::assistant('Answer.')]),
      '{}',
      '',
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Answer.', $result['answer']);
    $this->assertSame([], $result['citations']);
  }

  /**
   * @covers ::parse
   */
  public function testThrowsOnAnErrorEnvelope(): void {
    $body = json_encode(['error' => 'Upstream model timed out.']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Upstream model timed out.');

    LegacyStreamParser::parse($body);
  }

  /**
   * An unparsable line mid-body must not swallow everything after it.
   *
   * Regression: accumulating past a line that can never complete poisoned the
   * buffer, so a keep-alive or an SSE "data:" prefix silently truncated the
   * answer and dropped the citations — with no error recorded.
   *
   * @covers ::parse
   */
  public function testUnparseableLineMidBodyDoesNotDiscardLaterEnvelopes(): void {
    $body = implode("\n", [
      self::envelope([self::assistant('Office hours ')]),
      'data: keep-alive',
      self::envelope([self::assistant('are 9-5. [doc1]')]),
      self::envelope([self::tool([self::citation('Handbook', 'https://example.com/h')])]),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Office hours are 9-5. [doc1]', $result['answer']);
    $this->assertCount(1, $result['citations'], 'The citation after the bad line survives.');
  }

  /**
   * A 200 whose body is not the expected stream errors, not answers blank.
   *
   * An Azure sign-in page or proxy interstitial returned with a 200 must be
   * recorded as a failure; storing it as an empty answer would recreate exactly
   * the ambiguity the per-question error column exists to remove.
   *
   * @covers ::parse
   */
  public function testUnreadableBodyIsAnError(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('unreadable response');

    LegacyStreamParser::parse('<!DOCTYPE html><html><body>Sign in</body></html>');
  }

  /**
   * A structured error payload keeps its message instead of becoming "Array".
   *
   * @covers ::parse
   */
  public function testStructuredErrorPayloadKeepsItsMessage(): void {
    $body = json_encode([
      'error' => ['code' => '429', 'message' => 'Rate limit exceeded'],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Rate limit exceeded');

    LegacyStreamParser::parse($body);
  }

  /**
   * @covers ::parse
   */
  public function testMalformedToolContentYieldsNoCitations(): void {
    $body = implode("\n", [
      self::envelope([['role' => 'tool', 'content' => 'not json at all']]),
      self::envelope([self::assistant('Answer.')]),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Answer.', $result['answer']);
    $this->assertSame([], $result['citations']);
  }

  /**
   * @covers ::parse
   */
  public function testToolMessageWithoutCitationsKeyYieldsNoCitations(): void {
    $body = self::envelope([
      ['role' => 'tool', 'content' => json_encode(['intent' => 'x'])],
    ]);

    $this->assertSame([], LegacyStreamParser::parse($body)['citations']);
  }

  /**
   * @covers ::parse
   */
  public function testAnswerWithNoToolMessageHasNoCitations(): void {
    $body = self::envelope([self::assistant('No sources for this one.')]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('No sources for this one.', $result['answer']);
    $this->assertSame([], $result['citations']);
  }

  /**
   * The reference widget keeps the last tool message it sees; so does this.
   *
   * @covers ::parse
   */
  public function testTheLastToolMessageWins(): void {
    $body = implode("\n", [
      self::envelope([self::tool([self::citation('First', 'https://example.com/1')])]),
      self::envelope([self::tool([self::citation('Second', 'https://example.com/2')])]),
      self::envelope([self::assistant('Answer.')]),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertCount(1, $result['citations']);
    $this->assertSame('Second', $result['citations'][0]['title']);
  }

  /**
   * @covers ::parse
   */
  public function testEmptyBodyYieldsAnEmptyAnswer(): void {
    $this->assertSame(
      ['answer' => '', 'citations' => []],
      LegacyStreamParser::parse('')
    );
  }

  /**
   * Trailing text that never parses is dropped rather than raising.
   *
   * @covers ::parse
   */
  public function testUnparseableTrailingTextIsIgnored(): void {
    $body = self::envelope([self::assistant('Answer.')]) . "\n" . '{"partial":';

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Answer.', $result['answer']);
  }

  /**
   * A tool message and assistant deltas in one envelope are both read.
   *
   * @covers ::parse
   */
  public function testHandlesToolAndAssistantInTheSameEnvelope(): void {
    $body = self::envelope([
      self::tool([self::citation('Handbook', 'https://example.com/handbook')]),
      self::assistant('Answer. [doc1]'),
    ]);

    $result = LegacyStreamParser::parse($body);

    $this->assertSame('Answer. [doc1]', $result['answer']);
    $this->assertCount(1, $result['citations']);
  }

}
