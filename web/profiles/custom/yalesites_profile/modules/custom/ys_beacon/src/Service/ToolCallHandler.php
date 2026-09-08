<?php

namespace Drupal\ys_beacon\Service;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Psr\Log\LoggerInterface;

/**
 * Builds tool declarations for a chat turn and runs a returned tool call.
 *
 * Shared by the streamed chat endpoint (ChatApiController) and the
 * non-streamed tester path (BeaconAnswerService). Both offer the same tools,
 * detect a returned tool call the same way and build the same follow-up
 * request (::attachTools(), then ::followUpInput()); all that differs is how
 * each consumes the answer that comes back - emitted chunk by chunk, or read
 * off a plain message. The shared half lives here rather than as a copy per
 * surface, the way ys_beacon.index_status owns the index status its three
 * surfaces used to each re-implement.
 *
 * Which function-call plugins are actually offered to the model is an
 * explicit allow-list, not everything registered under a function group:
 * ai_search's own "information_tools" plugin (rag_search) would otherwise be
 * offered on every Beacon turn, bypassing ys_beacon's own RAG retrieval and
 * citation tracking.
 */
class ToolCallHandler {

  public function __construct(
    protected FunctionCallPluginManager $functionCallManager,
    protected LoggerInterface $logger,
    protected array $exposedFunctionCallIds,
  ) {
  }

  /**
   * Builds the tools declaration for the allow-listed function calls.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput|null
   *   The tools input, or NULL when none of the allow-listed ids resolve to
   *   a registered plugin.
   */
  public function buildToolsInput(): ?ToolsInput {
    if (!$this->exposedFunctionCallIds) {
      return NULL;
    }

    $definitions = $this->functionCallManager->getDefinitions();
    $functions = [];
    foreach ($this->exposedFunctionCallIds as $id) {
      if (isset($definitions[$id])) {
        $functions[] = $this->functionCallManager->createInstance($id)->normalize();
      }
    }
    return $functions ? new ToolsInput($functions) : NULL;
  }

  /**
   * Attaches the allow-listed tools to a chat input, if any resolve.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $chat_input
   *   The chat input to attach tools to.
   */
  public function attachTools(ChatInput $chat_input): void {
    $tools = $this->buildToolsInput();
    if ($tools) {
      $chat_input->setChatTools($tools);
    }
  }

