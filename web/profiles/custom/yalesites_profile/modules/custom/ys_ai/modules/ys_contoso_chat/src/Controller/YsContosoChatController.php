<?php

namespace Drupal\ys_contoso_chat\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_assistant_api\Entity\AiAssistant;
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
   * {@inheritdoc}
   */
  public function __construct(
    protected AiAssistantApiRunner $runner,
    protected CsrfTokenGenerator $csrfToken,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_assistant_api.runner'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Handles a chat message and streams the response.
   */
  public function message(Request $request): Response {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$this->csrfToken->validate($token, 'yale-chat/message')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }

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

    try {
      $output = $this->runner->process();
      $normalized = $output->getNormalized();
    }
    catch (\Exception $e) {
      $this->getLogger('ys_contoso_chat')->error('Chat error: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An error occurred. Please try again.'], 500);
    }

    $response_id = $conversation_id ?? uniqid('yc-', TRUE);

    if ($normalized instanceof StreamedChatMessageIterator) {
      return $this->streamResponse($normalized, $response_id);
    }

    // Non-streaming fallback: wrap the full text in the expected envelope.
    $text = $normalized->getText();
    $this->runner->setAssistantMessage($text);
    return new JsonResponse($this->buildEnvelope($response_id, $text));
  }

  /**
   * Clears the session thread for a conversation.
   */
  public function clear(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$this->csrfToken->validate($token, 'yale-chat/message')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }

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
   */
  protected function streamResponse(StreamedChatMessageIterator $iterator, string $response_id): StreamedResponse {
    $runner = $this->runner;

    $response = new StreamedResponse(function () use ($iterator, $response_id, $runner) {
      $accumulated = '';
      $date = date('c');

      $runner->startSession();

      foreach ($iterator as $chunk) {
        $accumulated .= $chunk->getText();
        echo Json::encode($this->buildEnvelope($response_id, $accumulated, $date)) . "\n";
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
   */
  protected function buildEnvelope(string $id, string $content, string $date = ''): array {
    return [
      'id' => $id,
      'choices' => [
        [
          'messages' => [
            [
              'id' => $id,
              'role' => 'assistant',
              'content' => $content,
              'date' => $date ?: date('c'),
            ],
          ],
        ],
      ],
    ];
  }

}
