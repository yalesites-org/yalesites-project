<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\Service\HostnameFilter;
use Drupal\ys_beacon\Controller\ChatApiController;
use Symfony\Component\HttpFoundation\Request;

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

  /**
   * @covers ::withOutputFilteringDisabled
   */
  public function testWithOutputFilteringDisabledEnablesFullTrustAroundCallback(): void {
    $filter = $this->createMock(HostnameFilter::class);
    $snapshot = ['sentinel' => TRUE];
    $filter->method('snapshotSettings')->willReturn($snapshot);

    // Beacon disables the AI module's output URL filter for its own responses:
    // the allow-list ships empty (block-all), so otherwise every source link
    // the model returns is stripped and renders as a broken "[text](" fragment.
    $calls = [];
    $filter->expects($this->once())->method('setFullTrust')->with(TRUE)
      ->willReturnCallback(function () use (&$calls) {
        $calls[] = 'fullTrust';
      });
    $filter->expects($this->once())->method('restoreSettings')->with($snapshot)
      ->willReturnCallback(function () use (&$calls) {
        $calls[] = 'restore';
      });
    $this->setControllerProperty('hostnameFilter', $filter);

    $ran = FALSE;
    $this->invoke('withOutputFilteringDisabled', [
      function () use (&$ran, &$calls) {
        $ran = TRUE;
        $calls[] = 'consume';
      },
    ]);

    $this->assertTrue($ran, 'The callback runs.');
    // Full trust is enabled before, and the filter restored after, the callback
    // that consumes the (possibly streamed) model output.
    $this->assertSame(['fullTrust', 'consume', 'restore'], $calls);
  }

  /**
   * @covers ::withOutputFilteringDisabled
   */
  public function testWithOutputFilteringDisabledRestoresFilterOnException(): void {
    $filter = $this->createMock(HostnameFilter::class);
    $filter->method('snapshotSettings')->willReturn([]);
    // The filter must be restored even when the callback throws, so a failed
    // Beacon turn never leaks full-trust to other AI features on the site.
    $filter->expects($this->once())->method('restoreSettings');
    $this->setControllerProperty('hostnameFilter', $filter);

    $this->expectException(\RuntimeException::class);
    $this->invoke('withOutputFilteringDisabled', [
      function () {
        throw new \RuntimeException('stream blew up');
      },
    ]);
  }

  /**
   * Chat disabled in config yields a 403 before any work.
   *
   * @covers ::conversation
   */
  public function testConversationForbiddenWhenChatDisabled(): void {
    $this->configureGuards(FALSE);
    $response = $this->controller->conversation($this->request('{"messages":[{"role":"user","content":"hi"}]}'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Exceeding the per-IP flood limit yields a 429.
   *
   * @covers ::conversation
   */
  public function testConversationRateLimited(): void {
    $this->configureGuards(TRUE, FALSE);
    $response = $this->controller->conversation($this->request('{"messages":[{"role":"user","content":"hi"}]}'));
    $this->assertSame(429, $response->getStatusCode());
  }

  /**
   * A body over MAX_PAYLOAD_BYTES is rejected with 413 before JSON decoding.
   *
   * @covers ::conversation
   */
  public function testConversationRejectsOversizePayload(): void {
    $this->configureGuards(TRUE);
    $response = $this->controller->conversation($this->request(str_repeat('x', 70000)));
    $this->assertSame(413, $response->getStatusCode());
  }

  /**
   * A transcript with no user message yields a 400.
   *
   * @covers ::conversation
   */
  public function testConversationRejectsMissingUserMessage(): void {
    $this->configureGuards(TRUE);
    $response = $this->controller->conversation($this->request('{"messages":[]}'));
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Wires the collaborators the conversation() guards consult.
   *
   * Only the pre-provider guards (403/429/413/400) are exercised here; the
   * no-provider 503 path needs the real (final) AiProviderPluginManager and is
   * left to a functional test.
   *
   * @param bool $enableChat
   *   The ys_beacon.settings:enable_chat value.
   * @param bool $floodAllowed
   *   Whether the flood service allows the request.
   */
  protected function configureGuards(bool $enableChat, bool $floodAllowed = TRUE): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(fn ($name) => $name === 'enable_chat' ? $enableChat : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('ys_beacon.settings')->willReturn($settings);
    $this->setControllerProperty('configFactory', $configFactory);

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn($floodAllowed);
    $this->setControllerProperty('flood', $flood);
  }

  /**
   * Builds a POST request carrying the given raw body.
   */
  protected function request(string $content): Request {
    return Request::create('/api/ys-beacon/v1/conversation', 'POST', [], [], [], [], $content);
  }

  /**
   * Sets a protected property (including inherited ones) on the controller.
   */
  protected function setControllerProperty(string $name, mixed $value): void {
    $property = (new \ReflectionClass($this->controller))->getProperty($name);
    $property->setAccessible(TRUE);
    $property->setValue($this->controller, $value);
  }

}
