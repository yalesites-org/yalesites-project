<?php

namespace Drupal\ys_beacon\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Answers a question through the Beacon assistant, non-streamed.
 *
 * The chat endpoint streams its answer over HTTP; batch consumers such as the
 * AI tester need the whole answer at once. This runs the same retrieval and
 * system-prompt path the chat uses (RagRetriever + SystemPromptBuilder + the
 * default chat provider) and returns the complete answer with its retrieved
 * citations, so the tester evaluates exactly what the chatbot would say.
 */
class BeaconAnswerService {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected RagRetriever $ragRetriever,
    protected SystemPromptBuilder $promptBuilder,
    protected ToolCallHandler $toolCallHandler,
  ) {
  }

  /**
   * Answers a single question.
   *
   * @param string $question
   *   The user question.
   *
   * @return array
   *   An array with 'answer' (string) and 'citations' (the retrieved sources
   *   in [docN] order).
   *
   * @throws \RuntimeException
   *   When no default chat provider is configured.
   */
  public function answer(string $question): array {
    $defaults = $this->chatDefaults();
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      throw new \RuntimeException('No default chat provider is configured.');
    }

    $citations = $this->ragRetriever->retrieve($question);
    $messages = [
      new ChatMessage('system', $this->promptBuilder->build($citations)),
      new ChatMessage('user', $question),
    ];

    $provider = $this->chatProvider($defaults['provider_id']);
    $chat_input = new ChatInput($messages);
    $this->toolCallHandler->attachTools($chat_input);

    $output = $provider->chat($chat_input, $defaults['model_id'], ['ys_beacon']);
    $normalized = $output->getNormalized();

    $follow_up_input = $this->toolCallHandler->followUpInput($normalized, $messages);
    if ($follow_up_input) {
      $output = $provider->chat($follow_up_input, $defaults['model_id'], ['ys_beacon']);
      $normalized = $output->getNormalized();
    }

    return [
      'answer' => (string) $normalized->getText(),
      'citations' => $citations,
    ];
  }

  /**
   * Resolves the site's default chat provider and model.
   *
   * Extracted as the only touchpoint on the AI module's provider manager
   * before the call, so a test can substitute the answer.
   * AiProviderPluginManager is declared final and cannot be mocked.
   *
   * @return array|null
   *   The provider defaults, as
   *   AiProviderPluginManager::getDefaultProviderForOperationType() returns.
   */
  protected function chatDefaults(): ?array {
    return $this->aiProvider->getDefaultProviderForOperationType('chat');
  }

  /**
   * Builds the provider for a turn.
   *
   * The companion seam to ::chatDefaults(), and the only other provider
   * touchpoint.
   *
   * @param string $provider_id
   *   The provider plugin id from ::chatDefaults().
   *
   * @return object
   *   The provider.
   */
  protected function chatProvider(string $provider_id): object {
    return $this->aiProvider->createInstance($provider_id);
  }

}
