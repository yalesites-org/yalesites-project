<?php

namespace Drupal\ys_templated_content\Importers;

use Drupal\ys_templated_content\Managers\ImportManager;

/**
 * Import content from a yaml file.
 */
class YamlFileImporter {
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
   * YamlFileImporter constructor.
   *
   * @param \Drupal\ys_templated_content\Managers\ImportManager $importManager
   *   The import manager.
   */
  public function __construct(ImportManager $importManager) {
    $this->contentImporter = $importManager->getContentImporter();
    $this->importManager = $importManager;
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
  public function import($filename) {
    $content_array = $this->importManager->getContentFromFile($filename);

    $entity = $this->contentImporter->doImport($content_array);

    return $entity;
  }

}
