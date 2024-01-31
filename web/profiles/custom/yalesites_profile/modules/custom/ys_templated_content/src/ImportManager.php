<?php

namespace Drupal\ys_templated_content;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Drupal\ys_templated_content\Modifiers\TemplateModifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Manager for importing templated content.
 */
class ImportManager {
  use StringTranslationTrait;

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The template modifier.
   *
   * @var \Drupal\ys_templated_content\Support\TemplateModifier
   */
  protected $templateModifier;

  /**
   * The template manager.
   *
   * @var \Drupal\ys_templated_content\TemplateManager
   */
  protected $templateManager;

  /**
   * The content importer.
   *
   * @var \Drupal\single_content_sync\ContentImporterInterface
   */
  protected $contentImporter;

  /**
   * The content sync helper.
   *
   * @var \Drupal\single_content_sync\ContentSyncHelperInterface
   */
  protected $contentSyncHelper;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\single_content_sync\ContentImporterInterface $contentImporter
   *   The content importer.
   * @param \Drupal\single_content_sync\ContentSyncHelperInterface $contentSyncHelper
   *   The content sync helper.
   * @param \Drupal\ys_templated_content\TemplateManager $templateManager
   *   The template manager.
   * @param \Drupal\ys_templated_content\Support\TemplateModifier $templateModifier
   *   The template modifier.
   */
  public function __construct(
    ContentImporterInterface $contentImporter,
    ContentSyncHelperInterface $contentSyncHelper,
    TemplateManager $templateManager,
    TemplateModifier $templateModifier,
  ) {
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->templateManager = $templateManager;
    $this->templateModifier = $templateModifier;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('single_content_sync.importer'),
      $container->get('single_content_sync.helper'),
      $container->get('ys_templated_content.template_manager'),
      $container->get('ys_templated_content.template_modifier'),
    );
  }

  /**
   * Create the import from the sample content.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   */
  public function createImport(
    String $content_type,
    String $template
  ) {
    $filename = $this->templateManager->getFilenameForTemplate($content_type, $template);

    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    switch ($extension) {
      case 'zip':
        return $this->importFromZip($filename);

      case 'yml':
        return $this->importFromFile($filename);

      default:
        throw new \Exception("Unknown extension: $extension");
    }
  }

  /**
   * Create the import from the sample content.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity created.
   */
  protected function importFromZip($filename) {
    $zipFile = new ZipFile($filename, $this);

    $newFilename = $zipFile->process();

    // Extract zip files to the unique local directory.
    $zip = $this->contentSyncHelper->createZipInstance($newFilename);
    $import_directory = $this->contentSyncHelper->createImportDirectory();
    $zip->extract($import_directory);

    $content_file_path = NULL;
    $batch = [
      'title' => $this->t('Importing template'),
      'operations' => [],
      'file' => '\Drupal\single_content_sync\ContentBatchImporter',
      'finished' => '\Drupal\ys_templated_content\ImportManager::importFinished',
    ];

    // Always import assets first, even if they're at the end of ZIP archive.
    foreach ($zip->listContents() as $zip_file) {
      $original_file_path = "{$import_directory}/{$zip_file}";

      // Ensure only files from assets folder are imported.
      if (strpos($zip_file, 'assets') === 0 && is_file($original_file_path)) {
        $batch['operations'][] = [
          '\Drupal\single_content_sync\ContentBatchImporter::batchImportAssets',
          [$original_file_path, $zip_file],
        ];
      }
    }

    foreach ($zip->listContents() as $zip_file) {
      $original_file_path = "{$import_directory}/{$zip_file}";

      if ($this->isZipFileValid($original_file_path)) {
        $content_file_path = $original_file_path;
        $batch['operations'][] = [
          '\Drupal\single_content_sync\ContentBatchImporter::batchImportFile',
          [$original_file_path],
        ];
      }
    }

    if (!$batch['operations']) {
      throw new \Exception(' Please check the structure of the zip file and ensure you do not have an extra parent directory.');
    }

    $batch['operations'][] = [
      '\Drupal\single_content_sync\ContentBatchImporter::cleanImportDirectory',
      [$import_directory],
    ];

    if (is_null($content_file_path)) {
      throw new \Exception('The content file in YAML format could not be found.');
    }

    batch_set($batch);

    return NULL;
  }

  /**
   * Validate zip file before we run batch.
   *
   * @param string $path
   *   The local file path of the extracted zip file.
   *
   * @return bool
   *   TRUE if the valid YML file is found.
   */
  protected function isZipFileValid(string $path): bool {
    $info = pathinfo($path);

    if (is_file($path) && $info['extension'] === 'yml') {
      // Extra directory found, let's skip the operation and trigger
      // an error later.
      [, $directory] = explode('://', $info['dirname']);

      // If there are more than 3 parts, then there is an extra folder.
      // e.g. import/zip/uuid is correct one.
      if (count(explode('/', $directory)) > 3) {
        return FALSE;
      }

      // File name can't start with dot.
      if (strpos($info['filename'], '.') === 0) {
        return FALSE;
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   *
   */
  public static function importFinished($success, $results, $operations) {
    // Get the last node created.
    $query = \Drupal::entityQuery('node')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);
    $nids = $query->execute();
    $nid = reset($nids);
    $node = Node::load($nid);

    return new RedirectResponse(
      Url::fromRoute(
      'entity.node.edit_form',
      ['node' => $nid],
      )->toString()
    );
  }

  /**
   * Import the content from a file.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Redirects to the edit form of the imported entity.
   */
  public function importFromFile($filename, &$context = NULL) {
    /* Taken from the implementation of single_content_sync:
     * https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/Form/ContentImportForm.php?ref_type=heads#L136-143
     *
     * This would be a great way to contribute back:
     * $this->contentSyncHelper->generateEntityFromStringYaml($this::CONTENT);
     */
    $content = file_get_contents($filename);

    $content_array = $this
      ->contentSyncHelper
      ->validateYamlFileContent($content);

    $content_array = $this->templateModifier->process($content_array);

    $entity = $this->contentImporter->doImport($content_array);

    return $entity;
  }

  /**
   *
   */
  public function getContentFromFile($filename) {
    $content = file_get_contents($filename);

    $content_array = $this
      ->contentSyncHelper
      ->validateYamlFileContent($content);

    $content_array = $this->templateModifier->process($content_array);

    return $content_array;
  }

  /**
   *
   */
  public function writeContentToFile($yamlFilename, $content) {
    $yaml = Yaml::encode($content);
    file_put_contents($yamlFilename, $yaml);
  }

  /**
   *
   */
  public function replaceUuids($content, $uuid, $newUuid) {
    if (array_key_exists('uuid', $content) && $content['uuid'] === $uuid) {
      $content['uuid'] = $newUuid;
    }
    // Find any other element that has the original UUID passed and replace it
    // with the new one.
    foreach ($content as $key => $value) {
      if (is_array($value)) {
        $content[$key] = $this->replaceUuids($value, $uuid, $newUuid);
      }
      elseif (is_string($value) && strpos($value, 'entity:node/') !== FALSE) {
        $content[$key] = str_replace('entity:node/' . $uuid, 'entity:node/' . $newUuid, $value);
      }
      elseif (is_string($value) && strpos($value, $uuid) !== FALSE) {
        $content[$key] = str_replace($uuid, $newUuid, $value);
      }
    }

    return $content;
  }

  /**
   *
   */
  public function generateUuid() {
    return $this->templateModifier->generateUuid();
  }

}
