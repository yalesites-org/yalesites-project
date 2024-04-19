<?php

namespace Drupal\ys_templated_content\Plugin\TemplatedImporter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\ys_templated_content\TemplateImporterBase;

/**
 * Provides a yaml file template importer.
 *
 * @TemplatedImporter(
 *  id = "yaml_file_importer",
 *  label = @Translation("YAML file importer"),
 *  description = @Translation("For loading a YAML file"),
 *  extension = "yml"
 * )
 */
class YamlFileImporter extends TemplateImporterBase {
  /**
   * The content importer.
   *
   * @var \Drupal\single_content_sync\ContentImporterInterface
   */
  protected $contentImporter;

  /**
   * The import manager.
   *
   * @var \Drupal\ys_templated_content\Managers\ImportManager
   */
  protected $importManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->importManager = $configuration['importManager'];
    $this->contentImporter = $this->importManager->getContentImporter();
  }

  /**
   * Import the content from a yaml file.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity created.
   */
  public function import(string $filename): EntityInterface | NULL {
    $content_array = $this->importManager->getContentFromFile($filename);

    $content_array = $this->process($content_array);

    $entity = $this->contentImporter->doImport($content_array);

    return $entity;
  }

  /**
   * Process the content array.
   *
   * @param array $content_array
   *   The content array.
   */
  public function process($content_array) {
    return $this->removeAlias($content_array);
  }

  /**
   * Remove the alias so Drupal can generate one.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array without an alias.
   */
  protected function removeAlias($content_array) {
    if (isset($content_array['base_fields']['url'])) {
      $content_array['base_fields']['url'] = '';
    }

    return $content_array;
  }

}
