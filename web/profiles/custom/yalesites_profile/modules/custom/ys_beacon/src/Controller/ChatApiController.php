<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\HostnameFilter;
use Drupal\ys_beacon\Service\RagRetriever;
use Drupal\ys_beacon\Service\SystemPromptBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the Beacon chat conversation endpoint.
 *
 * The response format matches what the Beacon React widget expects: NDJSON
 * lines, each a complete chat completion envelope. The first line carries a
 * "tool" role message whose content is a JSON-encoded citations payload;
 * subsequent lines carry incremental assistant content deltas. A single
 * non-streamed JSON object is also valid for the client parser.
 */
class ChatApiController extends ControllerBase {

  /**
   * Maximum accepted request body size in bytes.
   *
   * A coarse denial-of-service ceiling on the raw body, sized well above any
   * legitimate conversation. It is deliberately not the model-context limit:
   * the transcript is windowed to the active model's token budget (see
   * ::windowTranscriptToBudget), so an over-long conversation is trimmed rather
   * than rejected. The client no longer re-sends prior-turn tool/citation
   * messages (which the server discards anyway), so a normal conversation stays
   * far below this ceiling.
   */
  protected const MAX_PAYLOAD_BYTES = 1048576;

  /**
   * Maximum number of transcript messages forwarded to the model.
   */
  protected const MAX_TRANSCRIPT_MESSAGES = 20;

  /**
   * Default model context window in tokens, used when unset in config.
   *
   * Sized for the current model (Claude Haiku, 200k). The real model is chosen
   * by Portkey server-side and is not observable here, so operators set the
   * true window in the Beacon administration form (model_context_window); this
   * default only applies until they do.
   */
  public const DEFAULT_CONTEXT_WINDOW = 200000;

  /**
   * Tokens held back from the context window for the model's reply.
   */
  protected const OUTPUT_RESERVE_TOKENS = 4096;

  /**
   * Extra tokens held back to absorb token-estimate error.
   */
  protected const SAFETY_MARGIN_TOKENS = 2048;

  /**
   * Approximate characters per token, for the transcript-windowing estimate.
   */
  protected const CHARS_PER_TOKEN = 4;

  /**
   * Flood control: allowed requests per window, per client IP.
   */
  protected const FLOOD_LIMIT = 30;

  /**
   * Flood control: window length in seconds.
   */
  protected const FLOOD_WINDOW = 300;

  /**
   * The RAG retriever.
   *
   * @var \Drupal\ys_beacon\Service\RagRetriever
   */
  protected RagRetriever $ragRetriever;

  /**
   * The system prompt builder.
   *
   * @var \Drupal\ys_beacon\Service\SystemPromptBuilder
   */
  protected SystemPromptBuilder $promptBuilder;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuid;

