<?php

namespace Drupal\ys_beacon\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ys_beacon\Exception\BeaconStageException;

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

    $citations = $this->runStage(
      BeaconStageException::STAGE_RETRIEVAL,
      fn() => $this->ragRetriever->retrieve($question)
    );
    $messages = [
      new ChatMessage('system', $this->promptBuilder->build($citations)),
      new ChatMessage('user', $question),
    ];

    $provider = $this->chatProvider($defaults['provider_id']);
    $chat_input = new ChatInput($messages);
    $this->toolCallHandler->attachTools($chat_input);
    $chat_input->setHostnameFilter($this->outputFilterOverride());

    $output = $this->runStage(
      BeaconStageException::STAGE_CHAT,
      fn() => $provider->chat($chat_input, $defaults['model_id'], ['ys_beacon'])
    );
    $normalized = $output->getNormalized();

    $follow_up_input = $this->toolCallHandler->followUpInput($normalized, $messages);
    if ($follow_up_input) {
      $follow_up_input->setHostnameFilter($this->outputFilterOverride());
      $output = $this->runStage(
        BeaconStageException::STAGE_CHAT_FOLLOW_UP,
        fn() => $provider->chat($follow_up_input, $defaults['model_id'], ['ys_beacon'])
      );
      $normalized = $output->getNormalized();
    }

    return [
      'answer' => (string) $normalized->getText(),
      'citations' => $citations,
    ];
  }

  /**
   * Returns the AI output-filter override applied to every chat call.
   *
   * The AI module filters all model output through
   * \Drupal\ai\Service\HostnameFilter, which removes any link whose host is
   * not on ai.settings.allowed_hosts. That allow-list ships empty and the
   * filter treats empty as block-all, so without this override every link an
   * answer contains - including links back into the site being tested - is
   * stripped, and one "ai" channel warning is logged per link.
   *
   * The allow-list cannot express "trust the sites we index": YaleSites content
   * links to an open-ended set of legitimate hosts (yale.edu, ServiceNow,
   * Microsoft, ...), the module offers no denylist, and there is no
   * match-everything pattern - a bare "*" is escaped to /^\*$/ and matches only
   * the literal hostname "*". Full trust is the only switch that fits.
   *
   * ChatApiController disables the same filter for the streamed chat path (see
   * ::withOutputFilteringDisabled there). Without the equivalent here the
   * tester scores answers whose links have been stripped, so it stops measuring
   * what the chat widget actually renders - the one thing it exists to do.
   *
   * Unlike that streamed path this is a per-call DTO rather than a mutation of
   * the shared filter service: ProviderProxy applies a ChatInput's
   * HostnameFilterDto before invoking the provider and restores the singleton
   * in a finally block, which covers both the non-streamed response and the
   * eagerly filtered tool results without leaking into other AI features. The
   * streamed path cannot use a DTO because the proxy restores the filter before
   * the lazy stream is consumed; this path is not streamed, so it can.
   *
   * Safe because full trust bypasses the whole output filter - its HTML
   * sanitizing included - and every consumer of this answer escapes it:
   * AiTesterController renders answers through Html::escape() at each render
   * site, and the exports carry them as data. If an answer is ever rendered as
   * raw HTML, a server-side sanitizer must be restored here.
   *
   * @return \Drupal\ai\Dto\HostnameFilterDto
   *   A DTO disabling output filtering for a single call.
   */
  protected function outputFilterOverride(): HostnameFilterDto {
    return new HostnameFilterDto(fullTrust: TRUE);
  }

  /**
   * Runs one upstream call, labeling any failure with the stage it came from.
   *
   * Answering a question makes several calls to different outside services, and
   * their exceptions are indistinguishable once they reach the caller: a 500
   * from the embeddings request and a 500 from the chat request arrive as the
   * same openai-php ServerException with the same message. Labeling the stage
   * is what lets a failure be traced to the right service - and, for the two
   * Portkey calls, to the right API key, since chat and embeddings authenticate
   * with different ones and can sit in different gateway workspaces.
   *
   * The cause is preserved as the wrapped exception, so a caller can still
   * classify the failure. This service answers only the AI Tester (the chat
   * widget uses the streamed ChatApiController path), so the wrapping cannot
   * change what a site visitor sees.
   *
   * @param string $stage
   *   A BeaconStageException::STAGE_* constant.
   * @param callable $operation
   *   The call to make.
   *
   * @return mixed
   *   Whatever the call returned.
   *
   * @throws \Drupal\ys_beacon\Exception\BeaconStageException
   *   When the call failed, wrapping the original exception.
   */
  protected function runStage(string $stage, callable $operation): mixed {
    try {
      return $operation();
    }
    catch (\Throwable $e) {
      // The message is carried over verbatim so anything already displaying it
      // - the tester's per-question error cell - reads exactly as before.
      throw new BeaconStageException($stage, $e->getMessage(), $e);
    }
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
