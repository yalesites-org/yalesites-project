<?php

namespace Drupal\ys_beacon\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides an Enable AI action for media.
 */
#[Action(
  id: 'ys_beacon_enable_media_ai',
  label: new TranslatableMarkup('Enable AI for media'),
  type: 'media',
  category: new TranslatableMarkup('Custom'),
)]
final class EnableMediaAi extends EnableAi {
}
