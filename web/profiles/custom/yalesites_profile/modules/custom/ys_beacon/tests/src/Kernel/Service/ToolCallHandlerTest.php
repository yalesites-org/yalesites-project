<?php

namespace Drupal\Tests\ys_beacon\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ys_beacon\Service\ToolCallHandler;
use Psr\Log\NullLogger;

/**
 * Tests tool resolution, execution and follow-up message construction.
 *
 * Uses the real plugin.manager.ai.function_calls service
 * (FunctionCallPluginManager is declared final and cannot be mocked)
 * together with the "ai" module's own ai:weather test fixture plugin, rather
 * than a ys_beacon plugin - so this test does not depend on ys_beacon's own
 * plugin also being correct.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\ToolCallHandler
 */
class ToolCallHandlerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai', 'ai_test', 'user', 'field', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The "ai" module's own function-call plugins are discovered through a
    // deriver that enumerates core Action plugins, one of which needs the
    // "user" entity type to exist - required for
    // FunctionCallPluginManager::getDefinitions() to run at all, regardless
    // of which plugin the allow-list actually asks for.
    $this->installEntitySchema('user');
  }

  /**
   * Builds the handler under test, allow-listing the given plugin ids.
   *
   * @param string[] $exposed_ids
   *   The function-call plugin ids to expose.
   *
   * @return \Drupal\ys_beacon\Service\ToolCallHandler
   *   The handler.
   */
  protected function handler(array $exposed_ids): ToolCallHandler {
    return new ToolCallHandler(
      $this->container->get('plugin.manager.ai.function_calls'),
      new NullLogger(),
      $exposed_ids,
    );
  }

  /**
   * @covers ::buildToolsInput
   */
  public function testBuildToolsInputResolvesOnlyAllowListedPlugins(): void {
    $tools = $this->handler(['ai:weather'])->buildToolsInput();

    $this->assertNotNull($tools);
    $rendered = $tools->renderToolsArray();
    $this->assertCount(1, $rendered);
    $this->assertSame('weather', $rendered[0]['function']['name']);
  }

  /**
   * A plugin not on the allow-list is never offered to the model.
   *
   * This is the safety property the allow-list exists for: a registered
   * plugin (ai:calculator, from the same ai_test module as ai:weather, so it
   * is genuinely present in this test's plugin registry - not merely absent
   * because its module isn't installed) must not be offered just because
   * some other plugin is allow-listed.
   *
   * @covers ::buildToolsInput
   */
  public function testBuildToolsInputIgnoresNonAllowListedPlugins(): void {
    $tools = $this->handler(['ai:weather'])->buildToolsInput();
    $rendered = $tools->renderToolsArray();

    $names = array_column(array_column($rendered, 'function'), 'name');
    $this->assertContains('weather', $names);
    $this->assertNotContains('calculator', $names);
  }

  /**
   * @covers ::buildToolsInput
   */
  public function testBuildToolsInputReturnsNullWhenNothingResolves(): void {
    $tools = $this->handler(['ys_beacon:not_a_real_tool'])->buildToolsInput();
    $this->assertNull($tools);
  }

  /**
   * A successful tool call becomes an assistant echo plus a tool-role result.
   *
   * @covers ::buildFollowUpMessages
   */
  public function testBuildFollowUpMessagesExecutesAndReportsTheResult(): void {
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', [
      'city' => 'London',
      'country' => 'UK',
      'unit' => 'celsius',
    ]);
    $tool_call->setName('weather');

    $messages = $this->handler(['ai:weather'])->buildFollowUpMessages([$tool_call]);

    $this->assertCount(2, $messages);
    $this->assertSame('assistant', $messages[0]->getRole());
    $this->assertSame([$tool_call], $messages[0]->getTools());

    $this->assertSame('tool', $messages[1]->getRole());
    $this->assertSame('call_1', $messages[1]->getToolsId());
    $this->assertSame('15°C', $messages[1]->getText());
  }

  /**
   * A tool call naming an unknown function does not break the turn.
   *
   * Rejected by the same allow-list check as
   * ::testAllowListIsEnforcedAtExecutionNotJustAtOffer() - a name that does
   * not resolve to any registered plugin is just as much "not allowed" as
   * one that resolves to a real, non-allow-listed plugin. Kept as a separate
   * test because it is the more obvious/first case a reader would reach for.
   *
   * @covers ::buildFollowUpMessages
   */
  public function testUnknownToolNameProducesAnErrorResultInsteadOfThrowing(): void {
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', []);
    $tool_call->setName('not_a_real_function');

    $messages = $this->handler(['ai:weather'])->buildFollowUpMessages([$tool_call]);

    $this->assertSame('tool', $messages[1]->getRole());
    $this->assertStringContainsString('not available', $messages[1]->getText());
  }

  /**
   * A tool call missing a required argument reports invalid arguments.
   *
   * @covers ::buildFollowUpMessages
   */
  public function testMissingRequiredArgumentIsReportedRatherThanExecuted(): void {
    // Weather requires city and country; neither is supplied.
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', []);
    $tool_call->setName('weather');

    $messages = $this->handler(['ai:weather'])->buildFollowUpMessages([$tool_call]);

    $this->assertSame('tool', $messages[1]->getRole());
    $this->assertStringContainsString('Invalid arguments', $messages[1]->getText());
  }

  /**
   * A tool call naming a registered but non-allow-listed plugin is rejected.
   *
   * FunctionCallPluginManager::convertToolResponseToObject() resolves a
   * function_name against every registered plugin, not just the ones this
   * handler's allow-list actually offers - a prompt-injected model could name
   * a real plugin it was never told about. This is the load-bearing test for
   * that guard: ai:calculator is genuinely registered (same module as
   * ai:weather), but only ai:weather is on this handler's allow-list.
   *
   * @covers ::buildFollowUpMessages
   */
  public function testAllowListIsEnforcedAtExecutionNotJustAtOffer(): void {
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', ['a' => 1, 'b' => 2]);
    $tool_call->setName('calculator');

    $messages = $this->handler(['ai:weather'])->buildFollowUpMessages([$tool_call]);

    $this->assertSame('tool', $messages[1]->getRole());
    $this->assertStringContainsString('not available', $messages[1]->getText());
  }

  /**
   * @covers ::attachTools
   */
  public function testAttachToolsSetsChatToolsWhenSomethingResolves(): void {
    $chat_input = new ChatInput([new ChatMessage('user', 'hi')]);
    $this->handler(['ai:weather'])->attachTools($chat_input);

    $this->assertNotNull($chat_input->getChatTools());
  }

  /**
   * @covers ::attachTools
   */
  public function testAttachToolsLeavesChatToolsUnsetWhenNothingResolves(): void {
    $chat_input = new ChatInput([new ChatMessage('user', 'hi')]);
    $this->handler(['ys_beacon:not_a_real_tool'])->attachTools($chat_input);

    $this->assertNull($chat_input->getChatTools());
  }

  /**
   * @covers ::extractToolCalls
   */
  public function testExtractToolCallsReadsToolsFromPlainMessage(): void {
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', []);
    $tool_call->setName('weather');
    $message = new ChatMessage('assistant', '');
    $message->setTools([$tool_call]);

    $extracted = $this->handler(['ai:weather'])->extractToolCalls($message);

    $this->assertSame([$tool_call], $extracted);
  }

  /**
   * @covers ::extractToolCalls
   */
  public function testExtractToolCallsReturnsEmptyForAnOrdinaryMessage(): void {
    $message = new ChatMessage('assistant', 'Just an answer.');

    $this->assertSame([], $this->handler(['ai:weather'])->extractToolCalls($message));
  }

  /**
   * Builds a real StreamedChatMessageIterator yielding a tool-call chunk.
   *
   * Overrides doIterate() (the base class's own extension point, with a
   * placeholder default implementation - not abstract) so the SAME
   * production assembly code (StreamedChatMessageIterator::
   * assembleToolCalls(), reached via ::getTools()) that a real provider's
   * response drives runs here too - this is deliberately not a bare stub
   * with a hand-rolled getTools().
   *
   * @param string $arguments_json
   *   The raw (already JSON-encoded) arguments string the delta carries -
   *   exactly the value contrib passes straight to Json::decode().
   *
   * @return \Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface
   *   The iterator, not yet drained.
   */
  protected function streamedToolCallIterator(string $arguments_json): StreamedChatMessageIteratorInterface {
    $tool_delta = new class($arguments_json) {

      /**
       * Constructs the raw tool-call delta double.
       */
      public function __construct(protected string $argumentsJson) {
      }

      /**
       * Matches the shape contrib's own assembleToolCalls() reads.
       */
      public function toArray(): array {
        return [
          'id' => 'call_1',
          'function' => [
            'name' => 'weather',
            'arguments' => $this->argumentsJson,
          ],
        ];
      }

    };

    // The tool delta is assigned via a public property, not a constructor
    // parameter: StreamedChatMessageIteratorInterface declares __construct()
    // as part of its contract, so a subclass adding a second constructor
    // parameter is a fatal LSP violation, not just a lint complaint.
    $iterator = new class(new \ArrayIterator([])) extends StreamedChatMessageIterator {

      /**
       * The tool-call delta this stream yields from doIterate().
       *
       * @var object
       */
      public object $toolDelta;

      /**
       * {@inheritdoc}
       */
      public function doIterate(): \Generator {
        yield $this->createStreamedChatMessage('assistant', '', [], [$this->toolDelta]);
        $this->setFinishReason('tool_calls');
      }

    };
    $iterator->toolDelta = $tool_delta;

    return $iterator;
  }

  /**
   * A real streamed iterator's tool call is only readable after draining.
   *
   * @covers ::extractToolCalls
   */
  public function testExtractToolCallsAssemblesRealStreamedToolCall(): void {
    $iterator = $this->streamedToolCallIterator('{"unit":"celsius"}');

    // Before draining (nothing has iterated it yet), contrib has nothing to
    // assemble from.
    $this->assertSame([], $iterator->getTools());

    // Drain it exactly as ChatApiController::emitAnswer() does.
    iterator_to_array($iterator);

    $extracted = $this->handler(['ai:weather'])->extractToolCalls($iterator);

    $this->assertCount(1, $extracted);
    $this->assertSame('weather', $extracted[0]->getName());
  }

  /**
   * Reproduces the exact live failure mode found via a real model.
   *
   * A zero-argument tool call whose delta carries a genuinely empty
   * arguments string - not "{}" - makes contrib's own assembleToolCalls()
   * TypeError (Json::decode('') -> NULL, passed into ToolsFunctionOutput's
   * non-nullable $arguments parameter). Reproduced through the real
   * StreamedChatMessageIterator rather than a hand-rolled throw.
   *
   * Note WHERE it throws, because it decides which code has to catch it:
   * getIterator() assembles the tool calls once the generator is exhausted,
   * so the TypeError comes out of the drain itself - the caller's foreach -
   * and not out of the later ::extractToolCalls() call. That is why
   * ChatApiController::emitAnswer() wraps its streaming loop; catching it in
   * ::extractToolCalls() alone would never have run. Both layers are needed:
   * this asserts the drain throws, and that ::extractToolCalls() then still
   * answers "no tool calls" instead of throwing a second time.
   *
   * @covers ::extractToolCalls
   */
  public function testExtractToolCallsAbsorbsRealEmptyArgumentsToolCall(): void {
    $iterator = $this->streamedToolCallIterator('');

    $threw = FALSE;
    try {
      iterator_to_array($iterator);
    }
    catch (\TypeError) {
      // Contrib's own bug, thrown from inside the drain - see above.
      $threw = TRUE;
    }
    $this->assertTrue($threw, 'Expected contrib to TypeError while assembling the tool call.');

    $this->assertSame([], $this->handler(['ai:weather'])->extractToolCalls($iterator));
  }

  /**
   * A failure while assembling tool calls is absorbed, not fatal.
   *
   * Confirmed live against a real model
   * (yalesites-org/YaleSites-Internal#1146): a zero-argument tool call can
   * arrive as a genuinely empty arguments string, which contrib's own
   * assembly (Json::decode('') -> NULL) TypeErrors on before this handler
   * ever sees the call. That happens inside contrib
   * code this module cannot patch, so the failure is simulated here via a
   * double whose getTools() throws, the same shape the real exception takes.
   *
   * @covers ::extractToolCalls
   */
  public function testExtractToolCallsAbsorbsAnAssemblyFailure(): void {
    $normalized = new class {

      /**
       * Simulates contrib's own tool-call assembly throwing.
       */
      public function getTools(): array {
        throw new \TypeError('Argument #3 ($arguments) must be of type array, null given.');
      }

    };

    $this->assertSame([], $this->handler(['ai:weather'])->extractToolCalls($normalized));
  }

  /**
   * An answer carrying no tool call needs no follow-up request.
   *
   * @covers ::followUpInput
   */
  public function testFollowUpInputIsNullWithoutToolCall(): void {
    $normalized = new ChatMessage('assistant', 'An ordinary answer.');

    $this->assertNull($this->handler(['ai:weather'])->followUpInput($normalized, []));
  }

  /**
   * The follow-up request appends the tool result to the given transcript.
   *
   * @covers ::followUpInput
   */
  public function testFollowUpInputAppendsResultsToTheTranscript(): void {
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', []);
    $normalized = new ChatMessage('assistant', '');
    $normalized->setTools([$tool_call]);

    $transcript = [new ChatMessage('user', 'What time is it?')];
    $input = $this->handler(['ai:weather'])->followUpInput($normalized, $transcript);

    $this->assertInstanceOf(ChatInput::class, $input);
    $messages = $input->getMessages();
    // The original turn, then the assistant echo, then the tool result.
    $this->assertCount(3, $messages);
    $this->assertSame('What time is it?', $messages[0]->getText());
    $this->assertSame('tool', $messages[2]->getRole());
    // No tools are attached, so the turn cannot chain into a second one.
    $this->assertNull($input->getChatTools());
  }

  /**
   * A tool call whose name cannot even be read is absorbed, not fatal.
   *
   * The non-streamed path builds a tool call as
   * new ToolsFunctionOutput($input->getChatTools()->getFunctionByName($name),
   * ...), and ToolsInput::getFunctionByName() returns NULL for a function that
   * was never offered - which is exactly what a hallucinating or
   * prompt-injected model produces. ToolsFunctionOutput's constructor only
   * sets its non-nullable string $name when that argument is non-NULL, so
   * getName() then raises an Error. Reading the name must therefore happen
   * where the handler's own failure handling covers it, or the allow-list
   * rejection it guards would be unreachable in precisely the injected case
   * it exists for, and the whole turn would fail instead.
   *
   * @covers ::buildFollowUpMessages
   */
  public function testBuildFollowUpMessagesAbsorbsUnreadableToolCallName(): void {
    // Exactly what contrib constructs for an un-offered function name.
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', []);

    $messages = $this->handler(['ai:weather'])->buildFollowUpMessages([$tool_call]);

    // The assistant echo plus one tool result, and the turn survives.
    $this->assertCount(2, $messages);
    $this->assertSame('tool', $messages[1]->getRole());
    $this->assertStringContainsString('failed to run', $messages[1]->getText());
    $this->assertSame('call_1', $messages[1]->getToolsId());
  }

}