  /**
   * The ys_beacon logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The AI module's output URL (hostname) filter.
   *
   * @var \Drupal\ai\Service\HostnameFilter
   */
  protected HostnameFilter $hostnameFilter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->ragRetriever = $container->get('ys_beacon.rag_retriever');
    $instance->promptBuilder = $container->get('ys_beacon.prompt_builder');
    $instance->aiProvider = $container->get('ai.provider');
    $instance->flood = $container->get('flood');
    $instance->uuid = $container->get('uuid');
    $instance->logger = $container->get('logger.channel.ys_beacon');
    $instance->hostnameFilter = $container->get('ai.hostname_filter_service');
    return $instance;
  }

  /**
   * Handles a conversation turn.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying {"messages": [{role, content, ...}, ...]}.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An NDJSON response.
   */
  public function conversation(Request $request): Response {
    $settings = $this->config('ys_beacon.settings');
    if (!$settings->get('enable_chat')) {
      return new JsonResponse(['error' => 'The chat service is not enabled.'], 403);
    }

    if (!$this->flood->isAllowed('ys_beacon.conversation', self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      return new JsonResponse(['error' => 'Too many requests. Please try again shortly.'], 429);
    }
    $this->flood->register('ys_beacon.conversation', self::FLOOD_WINDOW);

    $content = $request->getContent();
    if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
      return new JsonResponse(['error' => 'Request too large.'], 413);
    }

    $payload = json_decode($content, TRUE);
    $transcript = $this->extractTranscript($payload['messages'] ?? NULL);
    $question = $this->lastUserMessage($transcript);
    if ($question === NULL) {
      return new JsonResponse(['error' => 'No user message provided.'], 400);
    }

    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      $this->logger->error('Beacon chat is enabled but no default chat provider is configured.');
      return new JsonResponse(['error' => 'The chat service is not configured.'], 503);
    }

    $citations = $this->ragRetriever->retrieve($question);
    $system_prompt = $this->promptBuilder->build($citations);

    // The transcript is already coarse-capped by message count in
    // extractTranscript(); here it is additionally windowed to the active
    // model's token budget so a long conversation degrades gracefully instead
    // of hitting the model's hard limit. The system prompt (immutable guardrail
    // + instructions + fresh citations) is always kept; older turns are dropped
    // oldest-first to fit.
    $budget = $this->inputTokenBudget($settings) - $this->estimateTokens($system_prompt);
    $transcript = $this->windowTranscriptToBudget($transcript, $budget);
    if ($transcript === NULL) {
      return new JsonResponse(['error' => 'This conversation is too long to continue. Please start a new chat.'], 413);
    }

    $messages = [new ChatMessage('system', $system_prompt)];
    foreach ($transcript as $message) {
      $messages[] = new ChatMessage($message['role'], $message['content']);
    }

    $streaming = (bool) $settings->get('streaming');
    $response_id = $this->uuid->generate();
    $model_id = $defaults['model_id'];

    $tool_line = $this->envelope($response_id, $model_id, [
      [
        'role' => 'tool',
        'content' => json_encode([
          'citations' => $citations,
          'intent' => $question,
        ]),
      ],
    ]);

    $response = new StreamedResponse(function () use ($defaults, $messages, $streaming, $tool_line, $response_id, $model_id) {
      $emit = static function (string $line): void {
        echo $line;
        if (ob_get_level() > 0) {
          @ob_flush();
        }
        flush();
      };

      $emit($tool_line);

      try {
        $provider = $this->aiProvider->createInstance($defaults['provider_id']);
        if ($streaming) {
          $provider->streamedOutput(TRUE);
        }
        // The AI module's output filter blocks the links the model returns
        // (its allow-list is empty = block-all), so disable it for this
        // response. See withOutputFilteringDisabled() for the safety rationale.
        $this->withOutputFilteringDisabled(function () use ($provider, $messages, $model_id, $emit, $response_id) {
          $output = $provider->chat(new ChatInput($messages), $model_id, ['ys_beacon']);
          $normalized = $output->getNormalized();

          if ($normalized instanceof \Traversable) {
            foreach ($normalized as $chunk) {
              $delta = $chunk->getText();
              if ($delta === '') {
                continue;
              }
              $emit($this->envelope($response_id, $model_id, [
                ['role' => 'assistant', 'content' => $delta],
              ]));
            }
          }
          else {
            $emit($this->envelope($response_id, $model_id, [
              ['role' => 'assistant', 'content' => $normalized->getText()],
            ]));
          }
        });
      }
      catch (\Throwable $e) {
        $this->logger->error('Beacon conversation failed: @message', ['@message' => $e->getMessage()]);
        $emit(json_encode(['error' => 'The assistant is currently unavailable. Please try again later.']) . "\n");
      }
    });

    $response->headers->set('Content-Type', 'application/x-ndjson');
    $response->headers->set('Cache-Control', 'no-cache, private');
    $response->headers->set('X-Accel-Buffering', 'no');
    return $response;
  }

  /**
   * Runs a callback with the AI module's output content filter disabled.
   *
   * The AI module (\Drupal\ai\Service\HostnameFilter) filters all model output:
   * it removes links/images whose host is not on ai.settings.allowed_hosts, and
   * also strips dangerous HTML (script/iframe tags, on* handlers,
   * javascript:/data: URLs). Full trust bypasses the whole filter for the
   * duration of the call, not only the hostname allow-list.
   *
   * We disable it because the allow-list ships empty, which the filter treats
   * as block-all: every source or citation link the model returns is stripped
   * (while streaming the removal runs per delta, leaving a broken "[text(").
   * YaleSites content links out to an open-ended set of legitimate hosts
   * (yale.edu, ServiceNow, Microsoft, ...) that cannot be enumerated in an
   * allow-list, and the module offers no denylist.
   *
   * Safe only because the chat answer is rendered by react-markdown WITHOUT
   * rehype-raw (see react/.../Answer.tsx), so raw HTML in model output is
   * escaped rather than executed and dangerous URL schemes are dropped. If
   * raw-HTML rendering is ever enabled on the answer, restore a server-side
   * sanitizer here - full trust removes the only server-side one.
   *
   * A per-request HostnameFilterDto cannot cover streaming: the provider proxy
   * restores the filter before the lazy stream is consumed, and the streamed
   * iterator re-applies filtering per chunk. So the override is set on the
   * shared filter service here and restored afterwards - even on error - so it
   * never leaks to other AI features on the site.
   *
   * @param callable $consume
   *   Callback that invokes the model and consumes its (possibly streamed)
   *   output. It runs with the AI output filter disabled.
   */
  protected function withOutputFilteringDisabled(callable $consume): void {
    $snapshot = $this->hostnameFilter->snapshotSettings();
    $this->hostnameFilter->setFullTrust(TRUE);
    try {
      $consume();
    }
    finally {
      $this->hostnameFilter->restoreSettings($snapshot);
    }
  }

  /**
   * Extracts a clean transcript from the request payload.
   *
   * Only user and assistant messages with non-empty string content are kept,
   * capped to the most recent entries. Tool and error messages produced by
   * the frontend are dropped.
   *
   * @param mixed $messages
   *   The raw messages value from the request payload.
   *
   * @return array[]
   *   Sanitized messages with role and content keys.
   */
  protected function extractTranscript(mixed $messages): array {
    if (!is_array($messages)) {
      return [];
    }
    $transcript = [];
    foreach ($messages as $message) {
      if (!is_array($message)) {
        continue;
      }
      $role = $message['role'] ?? '';
      $content = $message['content'] ?? '';
      if (!in_array($role, ['user', 'assistant'], TRUE) || !is_string($content) || trim($content) === '') {
        continue;
      }
      $transcript[] = ['role' => $role, 'content' => $content];
    }
    return array_slice($transcript, -self::MAX_TRANSCRIPT_MESSAGES);
  }

  /**
   * Returns the content of the most recent user message.
   *
   * @param array[] $transcript
   *   The sanitized transcript.
   *
   * @return string|null
   *   The question, or NULL when the transcript has no user message.
   */
  protected function lastUserMessage(array $transcript): ?string {
    foreach (array_reverse($transcript) as $message) {
      if ($message['role'] === 'user') {
        return $message['content'];
      }
    }
    return NULL;
  }

  /**
   * Estimates the token count of a string.
   *
   * A deliberately model-agnostic approximation (~4 characters per token). The
   * real model is Portkey-routed and not observable here, so no exact tokenizer
   * would be authoritative; the safety margin in ::inputTokenBudget absorbs the
   * error, and windowing is a safety net rather than an exact cutoff.
   *
   * @param string $text
   *   The text to measure.
   *
   * @return int
   *   The estimated number of tokens.
   */
  protected function estimateTokens(string $text): int {
    return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
  }

  /**
   * Returns the token budget available for input, from the configured window.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   The ys_beacon settings.
   *
   * @return int
   *   The context window (operator-set, or the default) minus the output
   *   reserve and the safety margin.
   */
  protected function inputTokenBudget(ImmutableConfig $settings): int {
    $window = (int) ($settings->get('model_context_window') ?: self::DEFAULT_CONTEXT_WINDOW);
    return $window - self::OUTPUT_RESERVE_TOKENS - self::SAFETY_MARGIN_TOKENS;
  }

  /**
   * Windows a transcript to fit a token budget, keeping the most recent turns.
   *
   * Messages are kept newest-first while they fit, then returned in
   * chronological order. Returns NULL when even the most recent turn cannot fit
   * the budget (the fixed system prompt has left too little room), signalling
   * that the conversation cannot continue and the user should start a new chat.
   *
   * @param array[] $transcript
   *   The sanitized transcript, oldest-first.
   * @param int $available
   *   Tokens available for the transcript after the system prompt.
   *
   * @return array[]|null
   *   The kept messages in chronological order, or NULL when nothing fits.
   */
  protected function windowTranscriptToBudget(array $transcript, int $available): ?array {
    if (!$transcript || $available <= 0) {
      return NULL;
    }
    $kept = [];
    $used = 0;
    foreach (array_reverse($transcript) as $message) {
      $cost = $this->estimateTokens($message['content']);
      if ($used + $cost > $available) {
        break;
      }
      $used += $cost;
      $kept[] = $message;
    }
    // NULL when even the most recent turn did not fit the budget.
    return $kept ? array_reverse($kept) : NULL;
  }

  /**
   * Builds one NDJSON chat completion envelope line.
   *
   * @param string $response_id
   *   The response id shared by all lines of this turn.
   * @param string $model_id
   *   The model id.
   * @param array[] $messages
   *   Messages for the single choice in this envelope.
   *
   * @return string
   *   A JSON line terminated with a newline.
   */
  protected function envelope(string $response_id, string $model_id, array $messages): string {
    return json_encode([
      'id' => $response_id,
      'model' => $model_id,
      'created' => time(),
      'object' => 'chat.completion.chunk',
      'choices' => [
        ['messages' => $messages],
      ],
      'history_metadata' => new \stdClass(),
    ]) . "\n";
  }

}
