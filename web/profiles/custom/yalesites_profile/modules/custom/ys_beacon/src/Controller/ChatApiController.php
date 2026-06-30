<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
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
   */
  protected const MAX_PAYLOAD_BYTES = 65536;

  /**
   * Maximum number of transcript messages forwarded to the model.
   */
  protected const MAX_TRANSCRIPT_MESSAGES = 20;

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
