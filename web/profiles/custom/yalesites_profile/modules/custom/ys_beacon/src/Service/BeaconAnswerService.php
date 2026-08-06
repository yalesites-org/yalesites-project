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
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      throw new \RuntimeException('No default chat provider is configured.');
    }

    $citations = $this->ragRetriever->retrieve($question);
    $messages = [
      new ChatMessage('system', $this->promptBuilder->build($citations)),
      new ChatMessage('user', $question),
    ];

    $provider = $this->aiProvider->createInstance($defaults['provider_id']);
    $output = $provider->chat(new ChatInput($messages), $defaults['model_id'], ['ys_beacon']);

    return [
      'answer' => (string) $output->getNormalized()->getText(),
      'citations' => $citations,
    ];
  }

}
