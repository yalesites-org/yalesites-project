<?php

namespace Drupal\Tests\ys_beacon\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ys_beacon\Service\BeaconAnswerService;
use Drupal\ys_beacon\Service\RagRetriever;
use Drupal\ys_beacon\Service\SystemPromptBuilder;
use Drupal\ys_beacon\Service\ToolCallHandler;

/**
 * Tests the non-streamed answer path used by ys_ai_tester.
 *
 * AiProviderPluginManager is declared final and cannot be mocked, so the
 * service under test exposes the same chatDefaults()/chatProvider() seams
 * ChatApiController uses, overridden here with a provider double - matching
 * the pattern ChatApiSuspectTurnWiringTest establishes for the same reason.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconAnswerService
 */
class BeaconAnswerServiceTest extends UnitTestCase {

  /**
   * Builds the service with a provider double and mocked collaborators.
   *
   * @param object $provider
   *   The provider double, exposing chat($input, $model_id, $tags): ChatOutput.
   * @param \Drupal\ys_beacon\Service\ToolCallHandler|\PHPUnit\Framework\MockObject\MockObject|null $tool_call_handler
   *   The tool call handler double, or a default mock with no tools if omitted.
   *
   * @return \Drupal\ys_beacon\Service\BeaconAnswerService
   *   The service under test.
   */
  protected function service(object $provider, ?object $tool_call_handler = NULL): BeaconAnswerService {
    $ragRetriever = $this->createMock(RagRetriever::class);
    $ragRetriever->method('retrieve')->willReturn([]);

    $promptBuilder = $this->createMock(SystemPromptBuilder::class);
    $promptBuilder->method('build')->willReturn('System prompt.');

    if ($tool_call_handler === NULL) {
      $tool_call_handler = $this->createMock(ToolCallHandler::class);
      $tool_call_handler->method('followUpInput')->willReturn(NULL);
    }

    return new class($provider, $ragRetriever, $promptBuilder, $tool_call_handler) extends BeaconAnswerService {

      /**
       * Constructs the service double, bypassing the real provider manager.
       */
      public function __construct(
        protected object $testProvider,
        RagRetriever $ragRetriever,
        SystemPromptBuilder $promptBuilder,
        ToolCallHandler $toolCallHandler,
      ) {
        $this->ragRetriever = $ragRetriever;
        $this->promptBuilder = $promptBuilder;
        $this->toolCallHandler = $toolCallHandler;
      }

      /**
       * {@inheritdoc}
       */
      protected function chatDefaults(): ?array {
        return ['provider_id' => 'test_provider', 'model_id' => 'test-model'];
      }

      /**
       * {@inheritdoc}
       */
      protected function chatProvider(string $provider_id): object {
        return $this->testProvider;
      }

    };
  }

  /**
   * An ordinary question is answered in a single call, with no follow-up.
   *
   * @covers ::answer
   */
  public function testAnswersOrdinaryQuestionWithoutToolCall(): void {
    $provider = new class() {

      /**
       * The number of times chat() was called.
       */
      public int $calls = 0;

      /**
       * The chat input the double was last called with.
       */
      public ChatInput $receivedInput;

      /**
       * Answers the turn, as ProviderProxy::chat() would.
       */
      public function chat($input, string $model_id, array $tags = []): ChatOutput {
        $this->calls++;
        $this->receivedInput = $input;
        return new ChatOutput(new ChatMessage('assistant', 'Yale College applications open in August.'), NULL, []);
      }

    };

    $result = $this->service($provider)->answer('When do applications open?');

    $this->assertSame('Yale College applications open in August.', $result['answer']);
    $this->assertSame(1, $provider->calls);
  }

  /**
   * The handler's tools are attached before the (single) call is made.
   *
   * Uses a callback double rather than an unstubbed mock, so this actually
   * fails if ::answer() stopped calling ::attachTools() - an unstubbed
   * ToolCallHandler mock's attachTools() is a silent no-op, which would let
   * this pass either way.
   *
   * @covers ::answer
   */
  public function testAttachesToolsBeforeCallingTheProvider(): void {
    $toolCallHandler = $this->createMock(ToolCallHandler::class);
    $toolCallHandler->method('followUpInput')->willReturn(NULL);
    $toolCallHandler->method('attachTools')->willReturnCallback(
      fn (ChatInput $input) => $input->setChatTools(new ToolsInput([]))
    );

    $provider = new class() {

      /**
       * The chat input the double was called with.
       */
      public ChatInput $receivedInput;

      /**
       * Answers the turn, as ProviderProxy::chat() would.
       */
      public function chat($input, string $model_id, array $tags = []): ChatOutput {
        $this->receivedInput = $input;
        return new ChatOutput(new ChatMessage('assistant', 'An answer.'), NULL, []);
      }

    };

    $this->service($provider, $toolCallHandler)->answer('Any question.');

    $this->assertNotNull($provider->receivedInput->getChatTools());
  }

