<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Controller\ChatApiController;

/**
 * Tests the conversation endpoint transcript and envelope logic.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Controller\ChatApiController
 */
class ChatApiControllerTest extends UnitTestCase {

  /**
   * The controller under test.
   *
   * @var \Drupal\ys_beacon\Controller\ChatApiController
   */
  protected ChatApiController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = new ChatApiController();
  }

  /**
   * Invokes a protected method on the controller.
   */
  protected function invoke(string $method, array $args) {
    $reflection = new \ReflectionMethod(ChatApiController::class, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($this->controller, $args);
  }

  /**
   * @covers ::extractTranscript
   */
  public function testExtractTranscriptFiltersRolesAndGarbage(): void {
    $messages = [
      ['role' => 'user', 'content' => 'First question'],
      ['role' => 'tool', 'content' => '{"citations":[]}'],
      ['role' => 'assistant', 'content' => 'An answer'],
      ['role' => 'error', 'content' => 'Some error bubble'],
      ['role' => 'user', 'content' => '   '],
      ['role' => 'user', 'content' => ['not' => 'a string']],
      'not even an array',
      ['role' => 'user', 'content' => 'Second question'],
    ];
    $transcript = $this->invoke('extractTranscript', [$messages]);
    $this->assertSame([
      ['role' => 'user', 'content' => 'First question'],
      ['role' => 'assistant', 'content' => 'An answer'],
      ['role' => 'user', 'content' => 'Second question'],
    ], $transcript);
  }

  /**
   * @covers ::extractTranscript
   */
  public function testExtractTranscriptHandlesNonArrayAndCapsLength(): void {
    $this->assertSame([], $this->invoke('extractTranscript', [NULL]));
    $this->assertSame([], $this->invoke('extractTranscript', ['nope']));

    $messages = [];
    for ($i = 0; $i < 30; $i++) {
      $messages[] = ['role' => 'user', 'content' => "Message $i"];
    }
    $transcript = $this->invoke('extractTranscript', [$messages]);
    $this->assertCount(20, $transcript);
    $this->assertSame('Message 29', end($transcript)['content']);
  }

  /**
   * @covers ::lastUserMessage
   */
  public function testLastUserMessage(): void {
    $transcript = [
      ['role' => 'user', 'content' => 'First'],
      ['role' => 'assistant', 'content' => 'Reply'],
      ['role' => 'user', 'content' => 'Latest'],
      ['role' => 'assistant', 'content' => 'Another reply'],
    ];
    $this->assertSame('Latest', $this->invoke('lastUserMessage', [$transcript]));
    $this->assertNull($this->invoke('lastUserMessage', [[['role' => 'assistant', 'content' => 'Only replies']]]));
    $this->assertNull($this->invoke('lastUserMessage', [[]]));
  }

  /**
   * @covers ::envelope
   */
  public function testEnvelopeMatchesFrontendContract(): void {
    $line = $this->invoke('envelope', [
      'abc-123',
      'gpt-4o',
      [['role' => 'assistant', 'content' => 'Hello']],
    ]);

    $this->assertStringEndsWith("\n", $line);
    $decoded = json_decode($line, TRUE);
    $this->assertSame('abc-123', $decoded['id']);
    $this->assertSame('gpt-4o', $decoded['model']);
    $this->assertSame('chat.completion.chunk', $decoded['object']);
    $this->assertIsInt($decoded['created']);
    $this->assertSame(
      [['role' => 'assistant', 'content' => 'Hello']],
      $decoded['choices'][0]['messages']
    );
    $this->assertSame([], $decoded['history_metadata']);
    // The envelope must be a single line of JSON: the frontend splits the
    // stream on newlines and parses each line independently.
    $this->assertSame(1, substr_count($line, "\n"));
  }

}
