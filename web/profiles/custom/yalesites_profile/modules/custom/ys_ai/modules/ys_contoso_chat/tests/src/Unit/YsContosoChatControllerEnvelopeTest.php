<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ys_contoso_chat\Controller\YsContosoChatController;
use Drupal\ys_contoso_chat\Service\CitationStore;

/**
 * @coversDefaultClass \Drupal\ys_contoso_chat\Controller\YsContosoChatController
 * @group yalesites
 */
class YsContosoChatControllerEnvelopeTest extends UnitTestCase {

  /**
   * Invokes the protected buildEnvelope() method.
   *
   * @param string $content
   *   The assistant text.
   * @param array[] $citations
   *   The citations to embed.
   *
   * @return array
   *   The built envelope.
   */
  protected function buildEnvelope(string $content, array $citations): array {
    $controller = new YsContosoChatController(
      $this->createMock(AiAssistantApiRunner::class),
      $this->createMock(FloodInterface::class),
      new CitationStore(),
    );
    $method = (new \ReflectionClass($controller))->getMethod('buildEnvelope');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, 'resp-1', $content, '2026-06-04T00:00:00+00:00', $citations);
  }

  /**
   * @covers ::buildEnvelope
   */
  public function testEnvelopeOmitsToolMessageWithoutCitations(): void {
    $messages = $this->buildEnvelope('Hello there.', [])['choices'][0]['messages'];
    $this->assertCount(1, $messages);
    $this->assertSame('assistant', $messages[0]['role']);
    $this->assertSame('Hello there.', $messages[0]['content']);
  }

  /**
   * @covers ::buildEnvelope
   */
  public function testEnvelopePrependsToolMessageWithCitations(): void {
    $citations = [
      ['id' => '1', 'content' => 'a', 'url' => 'https://example.com/a', 'title' => 'A'],
    ];
    $messages = $this->buildEnvelope('See [doc1].', $citations)['choices'][0]['messages'];

    $this->assertCount(2, $messages);
    // Tool message must come first so the frontend reads it before the answer.
    $this->assertSame('tool', $messages[0]['role']);
    $this->assertSame('assistant', $messages[1]['role']);

    $payload = json_decode($messages[0]['content'], TRUE);
    $this->assertSame('', $payload['intent']);
    $this->assertSame($citations, $payload['citations']);
  }

}
