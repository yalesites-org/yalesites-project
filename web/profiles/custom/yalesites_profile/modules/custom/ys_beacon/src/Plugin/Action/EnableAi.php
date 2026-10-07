<?php

declare(strict_types=1);

namespace Drupal\ys_beacon\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides an Enable AI action.
 */
#[Action(
  id: 'ys_beacon_enable_ai',
  label: new TranslatableMarkup('Enable AI'),
  type: 'node',
  category: new TranslatableMarkup('Custom'),
)]
class EnableAi extends MetatagValueSetAction {

  /**
   * {@inheritdoc}
   */
  protected static $entityMetatagFieldName = 'field_metatags';

  /**
   * {@inheritdoc}
   */
  protected static $metatagFieldName = 'ai_disable_indexing';

  /**
   * {@inheritdoc}
   */
  protected static $actionValue = '';

}
