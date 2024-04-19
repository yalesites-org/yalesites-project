<?php

namespace Drupal\ys_templated_content\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a TemplateImporter annotation object.
 *
 * @Annotation
 */
class TemplatedImporter extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the importer.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The description of the importer.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * The extension of the file.
   *
   * @var string
   */
  public $extension;

}
