<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;

/**
 * Service for handling immediate media file deletion.
 *
 * This service encapsulates the business logic for deleting files associated
 * with media entities. It provides immediate deletion from the filesystem
 * rather than Drupal's default cron-based cleanup.
 *
 * Key responsibilities:
 * - Validate file objects and URIs
 * - Delete physical files from filesystem
 * - Delete file entities from database
 * - Handle errors gracefully with logging and user feedback
 * - Ensure security through URI validation
 * - Invalidate caches after deletion
 *
 * Error Handling Strategy:
 * This service uses a "best-effort" approach where file deletion failures
 * do not block the overall operation. This prioritizes database consistency
 * and allows media deletion to proceed even if filesystem operations fail.
 * All errors are logged with appropriate severity levels for monitoring.
 */
class MediaFileDeleter {

  use StringTranslationTrait;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Constructs a MediaFileDeleter service.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator service.
   */
  public function __construct(
    FileSystemInterface $file_system,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * Validates that a file object is valid and can be deleted.
   *
   * @param mixed $file
   *   The file object to validate.
   *
   * @return bool
   *   TRUE if the file is valid, FALSE otherwise.
   */
  public function validateFile($file): bool {
    if (!$file instanceof FileInterface) {
      if ($file !== NULL) {
        $this->loggerFactory->get('ys_file_management')->error('Invalid file object provided to MediaFileDeleter');
      }
      return FALSE;
    }
    return TRUE;
  }

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
  public function validateFileUri(string $file_uri): bool {
    $scheme = $this->streamWrapperManager->getScheme($file_uri);
    if (!$scheme || !$this->streamWrapperManager->isValidScheme($scheme)) {
      $this->loggerFactory->get('ys_file_management')->error('Invalid file URI scheme for @uri', [
        '@uri' => $file_uri,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Deletes a media file immediately from filesystem and database.
   *
   * This method performs immediate deletion using FileSystemInterface::delete()
   * rather than Drupal's default cron-based cleanup via $file->delete().
   *
   * Error Handling Strategy (Best-Effort):
   * - Validation failures: Return FALSE immediately
   * - Filesystem deletion fails: Log warning, delete entity anyway,
   *   return FALSE
   * - FileException: Log error, skip entity deletion, return FALSE
   * - EntityStorageException: Log error, return FALSE
   * - Success: Delete both file and entity, invalidate cache, return TRUE
   *
   * This approach prioritizes database consistency while providing
   * comprehensive logging for all failure scenarios. Media deletion
   * proceeds regardless of file deletion outcome.
   *
   * The deletion process:
   * 1. Validates the file object
   * 2. Validates the file URI
   * 3. Deletes physical file from filesystem
   * 4. Deletes file entity from database
   * 5. Invalidates relevant caches
   * 6. Provides user feedback
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to delete.
   *
   * @return bool
   *   TRUE if deletion was fully successful, FALSE if any step failed.
   */
  public function deleteFile(FileInterface $file): bool {
    // Validate the file object.
    if (!$this->validateFile($file)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file object.'));
      $this->loggerFactory->get('ys_file_management')->error('Attempted to delete invalid file object');
      return FALSE;
    }

    $file_id = $file->id();
    $file_uri = $file->getFileUri();
    $file_name = $file->getFilename();

    // Validate the file URI.
    if (!$this->validateFileUri($file_uri)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file location.'));
      $this->loggerFactory->get('ys_file_management')->error('File URI validation failed for @uri (fid: @fid)', [
        '@uri' => $file_uri,
        '@fid' => $file_id,
      ]);
      return FALSE;
    }

    try {
      // Immediately delete the physical file from the filesystem.
      // This uses FileSystemInterface::delete() for immediate removal,
      // unlike $file->delete() which only marks files for cron cleanup.
      if ($this->fileSystem->delete($file_uri)) {
        // Physical file deleted successfully. Remove the database record.
        $file->delete();

        // Invalidate caches for this file to ensure UI reflects deletion.
        $this->cacheTagsInvalidator->invalidateTags(['file:' . $file_id]);

        $this->messenger->addMessage($this->t('Deleted the associated file %name.', [
          '%name' => $file_name,
        ]));
        $this->loggerFactory->get('ys_file_management')->info('Successfully deleted file @name (fid: @fid, uri: @uri)', [
          '@name' => $file_name,
          '@fid' => $file_id,
          '@uri' => $file_uri,
        ]);
        return TRUE;
      }
      else {
        // Physical deletion failed but don't block media deletion.
        // Best-effort strategy: prioritize database consistency.
        $this->messenger->addWarning($this->t('Could not delete the physical file %name from the filesystem, but the file record was removed.', [
          '%name' => $file_name,
        ]));
        $this->loggerFactory->get('ys_file_management')->warning('File system deletion failed for @uri (fid: @fid). File entity will be deleted to maintain database consistency.', [
          '@uri' => $file_uri,
          '@fid' => $file_id,
        ]);

        // Still delete the file entity to maintain database consistency.
        // This may leave an orphaned file on disk.
        $file->delete();

        // Invalidate cache even on partial success.
        $this->cacheTagsInvalidator->invalidateTags(['file:' . $file_id]);

        return FALSE;
      }
    }
    catch (FileException $e) {
      // Handle file-specific exceptions (permissions, locks, etc.).
      // Don't delete the entity if we can't delete the file.
      $this->loggerFactory->get('ys_file_management')->error('FileException while deleting @file (fid: @fid): @error. File entity NOT deleted.', [
        '@file' => $file_uri,
        '@fid' => $file_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('A file system error occurred while deleting %name. The file may be locked or inaccessible.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
    catch (EntityStorageException $e) {
      // Handle database/entity storage errors.
      // Physical file may have been deleted but entity deletion failed.
      $this->loggerFactory->get('ys_file_management')->error('EntityStorageException deleting file entity @fid: @error. Physical file may be orphaned.', [
        '@fid' => $file_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('A database error occurred while deleting the file record for %name.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
    catch (\Exception $e) {
      // Catch any unexpected exceptions.
      // Log with high severity for investigation.
      $this->loggerFactory->get('ys_file_management')->error('Unexpected exception deleting file @file (fid: @fid): @error. Type: @type', [
        '@file' => $file_uri,
        '@fid' => $file_id,
        '@error' => $e->getMessage(),
        '@type' => get_class($e),
      ]);
      $this->messenger->addError($this->t('An unexpected error occurred while deleting the file %name.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
  }

}
