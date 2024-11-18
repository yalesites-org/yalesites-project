<?php

namespace Drupal\ys_ai\Plugin\AiProvider;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;

/**
 * Plugin implementation of the 'beacon' provider.
 */
#[AiProvider(
  id: 'beacon',
  label: new TranslatableMarkup('Beacon'),
)]
class BeaconAiProvider extends AiProviderClientBase implements EmbeddingsInterface {

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication($authentication): void {
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings($input, string $model_id, array $tags = []): EmbeddingsOutput {
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ys_ai.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
  }

}
