<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester_legacy\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_ai_tester_legacy\LegacyAnswerBackend;
use Drupal\ys_ai_tester_legacy\LegacyConversationClient;
use Drupal\ys_beacon\Service\CitationFormatter;

/**
 * Tests the legacy assistant exposed as an AI Tester answer backend.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester_legacy\LegacyAnswerBackend
 * @group ys_beacon
 */
class LegacyAnswerBackendTest extends UnitTestCase {

  /**
   * Builds one legacy citation in the Azure "on your data" shape.
   *
   * @param string $title
   *   The source title.
   * @param string $url
   *   The source URL.
   *
   * @return array
   *   The citation, with every key the legacy widget contract defines.
   */
  private static function citation(string $title, string $url): array {
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
   * Builds the backend over a stubbed client.
   *
   * @param bool $configured
   *   Whether the client reports the endpoint as configured.
   * @param array $answer
   *   The answer the client returns, or empty to stub nothing.
   *
   * @return \Drupal\ys_ai_tester_legacy\LegacyAnswerBackend
   *   The backend.
   */
  private function backend(bool $configured, array $answer = []): LegacyAnswerBackend {
    $client = $this->createMock(LegacyConversationClient::class);
    $client->method('isConfigured')->willReturn($configured);
    if ($answer !== []) {
      $client->method('ask')->willReturn($answer);
    }

    $backend = new LegacyAnswerBackend($client);
    $backend->setStringTranslation($this->getStringTranslationStub());
    return $backend;
  }

  /**
   * @covers ::id
   */
  public function testIdIsStableAndNotTheDefaultBackend(): void {
    $backend = $this->backend(TRUE);

    $this->assertSame('legacy', $backend->id());
    $this->assertNotSame(AnswerBackendInterface::DEFAULT_ID, $backend->id());
  }

  /**
   * @covers ::isAvailable
   */
  public function testAvailabilityFollowsTheClientConfiguration(): void {
    $this->assertTrue($this->backend(TRUE)->isAvailable());
    $this->assertFalse($this->backend(FALSE)->isAvailable());
  }

  /**
   * The backend passes the assistant's answer and raw sources straight through.
   *
   * @covers ::answer
   */
  public function testAnswerReturnsTheClientResultUnchanged(): void {
    $result = [
      'answer' => 'Office hours are in the handbook. [doc1]',
      'citations' => [self::citation('Handbook', 'https://example.com/handbook')],
    ];

    $this->assertSame($result, $this->backend(TRUE, $result)->answer('Hours?'));
  }

  /**
   * @covers ::answer
   */
  public function testAnswerWithNoCitationsStillReturnsTheAnswer(): void {
    $result = ['answer' => 'Plain answer.', 'citations' => []];

    $this->assertSame($result, $this->backend(TRUE, $result)->answer('Anything?'));
  }

  /**
   * Tests that legacy citations normalize with the tester's shared formatter.
   *
   * This is the load-bearing assumption behind the legacy backend: because a
   * legacy citation already carries the same keys as a Beacon one, and legacy
   * answers cite sources with the same [docN] markers, the batch's existing
   * CitationFormatter derives a correct cited flag from them with no
   * legacy-specific normalization. RunComparator counts that flag, so if this
   * were wrong every legacy row would report zero cited sources — reading as
   * "legacy never cites anything". The real formatter is used, not a mock,
   * because a mock would prove nothing here.
   */
  public function testLegacyCitationsNormalizeWithTheSharedFormatter(): void {
    $answer = 'Office hours are in the handbook. [doc1]';
    $citations = [
      self::citation('Handbook', 'https://example.com/handbook'),
      self::citation('Other page', 'https://example.com/other'),
    ];

    $formatted = (new CitationFormatter())->format($answer, $citations);

    $this->assertCount(2, $formatted);
    $this->assertTrue((bool) $formatted[0]['cited'], 'The [doc1] source is flagged cited.');
    $this->assertFalse((bool) $formatted[1]['cited'], 'The unreferenced source is retrieved, not cited.');
    $this->assertSame('Handbook', $formatted[0]['title']);
    $this->assertSame('https://example.com/handbook', $formatted[0]['url']);
  }

}
