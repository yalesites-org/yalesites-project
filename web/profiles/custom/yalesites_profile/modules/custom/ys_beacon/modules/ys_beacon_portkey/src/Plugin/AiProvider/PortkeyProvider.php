<?php

declare(strict_types=1);

namespace Drupal\ys_beacon_portkey\Plugin\AiProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;

/**
 * Plugin implementation of the Portkey AI gateway provider.
 *
 * Portkey exposes an OpenAI-compatible API, so this provider reuses the
 * OpenAI-based client base and only customizes authentication headers and
 * request routing.
 */
#[AiProvider(
  id: 'portkey',
  label: new TranslatableMarkup('Portkey'),
)]
class PortkeyProvider extends OpenAiBasedProviderClientBase {

  /**
   * The default Portkey gateway base URI.
   */
  const DEFAULT_HOST = 'https://api.portkey.ai/v1';

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat', 'embeddings'];
  }

  /**
   * {@inheritdoc}
   *
   * Deliberately stricter than the base implementation: the base class's
   * hasAuthentication() always returns TRUE, so it would report the provider
   * usable before any API key has been configured.
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    $config = $this->getConfig();
    $models = [];
    if ($operation_type === NULL || $operation_type === 'chat') {
      foreach ($config->get('chat_models') ?? [] as $model_id) {
        $models[$model_id] = $model_id;
      }
    }
    if ($operation_type === NULL || $operation_type === 'embeddings') {
      foreach ($config->get('embeddings_models') ?? [] as $model_id) {
        $models[$model_id] = $model_id;
      }
    }
    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->authenticateForOperation('chat');
    return parent::chat($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->authenticateForOperation('embeddings');
    return parent::embeddings($input, $model_id, $tags);
  }

  /**
   * Activates the configured API key for an operation type.
   *
   * The platform uses separate Portkey API keys for chat and embeddings.
   * Switching keys resets the client so it is rebuilt with the right
   * authentication headers.
   *
   * @param string $operation_type
   *   Either 'chat' or 'embeddings'.
   */
  protected function authenticateForOperation(string $operation_type): void {
    $config = $this->getConfig();
    $key_id = $config->get('api_key');
    if ($operation_type === 'embeddings' && $config->get('embeddings_api_key')) {
      $key_id = $config->get('embeddings_api_key');
    }
    if (!$key_id) {
      return;
    }
    $key_value = $this->keyRepository->getKey($key_id)?->getKeyValue();
    if ($key_value && $key_value !== $this->apiKey) {
      $this->setAuthentication($key_value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    // OpenAI-compatible embedding models routed through Portkey accept up to
    // 8191 tokens per input.
    return 8191;
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(): Client {
    if (empty($this->apiKey)) {
      $this->setAuthentication($this->loadApiKey());
    }
    $config = $this->getConfig();

    // Portkey authenticates with the x-portkey-api-key header. The bearer
    // token is also set for OpenAI SDK compatibility; Portkey ignores it when
    // the Portkey API key header is present.
    $factory = \OpenAI::factory()
      ->withApiKey($this->apiKey)
      ->withHttpHeader('x-portkey-api-key', $this->apiKey)
      ->withHttpClient($this->httpClient)
      ->withBaseUri($config->get('host') ?: self::DEFAULT_HOST);

    // Route requests to an upstream LLM provider configured in Portkey.
    // Model-catalog slugs in the model id (e.g. "@openai/gpt-4o") need no
    // extra headers; otherwise a virtual key or saved config can be set.
    if ($virtual_key = $config->get('virtual_key')) {
      $factory = $factory->withHttpHeader('x-portkey-virtual-key', $virtual_key);
    }
    if ($config_id = $config->get('config_id')) {
      $factory = $factory->withHttpHeader('x-portkey-config', $config_id);
    }

    return $factory->make();
  }

  /**
   * {@inheritdoc}
   *
   * Detects rate-limit, quota, and request-too-large conditions from the
   * HTTP status code rather than from error-message text. Portkey is a gateway
   * fronting multiple upstream providers (OpenAI, Azure, Anthropic, ...), so
   * the human-readable message varies by upstream and by openai-php version;
   * branching on the wording (as the contrib base class does) silently stops
   * working when that wording changes. The status code is stable.
   *
   * There is no synchronous retry loop here: a rate-limited request surfaces a
   * typed exception immediately for the caller (the AI module / streamed chat
   * endpoint) to handle, so a single request can never hold a PHP-FPM worker
   * while it sleeps and waits to retry.
   */
  protected function handleApiException(\Exception $e): void {
    if ($e instanceof ErrorException) {
      switch ($e->getStatusCode()) {
        case 429:
          // OpenAI-compatible APIs report an exhausted billing quota as a 429
          // carrying the machine-readable code "insufficient_quota"; any other
          // 429 is a transient rate limit that retrying can clear.
          if ((string) $e->getErrorCode() === 'insufficient_quota') {
            throw new AiQuotaException($e->getMessage());
          }
          throw new AiRateLimitException($e->getMessage());

        case 413:
          // Payload too large: a rate-limit-class condition the caller handles
          // by shrinking the request, matching the previous "Request too large"
          // mapping.
          throw new AiRateLimitException($e->getMessage());

        case 402:
          // Payment required: the account is out of quota.
          throw new AiQuotaException($e->getMessage());
      }
    }
    // Fall back to the base class's message-based mapping. This still covers
    // the documented streamed-request case where the status code can be 200
    // even on error, so the error body wording is the only remaining signal.
    parent::handleApiException($e);
  }

}
