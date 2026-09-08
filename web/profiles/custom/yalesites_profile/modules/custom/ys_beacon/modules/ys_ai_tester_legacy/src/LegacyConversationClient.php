<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester_legacy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Calls the legacy ai_engine Azure conversation endpoint from PHP.
 *
 * The legacy assistant has no server-side answer path in Drupal: answering
 * lives entirely in the external Azure app the React widget talks to. This is
 * the smallest adapter that mirrors what the widget does — POST a one-message
 * transcript to {azure_base_url}/conversation and read the streamed reply. It
 * requires no changes inside ai_engine.
 */
class LegacyConversationClient {

  /**
   * The module whose settings hold the endpoint.
   */
  const MODULE = 'ai_engine_chat';

  /**
   * The config object holding the endpoint.
   */
  const CONFIG_NAME = 'ai_engine_chat.settings';

  /**
   * Seconds to wait for the whole request.
   *
   * Generous because the legacy app has to run a retrieval and a completion,
   * but bounded: without it one hung request could stall an entire batch.
   */
  const REQUEST_TIMEOUT = 60;

  /**
   * Seconds to wait for the connection itself.
   */
  const CONNECT_TIMEOUT = 10;

  /**
   * Constructs the legacy conversation client.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator, for the message id the widget also sends.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for the message timestamp.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected UuidInterface $uuid,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns whether the legacy endpoint is configured on this site.
   *
   * Deliberately keyed on the module being installed and the base URL being
   * set — never on ai_engine_chat.settings:enable or the LegacyAiEngine
   * active/chat-active helpers. Cutover switches those off while leaving the
   * base URL in place, and a cut-over site is the primary case for comparing
   * the two assistants, not an edge case.
   *
   * @return bool
   *   TRUE when a legacy run can be attempted.
   */
  public function isConfigured(): bool {
    if (!$this->moduleHandler->moduleExists(self::MODULE)) {
      return FALSE;
    }
    return $this->baseUrl() !== '';
  }

  /**
   * Asks the legacy assistant one question.
   *
   * @param string $question
   *   The question text.
   *
   * @return array
   *   An array with 'answer' (string) and 'citations' (the legacy citation
   *   list).
   *
   * @throws \RuntimeException
   *   When the endpoint is not configured, the request fails or times out, or
   *   the stream carries an error.
   */
  public function ask(string $question): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('The legacy ai_engine chat endpoint is not configured.');
    }

    try {
      $response = $this->httpClient->request('POST', $this->baseUrl() . '/conversation', [
        'headers' => ['Content-Type' => 'application/json'],
        // The same single-turn transcript shape the widget posts. The tester
        // asks each question independently, so no history is carried over.
        'json' => [
          'messages' => [
            [
              'id' => $this->uuid->generate(),
              'role' => 'user',
              'content' => $question,
              'date' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getRequestTime()),
            ],
          ],
        ],
        'timeout' => self::REQUEST_TIMEOUT,
        'connect_timeout' => self::CONNECT_TIMEOUT,
      ]);
    }
    catch (GuzzleException $e) {
      // Surfaced as a readable message because it is recorded against the
      // question and shown in the tester UI.
      throw new \RuntimeException('The legacy ai_engine request failed: ' . $e->getMessage(), 0, $e);
    }

    return LegacyStreamParser::parse((string) $response->getBody());
  }

  /**
   * Returns the configured base URL without a trailing slash.
   *
   * @return string
   *   The base URL, or an empty string when it is unset or blank.
   */
  protected function baseUrl(): string {
    $configured = (string) $this->configFactory->get(self::CONFIG_NAME)->get('azure_base_url');
    return rtrim(trim($configured), '/');
  }

}
