<?php

namespace Drupal\ys_embed\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a Embed Source item annotation object.
 *
 * @see \Drupal\ys_embed\Plugin\EmbedSourceManager
 * @see plugin_api
 *
 * @Annotation
 */
class EmbedSource extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The short description of the plugin for admin intefaces.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * Whether to include the plugin in the active list of plugins.
   *
   * @var bool
   */
  public $active;

}