  /**
   * A tool call is executed and a follow-up call produces the final answer.
   *
   * @covers ::answer
   */
  public function testExecutesToolCallAndReturnsFollowUpAnswer(): void {
    $toolCall = $this->createMock(ToolsFunctionOutputInterface::class);

    $firstMessage = new ChatMessage('assistant', '');
    $firstMessage->setTools([$toolCall]);

    $followUpToolMessage = new ChatMessage('tool', 'Monday, January 5, 2026 09:00.');

    $toolCallHandler = $this->createMock(ToolCallHandler::class);
    $toolCallHandler->method('attachTools')->willReturnCallback(
      fn (ChatInput $input) => $input->setChatTools(new ToolsInput([]))
    );
    // Returns the follow-up request built from the transcript it was handed,
    // so the assertions below also prove ::answer() passes the real transcript
    // through rather than rebuilding one.
    $toolCallHandler->expects($this->once())
      ->method('followUpInput')
      ->willReturnCallback(
        fn (mixed $normalized, array $messages) => new ChatInput(array_merge($messages, [$followUpToolMessage]))
      );

    $provider = new class($firstMessage) {

      /**
       * The number of times chat() was called.
       */
      public int $calls = 0;

      /**
       * The chat inputs the double was called with, in call order.
       */
      public array $receivedInputs = [];

      /**
       * Constructs the double with the first call's response.
       */
      public function __construct(protected ChatMessage $firstMessage) {
      }

      /**
       * Answers the turn, returning a tool call on the first call only.
       */
      public function chat($input, string $model_id, array $tags = []): ChatOutput {
        $this->calls++;
        $this->receivedInputs[] = $input;
        if ($this->calls === 1) {
          return new ChatOutput($this->firstMessage, NULL, []);
        }
        return new ChatOutput(new ChatMessage('assistant', "Today's date is January 5, 2026."), NULL, []);
      }

    };

    $result = $this->service($provider, $toolCallHandler)->answer("What's today's date?");

    $this->assertSame("Today's date is January 5, 2026.", $result['answer']);
    $this->assertSame(2, $provider->calls);
    // The follow-up call never carries tools, keeping the turn to one hop -
    // the first call does, proving attachTools() ran for it too, not just
    // that it was never called at all.
    $this->assertNotNull($provider->receivedInputs[0]->getChatTools());
    $this->assertNull($provider->receivedInputs[1]->getChatTools());
    // The follow-up request carries the tool result appended to the original
    // transcript, not a transcript of its own.
    $this->assertContains($followUpToolMessage, $provider->receivedInputs[1]->getMessages());
  }

  /**
   * No configured provider is a hard failure, unchanged from before tools.
   *
   * @covers ::answer
   */
  public function testThrowsWhenNoProviderConfigured(): void {
    $ragRetriever = $this->createMock(RagRetriever::class);
    $promptBuilder = $this->createMock(SystemPromptBuilder::class);
    $toolCallHandler = $this->createMock(ToolCallHandler::class);

    $service = new class(
      $ragRetriever,
      $promptBuilder,
      $toolCallHandler,
    ) extends BeaconAnswerService {

      /**
       * Constructs the service double with no provider configured.
       */
      public function __construct(
        RagRetriever $ragRetriever,
        SystemPromptBuilder $promptBuilder,
        ToolCallHandler $toolCallHandler,
      ) {
        $this->ragRetriever = $ragRetriever;
        $this->promptBuilder = $promptBuilder;
        $this->toolCallHandler = $toolCallHandler;
      }

      /**
       * {@inheritdoc}
       */
      protected function chatDefaults(): ?array {
        return [];
      }

    };

    $this->expectException(\RuntimeException::class);
    $service->answer('Anything');
  }

  /**
   * Every chat call carries a full-trust override for the AI output filter.
   *
   * The AI module filters all model output through
   * \Drupal\ai\Service\HostnameFilter, which removes any link whose host is
   * not on ai.settings.allowed_hosts. That allow-list ships empty and the
   * filter treats empty as block-all, so without this override every link in
   * an answer is stripped and one "ai" channel warning is logged per link.
   * That corrupts the very output the tester exists to score: the answer it
   * records stops matching what the chat widget renders, since
   * ChatApiController disables the same filter on its own streamed path.
   *
   * Asserted on both calls because ToolCallHandler builds the follow-up input
   * itself, so the override has to be set on it separately rather than being
   * inherited from the first request.
   *
   * @covers ::answer
   */
  public function testDisablesOutputLinkFilteringOnEveryCall(): void {
    $toolCall = $this->createMock(ToolsFunctionOutputInterface::class);

    $firstMessage = new ChatMessage('assistant', '');
    $firstMessage->setTools([$toolCall]);

    $toolCallHandler = $this->createMock(ToolCallHandler::class);
    $toolCallHandler->method('followUpInput')->willReturnCallback(
      fn (mixed $normalized, array $messages) => new ChatInput($messages)
    );

    $provider = new class($firstMessage) {

      /**
       * The number of times chat() was called.
       */
      public int $calls = 0;

      /**
       * The override each call carried, read when the call was made.
       */
      public array $receivedFilters = [];

      /**
       * Constructs the double with the first call's response.
       */
      public function __construct(protected ChatMessage $firstMessage) {
      }

      /**
       * Answers the turn, returning a tool call on the first call only.
       */
      public function chat($input, string $model_id, array $tags = []): ChatOutput {
        $this->calls++;
        // Read now, not retained for the assertions to read later: ChatInput is
        // mutable and ProviderProxy consults the override once, before invoking
        // the provider. Asserting on the object after answer() returns would
        // pass just as well if the override were set after the call, which is
        // precisely the regression worth catching.
        $this->receivedFilters[] = $input->getHostnameFilter();
        if ($this->calls === 1) {
          return new ChatOutput($this->firstMessage, NULL, []);
        }
        return new ChatOutput(new ChatMessage('assistant', 'See [the grants page](https://example.yale.edu/grants).'), NULL, []);
      }

    };

    $this->service($provider, $toolCallHandler)->answer('Where do I apply?');

    $this->assertSame(2, $provider->calls);
    foreach ($provider->receivedFilters as $index => $filter) {
      $this->assertInstanceOf(HostnameFilterDto::class, $filter, "Call $index carried a hostname filter override.");
      $this->assertTrue($filter->fullTrust, "Call $index disabled output link filtering.");
    }
  }

}
