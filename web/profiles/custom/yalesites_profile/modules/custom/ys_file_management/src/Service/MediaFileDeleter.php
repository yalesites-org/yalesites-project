<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
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
class MediaFileDeleter implements MediaFileDeleterInterface {

  use StringTranslationTrait;

  /**
   * The logger channel name for this module.
   */
  private const LOGGER_CHANNEL = 'ys_file_management';

  /**
   * Constructs a MediaFileDeleter service.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator service.
   */
  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected MessengerInterface $messenger,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Gets the logger channel for this service.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   The logger channel.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get(self::LOGGER_CHANNEL);
  }

  /**
   * Gets cache tags for a file entity.
   *
   * @param string $file_id
   *   The file ID.
   *
   * @return array
   *   Array of cache tags to invalidate.
   */
  protected function getFileCacheTags(string $file_id): array {
    return ['file:' . $file_id];
  }

  /**
   * {@inheritdoc}
   */
  public function validateFile(mixed $file): bool {
    if (!$file instanceof FileInterface) {
      if ($file !== NULL) {
        $this->getLogger()->error('Invalid file object provided to MediaFileDeleter');
      }
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFileUri(string $file_uri): bool {
    // Use static method for getScheme since it's a utility function.
    $scheme = StreamWrapperManager::getScheme($file_uri);
    if (!$scheme || !$this->streamWrapperManager->isValidScheme($scheme)) {
      $this->getLogger()->error('Invalid file URI scheme for @uri', [
        '@uri' => $file_uri,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFile(FileInterface $file): bool {
    // Validate the file object.
    if (!$this->validateFile($file)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file object.'));
      $this->getLogger()->error('Attempted to delete invalid file object');
      return FALSE;
    }

    $file_id = $file->id();
    $file_uri = $file->getFileUri();
    $file_name = $file->getFilename();

    // Validate the file URI.
    if (!$this->validateFileUri($file_uri)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file location.'));
      $this->getLogger()->error('File URI validation failed for @uri (fid: @fid)', [
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
        $this->cacheTagsInvalidator->invalidateTags($this->getFileCacheTags($file_id));

        $this->messenger->addMessage($this->t('Deleted the associated file %name.', [
          '%name' => $file_name,
        ]));
        $this->getLogger()->info('Successfully deleted file @name (fid: @fid, uri: @uri)', [
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
        $this->getLogger()->warning('File system deletion failed for @uri (fid: @fid). File entity will be deleted to maintain database consistency.', [
          '@uri' => $file_uri,
          '@fid' => $file_id,
        ]);

        // Still delete the file entity to maintain database consistency.
        // This may leave an orphaned file on disk.
        $file->delete();

        // Invalidate cache even on partial success.
        $this->cacheTagsInvalidator->invalidateTags($this->getFileCacheTags($file_id));

        return FALSE;
      }
    }
    catch (FileException $e) {
      // Handle file-specific exceptions (permissions, locks, etc.).
      // Don't delete the entity if we can't delete the file.
      $this->getLogger()->error('FileException while deleting @file (fid: @fid): @error. File entity NOT deleted.', [
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
      $this->getLogger()->error('EntityStorageException deleting file entity @fid: @error. Physical file may be orphaned.', [
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
      $this->getLogger()->error('Unexpected exception deleting file @file (fid: @fid): @error. Type: @type', [
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
