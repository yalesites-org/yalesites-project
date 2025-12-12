<?php

namespace Drupal\ys_file_management\Service;

use Drupal\file\FileInterface;

/**
 * Interface for media file deletion service.
 *
 * Defines the contract for services that handle immediate deletion of files
 * associated with media entities. Implementations should provide immediate
 * deletion from the filesystem rather than Drupal's default cron-based cleanup.
 */
interface MediaFileDeleterInterface {

  /**
   * Validates that a file object is valid and can be deleted.
   *
   * @param mixed $file
   *   The file object to validate.
   *
   * @return bool
   *   TRUE if the file is valid, FALSE otherwise.
   */
  public function validateFile(mixed $file): bool;

  /**
   * Validates that a file URI has a valid stream wrapper scheme.
   *
   * Security: Prevents directory traversal and ensures file is in a managed
   * location (public://, private://, etc.).
   *
   * @param string $file_uri
   *   The file URI to validate.
   *
   * @return bool
   *   TRUE if the URI is valid, FALSE otherwise.
   */
  public function validateFileUri(string $file_uri): bool;

  /**
   * Deletes a media file immediately from filesystem and database.
   *
   * This method performs immediate deletion using FileSystemInterface::delete()
   * rather than Drupal's default cron-based cleanup via $file->delete().
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to delete.
   *
   * @return bool
   *   TRUE if deletion was fully successful, FALSE if any step failed.
   */
  public function deleteFile(FileInterface $file): bool;

}
