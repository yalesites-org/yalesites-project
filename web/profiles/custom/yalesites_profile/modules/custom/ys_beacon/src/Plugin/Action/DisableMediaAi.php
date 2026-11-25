<?php

namespace Drupal\ys_beacon\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a Disable AI action for media.
 */
#[Action(
  id: 'ys_beacon_disable_media_ai',
  label: new TranslatableMarkup('Disable AI for media'),
  type: 'media',
  category: new TranslatableMarkup('Custom'),
)]
final class DisableMediaAi extends DisableAi {
}