  /**
   * Extracts the tool calls a normalized chat output carries, if any.
   *
   * A streamed answer's tool calls are only fully assembled after its
   * iterator has been drained, so they can only be read from it AFTER a
   * caller's foreach over the iterator finishes; a plain (non-streamed)
   * ChatMessage carries them directly. Callers pass whichever they already
   * have in hand once they are done reading it.
   *
   * Reading them can throw from inside contrib, which this module cannot
   * patch: a tool whose parameters are all optional gets called with an empty
   * arguments string that contrib JSON-decodes to NULL and passes into a
   * non-nullable array parameter. Giving every tool a required parameter is
   * the actual fix - see docs/AI_FUNCTION_CALL_TOOLS.md, which is the one
   * place that explains this in full - and the catch below is only
   * defence-in-depth, since absorbing the failure yields a turn with no tool
   * result rather than a working tool call.
   *
   * @param mixed $normalized
   *   The provider's normalized chat output: a ChatMessage, or a streamed
   *   iterator. Typed loosely because neither is guaranteed, which is what
   *   the method_exists() guard below is for.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface[]
   *   Any tool calls the model made in this turn.
   */
  public function extractToolCalls(mixed $normalized): array {
    if (!method_exists($normalized, 'getTools')) {
      return [];
    }
    try {
      return $normalized->getTools() ?? [];
    }
    catch (\Throwable $e) {
      $this->logger->error('Beacon failed to read the model\'s tool calls: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the follow-up request for a turn in which the model called a tool.
   *
   * @param mixed $normalized
   *   The provider's normalized chat output from the turn's first call,
   *   already fully read (see ::extractToolCalls() for why that matters on
   *   the streamed path).
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The transcript the first call was made with.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatInput|null
   *   The input for the follow-up call, or NULL when the model asked for no
   *   tool and the turn is already answered. No tools are attached to it, so
   *   the turn resolves in exactly one extra hop and cannot chain.
   */
  public function followUpInput(mixed $normalized, array $messages): ?ChatInput {
    $tool_calls = $this->extractToolCalls($normalized);
    if (!$tool_calls) {
      return NULL;
    }
    return new ChatInput(array_merge($messages, $this->buildFollowUpMessages($tool_calls)));
  }

  /**
   * Executes the model's tool calls and builds the follow-up messages.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface[] $tool_calls
   *   The tool calls the model returned.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   An assistant message echoing the tool calls, followed by one tool-role
   *   result message per call - append these to the transcript and call the
   *   provider again to get the model's answer.
   */
  public function buildFollowUpMessages(array $tool_calls): array {
    $assistant_message = new ChatMessage('assistant', '');
    $assistant_message->setTools($tool_calls);

    $messages = [$assistant_message];
    foreach ($tool_calls as $tool_call) {
      $messages[] = $this->executeOne($tool_call);
    }
    return $messages;
  }

  /**
   * Executes a single tool call, absorbing any failure.
   *
   * A bad or failing tool call must not break the turn: the model gets a
   * plain-text error result instead, and can decide how to answer around it.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $tool_call
   *   The tool call to execute.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The tool-role result message, tagged with the call's tool id.
   */
  protected function executeOne(ToolsFunctionOutputInterface $tool_call): ChatMessage {
    $name = '';

    try {
      // Read the name inside the try, because reading it can itself throw for
      // exactly the prompt-injected call the allow-list below exists to
      // reject. On the non-streamed path contrib builds the tool call as
      // new ToolsFunctionOutput($input->getChatTools()->getFunctionByName(
      // $name), ...) (OpenAiBasedProviderClientBase), and
      // ToolsInput::getFunctionByName() returns NULL for a function that was
      // never offered. ToolsFunctionOutput's constructor only calls setName()
      // when that argument is non-NULL, so its non-nullable string $name is
      // left uninitialized and getName() raises an Error. The streamed path
      // always sets a name, so this only bites the non-streamed one.
      $name = $tool_call->getName();

      // The model is only ever offered the allow-listed tools
      // (::buildToolsInput()), but FunctionCallPluginManager::
      // convertToolResponseToObject() resolves a function_name against every
      // REGISTERED plugin, not just those offered - so a prompt-injected model
      // naming an unoffered tool (e.g. ai_search's own rag_search, or the ai
      // module's action_plugin derivatives that wrap node operations) must be
      // rejected here, before ever reaching it.
      if (!in_array($name, $this->allowedFunctionNames(), TRUE)) {
        $this->logger->warning('Beacon tool call @name rejected: not on the allow-list.', ['@name' => $name]);
        $result = sprintf('The tool %s is not available.', $name);
      }
      else {
        $function = $this->functionCallManager->convertToolResponseToObject($tool_call);
        $violations = $function->validateContexts();
        if (count($violations) > 0) {
          $result = sprintf('Invalid arguments for %s.', $name);
        }
        elseif ($function instanceof ExecutableFunctionCallInterface) {
          $function->execute();
          $result = $function->getReadableOutput();
        }
        else {
          $result = sprintf('The tool %s cannot be executed.', $name);
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Beacon tool call @name failed: @message', [
        '@name' => $name ?: '(unreadable name)',
        '@message' => $e->getMessage(),
      ]);
      $result = sprintf('The tool %s failed to run.', $name ?: 'requested');
    }

    // Safe outside the try: ToolsFunctionOutput's constructor always sets the
    // tool id, unlike the name.
    $message = new ChatMessage('tool', $result);
    $message->setToolsId($tool_call->getToolId());
    return $message;
  }

  /**
   * The function names of the allow-listed plugins that actually resolve.
   *
   * @return string[]
   *   The allowed function_name values.
   */
  protected function allowedFunctionNames(): array {
    $definitions = $this->functionCallManager->getDefinitions();
    $names = [];
    foreach ($this->exposedFunctionCallIds as $id) {
      if (isset($definitions[$id])) {
        $names[] = $definitions[$id]['function_name'];
      }
    }
    return $names;
  }

}
