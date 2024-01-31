<?php

namespace Drupal\ys_templated_content;

/**
 *
 */
class ZipFile {

  protected $tempDir;

  protected $yamls;

  protected $file;

  protected $importManager;

  /**
   *
   */
  public function __construct($file, $importManager) {
    $this->file = $file;
    $this->importManager = $importManager;
  }

  /**
   *
   */
  public function process() {
    $this->extractToTemp();
    $this->findYamlFiles();
    $this->processYamls();
    $this->zipModifiedArchive();
    return $this->tempDir . '/' . basename($this->file, '.zip') . '.modified.zip';
  }

  /**
   *
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
   *
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
   *
   */
  protected function processYamls() {
    foreach ($this->yamls as $yaml) {
      $this->modifyForInsertion($yaml);
    }
  }

  /**
   *
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
   *
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
   *
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
