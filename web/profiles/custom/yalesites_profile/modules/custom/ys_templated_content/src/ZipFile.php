<?php

namespace Drupal\ys_templated_content;

/**
 * Takes a zip file and modifies it for new import.
 */
class ZipFile {

  /**
   * The path to the temporary directory.
   *
   * @var string
   */
  protected $tempDir;

  /**
   * The different YAML files found in the zip.
   *
   * @var array
   */
  protected $yamls;

  /**
   * The filename.
   *
   * @var string
   */
  protected $file;

  /**
   * The import manager.
   *
   * @var \Drupal\ys_templated_content\ImportManager
   */
  protected $importManager;

  /**
   * ZipFile constructor.
   *
   * @param string $file
   *   The filename.
   * @param \Drupal\ys_templated_content\ImportManager $importManager
   *   The import manager.
   */
  public function __construct($file, $importManager) {
    $this->file = $file;
    $this->importManager = $importManager;
  }

  /**
   * Process the zip file.
   *
   * @return string
   *   The new filename of the modified zip.
   */
  public function process() {
    $this->extractToTemp();
    $this->findYamlFiles();
    $this->processYamls();
    $this->zipModifiedArchive();
    return $this->tempDir . '/' . basename($this->file, '.zip') . '.modified.zip';
  }

  /**
   * Extract the zip to a temporary directory.
   */
  protected function extractToTemp() {
    $this->tempDir = tempnam(sys_get_temp_dir(), 'zip');
    unlink($this->tempDir);
    mkdir($this->tempDir);
    $zip = new \ZipArchive();
    $zip->open($this->file);
    $zip->extractTo($this->tempDir);
    $zip->close();
  }

  /**
   * Find all the YAML files in the temp directory.
   */
  protected function findYamlFiles() {
    $this->yamls = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempDir));
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue;
      }
      if ($file->getExtension() === 'yml') {
        $this->yamls[] = $file->getPathname();
      }
    }
  }

  /**
   * Process the YAML files found for insert.
   */
  protected function processYamls() {
    foreach ($this->yamls as $yaml) {
      $this->modifyForInsertion($yaml);
    }
  }

  /**
   * Modify the YAML file for new insert.
   *
   * @param string $yaml
   *   The YAML file.
   */
  protected function modifyForInsertion($yaml) {
    $content = $this->importManager->getContentFromFile($yaml);
    // Get the UUID from the file.
    $uuid = $content['uuid'];
    $newUuid = $this->importManager->generateUuid();
    // Find and replace any reference to that UUID with the new one.
    $content = $this->importManager->replaceUuids($content, $uuid, $newUuid);
    $this->importManager->writeContentToFile($yaml, $content);
  }

  /**
   * Zip the modified archive.
   *
   * @return string
   *   The new file path of the modified zip.
   */
  protected function zipModifiedArchive() {
    $zip = new \ZipArchive();
    $newFilename = $this->tempDir . '/' . basename($this->file, '.zip') . '.modified.zip';
    $zip->open($newFilename, \ZipArchive::CREATE);
    $this->addFilesToZip($zip);
    $zip->close();

    return $newFilename;
  }

  /**
   * Add files to the zip.
   *
   * @param \ZipArchive $zip
   *   The zip archive.
   */
  protected function addFilesToZip($zip) {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempDir));
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue;
      }
      // Get pathname relative to temp dir.
      $tempPath = substr($file->getPathname(), strlen($this->tempDir) + 1);
      $zip->addFile($file->getPathname(), $tempPath);
    }
  }

}
