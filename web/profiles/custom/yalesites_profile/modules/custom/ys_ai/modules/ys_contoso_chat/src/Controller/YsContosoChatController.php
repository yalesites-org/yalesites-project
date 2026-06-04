<?php

namespace Drupal\ys_contoso_chat\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\ys_contoso_chat\Service\CitationStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles Yale Chat API requests.
 *
 * POST /yale-chat/message
 *   Body: {
 *     "messages": [{ "role": "user", "content": "..." }],
 *     "conversation_id": "uuid"
 *   }
 *   Returns a chunked stream of newline-delimited JSON in Azure OpenAI format
 *   so the React frontend needs no changes to its rendering logic.
 *
 * POST /yale-chat/clear
 *   Body: { "conversation_id": "uuid" }
 *   Clears the session thread for the given conversation.
 */
class YsContosoChatController extends ControllerBase {

  /**
   * Flood event name used to rate limit chat requests.
   */
  private const RATE_LIMIT_EVENT = 'ys_contoso_chat.message';

  /**
   * Maximum chat requests allowed per client IP within the time window.
   */
  private const RATE_LIMIT = 30;

  /**
   * Rate limit time window, in seconds.
   */
  private const RATE_WINDOW = 60;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected AiAssistantApiRunner $runner,
    protected FloodInterface $flood,
    protected CitationStore $citationStore,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_assistant_api.runner'),
      $container->get('flood'),
      $container->get('ys_contoso_chat.citation_store'),
    );
  }

  /**
   * Handles a chat message and streams the response.
   */
  public function message(Request $request): Response {
    // Rate limit per client IP to protect the paid AI backend from abuse.
    // Omitting the identifier keys the flood event on the client IP, so each
    // IP has its own budget (this is not a site-wide limit).
    if (!$this->flood->isAllowed(self::RATE_LIMIT_EVENT, self::RATE_LIMIT, self::RATE_WINDOW)) {
      return new JsonResponse(
        ['error' => 'Too many requests. Please wait a moment and try again.'],
        429,
        ['Retry-After' => self::RATE_WINDOW],
      );
    }
    $this->flood->register(self::RATE_LIMIT_EVENT, self::RATE_WINDOW);

    $body = Json::decode($request->getContent());
    $messages = $body['messages'] ?? [];
    $conversation_id = $body['conversation_id'] ?? NULL;

    $user_text = '';
    foreach (array_reverse($messages) as $msg) {
      if (($msg['role'] ?? '') === 'user') {
        $user_text = trim($msg['content'] ?? '');
        break;
      }
    }

    if ($user_text === '') {
      return new JsonResponse(['error' => 'No user message provided.'], 400);
    }

    $assistant_id = $this->config('ys_contoso_chat.settings')->get('assistant_id');
    if (!$assistant_id) {
      return new JsonResponse(['error' => 'Yale Chat is not configured. No assistant selected.'], 503);
    }

    $assistant = $this->entityTypeManager()->getStorage('ai_assistant')->load($assistant_id);
    if (!$assistant instanceof AiAssistant) {
      return new JsonResponse(['error' => 'Configured AI Assistant not found.'], 503);
    }

    $this->runner->setAssistant($assistant);
    $this->runner->streamedOutput(TRUE);

    // Use the client-supplied conversation_id as the session thread key so
    // multi-turn context is preserved across requests within a browser session.
    if ($conversation_id) {
      $this->runner->setThreadsKey($conversation_id);
    }

    $this->runner->setUserMessage(new UserMessage($user_text));
    $this->runner->setThrowException(TRUE);

    // Clear any citations from a previous request so they never leak across
    // turns; the RAG tool repopulates the store during process().
    $this->citationStore->reset();

    try {
      $output = $this->runner->process();
      $normalized = $output->getNormalized();
    }
    catch (\Exception $e) {
      $this->getLogger('ys_contoso_chat')->error('Chat error: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An error occurred. Please try again.'], 500);
    }

    // RAG runs synchronously inside process(), so any citations are available
    // now. Capture them by value for the streamed response closure.
    $citations = $this->citationStore->getCitations();

    $response_id = $conversation_id ?? uniqid('yc-', TRUE);

    if ($normalized instanceof StreamedChatMessageIterator) {
      return $this->streamResponse($normalized, $response_id, $citations);
    }

    // Non-streaming fallback: wrap the full text in the expected envelope.
    $text = $normalized->getText();
    $this->runner->setAssistantMessage($text);
    return new JsonResponse($this->buildEnvelope($response_id, $text, '', $citations));
  }

  /**
   * Clears the session thread for a conversation.
   */
  public function clear(Request $request): JsonResponse {
    $body = Json::decode($request->getContent());
    $conversation_id = $body['conversation_id'] ?? NULL;

    if ($conversation_id) {
      $assistant_id = $this->config('ys_contoso_chat.settings')->get('assistant_id');
      $assistant = $this->entityTypeManager()
        ->getStorage('ai_assistant')
        ->load($assistant_id);

      if ($assistant instanceof AiAssistant) {
        $this->runner->setAssistant($assistant);
        try {
          $this->runner->resetThread($conversation_id);
        }
        catch (\Exception $e) {
          // Thread may not exist yet; ignore.
        }
      }
    }

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Builds a StreamedResponse that emits newline-delimited JSON chunks.
   *
   * The React app expects each chunk to be a JSON object on its own line:
   *   {"id":"...","choices":[{"messages":[{"role":"assistant","content":"..."}]}]}
   *
   * Content accumulates with each chunk so the UI renders partial text
   * progressively without managing diffs.
   *
   * @param \Drupal\ai\OperationType\Chat\StreamedChatMessageIterator $iterator
   *   The streamed chat message iterator.
   * @param string $response_id
   *   The response identifier shared across all chunks.
   * @param array[] $citations
   *   RAG source citations to emit alongside the assistant message.
   */
  protected function streamResponse(StreamedChatMessageIterator $iterator, string $response_id, array $citations = []): StreamedResponse {
    $runner = $this->runner;

    $response = new StreamedResponse(function () use ($iterator, $response_id, $runner, $citations) {
      $accumulated = '';
      $date = date('c');

      $runner->startSession();

      foreach ($iterator as $chunk) {
        $accumulated .= $chunk->getText();
        echo Json::encode($this->buildEnvelope($response_id, $accumulated, $date, $citations)) . "\n";
        flush();
      }

      $runner->setAssistantMessage($accumulated);
    });

    $response->headers->set('Content-Type', 'application/x-ndjson');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Cache-Control', 'no-cache');

    return $response;
  }

  /**
   * Builds the Azure OpenAI-compatible response envelope.
   *
   * When citations are present, a "tool" message carrying the structured
   * citation payload is placed before the assistant message. The React
   * frontend reads the message immediately before the assistant message to
   * render the "References" section, so ordering matters.
   *
   * @param string $id
   *   The response identifier.
   * @param string $content
   *   The accumulated assistant text.
   * @param string $date
   *   The ISO-8601 timestamp for the messages.
   * @param array[] $citations
   *   RAG source citations; when empty, no tool message is emitted.
   */
  protected function buildEnvelope(string $id, string $content, string $date = '', array $citations = []): array {
    $date = $date ?: date('c');
    $messages = [];

    if ($citations) {
      $messages[] = [
        'id' => $id,
        'role' => 'tool',
        'content' => Json::encode(['citations' => $citations, 'intent' => '']),
        'date' => $date,
      ];
    }

    $messages[] = [
      'id' => $id,
      'role' => 'assistant',
      'content' => $content,
      'date' => $date,
    ];

    return [
      'id' => $id,
      'choices' => [
        ['messages' => $messages],
      ],
    ];
  }

}
