<?php

namespace Drupal\ys_templated_content\Importers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ys_templated_content\FileTypes\ZipFile;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Takes a zip file and modifies it for new import.
 */
class ZipFileImporter {
  use StringTranslationTrait;

  /**
   * The content sync helper.
   *
   * @var \Drupal\single_content_sync\ContentSyncHelperInterface
   */
  protected $contentSyncHelper;

  /**
   * The import manager.
   *
   * @var \Drupal\ys_templated_content\Managers\ImportManager
   */
  protected $importManager;

  /**
   * The template modifier.
   *
   * @var \Drupal\ys_templated_content\Modifiers\TemplateModifier
   */
  protected $templateModifier;

  /**
   * ZipFileImporter constructor.
   *
   * @param \Drupal\ys_templated_content\Managers\ImportManager $importManager
   *   The import manager.
   */
  public function __construct($importManager) {
    $this->importManager = $importManager;
    $this->contentSyncHelper = $importManager->getContentSyncHelper();
    $this->templateModifier = $importManager->getTemplateModifier();
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
  public function import($filename) {
    $zipFile = new ZipFile($filename, $this);

    $tempDir = $zipFile->extractToTemp();
    $yamlFiles = $this->findYamlFiles($tempDir);
    $this->processYamlFiles($yamlFiles);
    $newFilename = $tempDir . '/' . basename($filename, '.zip') . 'modified.zip';
    $zipFile->zipArchive($newFilename);

    /*
     * Taken from Single Content Sync module.
     *
     * Needed to create a redirection for us and to clean up temp directory.
     * @see https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/ContentImporter.php?ref_type=heads#L376-429
     */

    // Extract zip files to the unique local directory.
    $zip = $this->contentSyncHelper->createZipInstance($newFilename);
    $import_directory = $this->contentSyncHelper->createImportDirectory();
    $zip->extract($import_directory);

    $content_file_path = NULL;
    $batch = [
      'title' => $this->t('Importing template'),
      'operations' => [],
      'file' => '\Drupal\single_content_sync\ContentBatchImporter',
      'finished' => '\Drupal\ys_templated_content\Importers\ZipFileImporter::importFinished',
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

    $batch['operations'][] = [
      '\Drupal\single_content_sync\ContentBatchImporter::cleanImportDirectory',
      [$tempDir],
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
   * Taken from Single Content Sync module: @see https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/ContentImporter.php?ref_type=heads#L348-371
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
   * {@inheritdoc}
   *
   * Redirect to the entity created.
   */
  public static function importFinished($success, $results, $operations) {
    if ($success) {
      // Get the last node created to redirect to.
      $query = \Drupal::entityQuery('node')
        ->sort('changed', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE);
      $nids = $query->execute();
      $nid = reset($nids);

      return new RedirectResponse(
      Url::fromRoute(
      'entity.node.edit_form',
      ['node' => $nid],
      )->toString()
      );
    }
    else {
      drupal_set_message(t('An error occurred and the content could not be imported.'), 'error');
    }
  }

  /**
   * Find all the YAML files in the temp directory.
   *
   * @param string $tempDir
   *   The temp directory.
   *
   * @return array
   *   The YAML files.
   */
  protected function findYamlFiles($tempDir) {
    $yamlFiles = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempDir));
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue;
      }
      if ($file->getExtension() === 'yml') {
        $yamlFiles[] = $file->getPathname();
      }
    }

    return $yamlFiles;
  }

  /**
   * Process the YAML files found for insert.
   */
  protected function processYamlFiles($yamlFiles) {
    foreach ($yamlFiles as $yamlFile) {
      $this->modifyForInsertion($yamlFile);
    }
  }

  /**
   * Modify the YAML file for new insert.
   *
   * @param string $yamlFile
   *   The YAML file.
   */
  protected function modifyForInsertion($yamlFile) {
    $content = $this->importManager->getContentFromFile($yamlFile);
    $uuid = $content['uuid'];
    $newUuid = $this->importManager->generateUuid();
    $content = $this->templateModifier->replaceUuids($content, $uuid, $newUuid);
    $this->importManager->writeContentToFile($yamlFile, $content);
  }

}
