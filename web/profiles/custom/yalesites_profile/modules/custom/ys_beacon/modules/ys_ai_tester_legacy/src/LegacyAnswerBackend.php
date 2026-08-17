<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester_legacy;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ys_ai_tester\AnswerBackendInterface;

/**
 * Exposes the legacy ai_engine assistant as an AI Tester answer backend.
 *
 * Registered through the `ys_ai_tester.answer_backend` tag, so uninstalling
 * this module removes the option and leaves the Beacon-only tester intact.
 */
class LegacyAnswerBackend implements AnswerBackendInterface {

  use StringTranslationTrait;

  /**
   * The backend id recorded on a legacy run.
   */
  const ID = 'legacy';

  /**
   * Constructs the legacy answer backend.
   *
   * @param \Drupal\ys_ai_tester_legacy\LegacyConversationClient $client
   *   The legacy conversation client.
   */
  public function __construct(
    protected LegacyConversationClient $client,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::ID;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string|\Stringable {
    return $this->t('Legacy ai_engine (Azure)');
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->client->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function answer(string $question): array {
    // The legacy citations need no bespoke adapting: they already carry the
    // same keys as Beacon's (both come from the Azure "on your data" shape),
    // and legacy answers reference sources with the same [docN] markers — so
    // the batch's shared formatter derives an equivalent cited flag from them.
    // That is what keeps citation overlap and cited-source counts meaningful
    // on both sides of a comparison.
    return $this->client->ask($question);
  }

}
