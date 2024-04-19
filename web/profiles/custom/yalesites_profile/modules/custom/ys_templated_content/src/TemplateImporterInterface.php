<?php

namespace Drupal\ys_templated_content;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for template importers.
 */
interface TemplateImporterInterface extends PluginInspectionInterface {

  /**
   * Import a template.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity created or NULL.
   */
  public function import(string $filename): EntityInterface | NULL;

  /**
   * Get the extension.
   *
   * @return string
   *   The extension.
   */
  public function getExtension(): string;

}
