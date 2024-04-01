<?php

namespace Drupal\ys_templated_content\FileTypes;

/**
 * Takes an existing zip file and performs file-based operations on it.
 */
class ZipFile {

  /**
   * The filename of the zip.
   *
   * @var string
   */
  protected $filename;

  /**
   * ZipFile constructor.
   *
   * @param string $filename
   *   The filename of the existing zip file.
   */
  public function __construct($filename) {
    $this->filename = $filename;
  }

  /**
   * Extract the zip to a temporary directory.
   *
   * @return string
   *   The temporary directory path.
   */
  public function extractToTemp() : string {
    $tempDir = tempnam(sys_get_temp_dir(), 'zip');
    unlink($tempDir);
    mkdir($tempDir);
    $zip = new \ZipArchive();
    $zip->open($this->filename);
    $zip->extractTo($tempDir);
    $zip->close();

    return $tempDir;
  }

  /**
   * Zip an archive.
   *
   * @param string $newFilename
   *   The new filename of the zip.
   * @param string $originalPath
   *   The original path of the zip.  If NULL, will use the directory used for
   *   the zip filename.
   *
   * @return string
   *   The new file path of the zip.
   */
  public function zipArchive($newFilename, $originalPath = NULL) : string {
    $tempDir = $originalPath == NULL ? dirname($newFilename) : $originalPath;

    $zip = new \ZipArchive();
    $zip->open($newFilename, \ZipArchive::CREATE);
    $this->addFilesToZip($zip, $tempDir);
    $zip->close();

    return $newFilename;
  }

  /**
   * Add files to the zip.
   *
   * @param \ZipArchive $zip
   *   The zip archive.
   * @param string $directory
   *   The directory to add.
   */
  protected function addFilesToZip($zip, $directory) : void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue;
      }
      // Get pathname relative to temp dir.
      $tempPath = substr($file->getPathname(), strlen($directory) + 1);
      $zip->addFile($file->getPathname(), $tempPath);
    }
  }

}
