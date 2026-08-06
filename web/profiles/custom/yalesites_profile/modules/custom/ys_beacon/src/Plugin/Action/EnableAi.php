<?php

declare(strict_types=1);

namespace Drupal\ys_beacon\Plugin\Action;

/**
 * Provides an Enable AI action.
 *
 * @Action(
 *   id = "ys_beacon_enable_ai",
 *   label = @Translation("Enable AI"),
 *   type = "node",
 *   category = @Translation("Custom"),
 * )
 */
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
