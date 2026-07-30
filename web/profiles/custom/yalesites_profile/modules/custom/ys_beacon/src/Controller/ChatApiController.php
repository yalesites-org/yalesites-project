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
use Drupal\ys_beacon\Service\GuardrailSignalDetector;
use Drupal\ys_beacon\Service\GuardrailTelemetry;
use Drupal\ys_beacon\Service\RagRetriever;
use Drupal\ys_beacon\Service\SuspectTurnLog;
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
   * The guardrail telemetry recorder.
   *
   * @var \Drupal\ys_beacon\Service\GuardrailTelemetry
   */
  protected GuardrailTelemetry $telemetry;

  /**
   * The guardrail signal detector.
   *
   * @var \Drupal\ys_beacon\Service\GuardrailSignalDetector
   */
  protected GuardrailSignalDetector $signalDetector;

  /**
   * The log of turns flagged as suspected injection attempts.
   *
   * @var \Drupal\ys_beacon\Service\SuspectTurnLog
   */
  protected SuspectTurnLog $suspectTurnLog;

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
    $instance->telemetry = $container->get('ys_beacon.guardrail_telemetry');
    $instance->signalDetector = $container->get('ys_beacon.guardrail_signal_detector');
    $instance->suspectTurnLog = $container->get('ys_beacon.suspect_turn_log');
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
    // A site with no index configured has its search index forced off, so
    // retrieval returns nothing and every answer would be an ungrounded,
    // uncited guess. Refuse rather than guess; the widget is not rendered in
    // that state either. The chat toggle alone does not cover this - the config
    // override folds platform authorization into enable_chat, never the index
    // (yalesites-org/YaleSites-Internal#1459).
    if (!$settings->get('enable_chat') || !$settings->get('azure_index_name')) {
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

    // Counted here rather than with the rest of the turn's telemetry below, so
    // an attempt still registers when the turn is refused later for a missing
    // provider or an over-long transcript - the counter exists to make a
    // campaign visible, and a campaign is exactly when turns get refused. It is
    // one bounded preg_match plus a counter write, ahead of a retrieval that
    // makes a remote call, so it is not a meaningful cost. Requests rejected by
    // the flood limiter above are NOT counted: the question has not been parsed
    // at that point, and parsing it before rate limiting would invert the
    // protection. The report page says so.
    $injection = $this->signalDetector->injectionPattern($question);
    if ($injection !== NULL) {
      $this->telemetry->recordInjectionPattern($injection);
    }

    $defaults = $this->chatDefaults();
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

    // Only whether there were any citations is needed for telemetry, so the
    // streamed closure captures a boolean rather than holding the whole
    // citations payload alive for the life of the response.
    $has_citations = $citations !== [];

    // The answer buffer is sized from the injection check: a flagged turn keeps
    // a clamped review copy, every other turn keeps exactly the refusal sample
    // it always did. A guardrail stop is not knowable until after the answer
    // has streamed, so a turn kept for a stop alone carries only that shorter
    // sample - stated on the report page rather than left a surprise.
    //
    // The question itself is carried in unconditionally because a stop is not
    // predictable. That is not a new exposure: $tool_line already embeds the
    // question for the closure to emit, so it was in scope for every turn
    // regardless. What still holds is that nothing is *stored* for an ordinary
    // turn.
    $capture_length = $injection !== NULL
      ? SuspectTurnLog::MAX_TEXT_LENGTH
      : GuardrailSignalDetector::REFUSAL_SAMPLE_LENGTH;

    $response = new StreamedResponse(function () use ($defaults, $messages, $streaming, $tool_line, $response_id, $model_id, $has_citations, $injection, $question, $capture_length) {
      $emit = static function (string $line): void {
        echo $line;
        if (ob_get_level() > 0) {
          @ob_flush();
        }
        flush();
      };

      $emit($tool_line);

      // Guardrail telemetry is recorded once, in the finally below, after the
      // answer has been streamed - so counting can never delay the visible
      // answer, and a turn that failed part-way is still counted.
      $chat_input = NULL;
      // The answer is accumulated only up to $capture_length: the refusal
      // sample on an ordinary turn, which is never stored or logged, and the
      // clamped review copy on a turn already flagged as a suspected injection
      // attempt.
      $captured = '';

      try {
        $provider = $this->chatProvider($defaults['provider_id'], $streaming);
        // Held in a variable so the turn's guardrail results can be read back
        // off it below: the AI module's provider proxy passes this very object
        // (not a copy) to its pre- and post-generate events, and its guardrail
        // subscriber records each result onto it, so by the time chat() returns
        // the input carries what ran. That object identity is an implementation
        // detail of the AI module rather than a documented contract - a
        // pre-generate subscriber that replaced the input with a different
        // object would leave guardrail stops uncounted (nothing in the module
        // does so today).
        $chat_input = new ChatInput($messages);
        // The AI module's output filter blocks the links the model returns
        // (its allow-list is empty = block-all), so disable it for this
        // response. See withOutputFilteringDisabled() for the safety rationale.
        $this->withOutputFilteringDisabled(function () use ($provider, $chat_input, $model_id, $emit, $response_id, $capture_length, &$captured) {
          $output = $provider->chat($chat_input, $model_id, ['ys_beacon']);
          $normalized = $output->getNormalized();

          if ($normalized instanceof \Traversable) {
            foreach ($normalized as $chunk) {
              $delta = $chunk->getText();
              if ($delta === '') {
                continue;
              }
              if (mb_strlen($captured) < $capture_length) {
                $captured .= $delta;
              }
              $emit($this->envelope($response_id, $model_id, [
                ['role' => 'assistant', 'content' => $delta],
              ]));
            }
          }
          else {
            $text = $normalized->getText();
            $captured = mb_substr($text, 0, $capture_length);
            $emit($this->envelope($response_id, $model_id, [
              ['role' => 'assistant', 'content' => $text],
            ]));
          }
        });
      }
      catch (\Throwable $e) {
        $this->logger->error('Beacon conversation failed: @message', ['@message' => $e->getMessage()]);
        $emit(json_encode(['error' => 'The assistant is currently unavailable. Please try again later.']) . "\n");
      }
      finally {
        // Telemetry must never be able to break a chat turn, so nothing escapes
        // this block. GuardrailTelemetry already reports its own failures
        // through a guard that tolerates the logger itself failing (logging
        // writes to the same database the counters do), so anything still
        // arriving here is both unexpected and unloggable - swallow it. An
        // escaping throw would also put the answer opening into the logged
        // backtrace as a stack argument, which the privacy constraint forbids.
        //
        // Each write is guarded on its own, and the injection-flagged write
        // goes FIRST because it needs nothing from contrib. Reading the
        // guardrail results off the input evaluates two contrib getters in this
        // frame (see ::recordTurnTelemetry), so a throw from either must not
        // take the flagged-turn write with it - which would leave the injection
        // counter incremented, the text dropped, and nothing logged to say so.
        $logged = $injection !== NULL
          && $this->logSuspectTurn($injection, $question, $captured);

        $stops = 0;
        try {
          $stops = $this->recordTurnTelemetry($has_citations, $captured, $chat_input);
        }
        catch (\Throwable) {
        }

        // A turn a guardrail stopped is worth keeping too - the platform
        // actively blocked it - but only if this turn was not already kept for
        // its injection pattern, so a turn is never stored twice. It comes
        // after the counters because the stop count is only knowable by reading
        // the guardrail results off the input, which is what the guarded call
        // above does.
        if (!$logged && $stops > 0) {
          $this->logSuspectTurn(SuspectTurnLog::REASON_GUARDRAIL_STOP, $question, $captured);
        }
      }
    });

    $response->headers->set('Content-Type', 'application/x-ndjson');
    $response->headers->set('Cache-Control', 'no-cache, private');
    $response->headers->set('X-Accel-Buffering', 'no');
    return $response;
  }

  /**
   * Resolves the site's default chat provider and model.
   *
   * Extracted as the only ::conversation() touchpoint on the AI module's
   * provider manager before the stream, so a test can substitute the answer.
   * AiProviderPluginManager is declared final, so it cannot be mocked, and both
   * touchpoints sitting inline is what previously kept the streamed path out of
   * unit-test reach.
   *
   * @return array|null
   *   The provider defaults, as
   *   AiProviderPluginManager::getDefaultProviderForOperationType() returns.
   */
  protected function chatDefaults(): ?array {
    return $this->aiProvider->getDefaultProviderForOperationType('chat');
  }

  /**
   * Builds the provider for a turn, in streaming mode when configured.
   *
   * The companion seam to ::chatDefaults(), and the only provider touchpoint
   * inside the streamed closure. Returns object rather than ProviderProxy
   * because that class answers through __call and implements no interface, so a
   * test double only has to expose the methods actually used.
   *
   * @param string $provider_id
   *   The provider plugin id from ::chatDefaults().
   * @param bool $streaming
   *   Whether to ask the provider to stream its output.
   *
   * @return object
   *   The provider.
   */
  protected function chatProvider(string $provider_id, bool $streaming): object {
    $provider = $this->aiProvider->createInstance($provider_id);
    if ($streaming) {
      $provider->streamedOutput(TRUE);
    }

    return $provider;
  }

  /**
   * Stores a flagged turn, absorbing any failure.
   *
   * SuspectTurnLog already swallows its own errors; this exists so the caller
   * can guard each write independently without three try blocks inline, and so
   * a failure is visible to the caller as FALSE rather than as an exception on
   * a chat turn that must not fail.
   *
   * @param string $reason
   *   Why the turn was flagged: an injection pattern name, or
   *   SuspectTurnLog::REASON_GUARDRAIL_STOP.
   * @param string $question
   *   The question as asked.
   * @param string $answer
   *   The captured answer text, which may be empty or a short sample.
   *
   * @return bool
   *   TRUE if the write was attempted without throwing.
   */
  private function logSuspectTurn(string $reason, string $question, string $answer): bool {
    try {
      $this->suspectTurnLog->record($reason, $question, $answer);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Records the guardrail telemetry for a finished chat turn.
   *
   * Aggregate counts only - nothing here stores, logs or returns question or
   * answer text. The detector turns text into a boolean or a fixed pattern
   * name, and the telemetry service accepts nothing else
   * (yalesites-org/YaleSites-Internal#1469).
   *
   * The injection-pattern check is deliberately NOT here - it runs earlier, in
   * ::conversation(), so an attempt is still counted on a turn that never got
   * as far as an answer.
   *
   * @param bool $has_citations
   *   Whether retrieval produced any citations for the turn.
   * @param string $answer
   *   The captured answer text. Only its opening is inspected, by isRefusal();
   *   a flagged turn passes a longer buffer, which makes no difference here.
   *   Empty when the turn failed before the model produced anything.
   * @param \Drupal\ai\OperationType\Chat\ChatInput|null $chat_input
   *   The input the model was called with, carrying the turn's guardrail
   *   results, or NULL if the call was never made.
   *
   * @return int
   *   How many guardrail stops the turn recorded. Returned so the caller can
   *   decide whether the turn is worth keeping in full, without parsing the
   *   contrib guardrail results a second time.
   */
  protected function recordTurnTelemetry(bool $has_citations, string $answer, ?ChatInput $chat_input): int {
    // The denominator: recorded for every turn that reached the model, so the
    // other counters can be read as rates rather than raw volumes.
    $this->telemetry->recordTurn();

    if (!$has_citations) {
      $this->telemetry->recordZeroCitations();
    }

    if ($this->signalDetector->isRefusal($answer)) {
      $this->telemetry->recordRefusal();
    }

    if ($chat_input === NULL) {
      return 0;
    }

    return $this->telemetry->recordGuardrailResults(
      $chat_input->getAllGuardrailResults(),
      array_keys($chat_input->getGuardrailSets())
    );
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
