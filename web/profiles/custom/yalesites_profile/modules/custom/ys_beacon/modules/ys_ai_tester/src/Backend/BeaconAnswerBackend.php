<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Backend;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_beacon\Service\BeaconAnswerService;

/**
 * Answers tester questions with the Beacon assistant.
 *
 * This is the tester's default and only built-in backend: it holds the path the
 * batch used directly before backends existed.
 */
class BeaconAnswerBackend implements AnswerBackendInterface {

  use StringTranslationTrait;

  /**
   * Constructs the Beacon answer backend.
   *
   * @param \Drupal\ys_beacon\Service\BeaconAnswerService $beaconAnswer
   *   The Beacon answer service.
   */
  public function __construct(
    protected BeaconAnswerService $beaconAnswer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::DEFAULT_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string|\Stringable {
    return $this->t('Beacon');
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    // Beacon is the assistant the tester exists to test, so it is always
    // offered. A misconfigured chat provider surfaces per question as a
    // recorded error rather than by hiding the tester's whole reason to exist.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function answer(string $question): array {
    // BeaconAnswerService already returns the ['answer', 'citations'] contract
    // this interface declares, so there is nothing to adapt.
    return $this->beaconAnswer->answer($question);
  }

}
