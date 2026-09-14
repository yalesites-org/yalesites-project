<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester_legacy\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterFailure;
use Drupal\ys_ai_tester_legacy\LegacyStreamException;
use Drupal\ys_ai_tester_legacy\LegacyStreamParser;

/**
 * Tests that an error inside a 200 stream still reports its status code.
 *
 * The legacy endpoint streams its reply, so it can report a failure in the body
 * while the HTTP status stays 200. Those failures carry no response to read a
 * status off, so without the code travelling on the exception the tester would
 * treat a plainly retryable 429 as permanent and never retry it.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester_legacy\LegacyStreamParser
 *
 * @group ys_beacon
 */
class LegacyStreamExceptionTest extends UnitTestCase {

  /**
   * Builds a one-envelope stream body carrying an error payload.
   *
   * @param array $error
   *   The envelope's error value.
   *
   * @return string
   *   The stream body.
   */
  protected function errorStream(array $error): string {
    return json_encode(['error' => $error]) . "\n";
  }

  /**
   * @covers ::parse
   * @covers ::errorStatusCode
   * @dataProvider provideErrorPayloads
   */
  public function testParseCarriesTheReportedStatusCode(array $error, ?int $expected_code, bool $transient): void {
    try {
      LegacyStreamParser::parse($this->errorStream($error));
      $this->fail('Expected the stream error to throw.');
    }
    catch (LegacyStreamException $e) {
      $this->assertSame($expected_code, $e->getUpstreamStatusCode());
      // The whole point of carrying the code: the tester's classification.
      $this->assertSame($transient, AiTesterFailure::isTransient($e));
    }
  }

  /**
   * Error payload, the status code it should yield, and whether to retry it.
   */
  public static function provideErrorPayloads(): array {
    return [
      'throttled' => [['code' => 429, 'message' => 'Rate limit exceeded'], 429, TRUE],
      // Azure reports the code as a string in this shape.
      'throttled as a string' => [['code' => '429', 'message' => 'Rate limit exceeded'], 429, TRUE],
      'upstream broke' => [['code' => '500', 'message' => 'Server error'], 500, TRUE],
      'bad request' => [['code' => '400', 'message' => 'Bad request'], 400, FALSE],
      // A symbolic code is not a status and must not be coerced into one, or
      // "content_filter" would cast to 0 and be compared against 500.
      'symbolic code' => [['code' => 'content_filter', 'message' => 'Filtered'], NULL, FALSE],
      'no code at all' => [['message' => 'Something went wrong'], NULL, FALSE],
    ];
  }

  /**
   * The message recorded against the question is unchanged by this.
   *
   * @covers ::parse
   */
  public function testTheRecordedMessageIsStillTheUpstreamMessage(): void {
    $this->expectException(LegacyStreamException::class);
    $this->expectExceptionMessage('Rate limit exceeded');

    LegacyStreamParser::parse($this->errorStream([
      'code' => '429',
      'message' => 'Rate limit exceeded',
    ]));
  }

  /**
   * The new exception is still a \RuntimeException to every existing caller.
   *
   * @covers ::parse
   */
  public function testItRemainsRuntimeExceptionForExistingCallers(): void {
    $this->expectException(\RuntimeException::class);

    LegacyStreamParser::parse($this->errorStream(['message' => 'boom']));
  }

}
