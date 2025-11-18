<?php

namespace Drupal\ys_file_management\Service;

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
   */
  public function __construct(
    FileSystemInterface $file_system,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
  ) {
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
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
   * The deletion process:
   * 1. Validates the file object
   * 2. Validates the file URI
   * 3. Deletes physical file from filesystem
   * 4. Deletes file entity from database
   * 5. Provides user feedback
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to delete.
   *
   * @return bool
   *   TRUE if deletion was successful, FALSE otherwise.
   */
  public function deleteFile(FileInterface $file): bool {
    // Validate the file object.
    if (!$this->validateFile($file)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file object.'));
      return FALSE;
    }

    $file_uri = $file->getFileUri();
    $file_name = $file->getFilename();

    // Validate the file URI.
    if (!$this->validateFileUri($file_uri)) {
      $this->messenger->addError($this->t('Cannot delete file: invalid file location.'));
      return FALSE;
    }

    try {
      // Immediately delete the physical file from the filesystem.
      // This uses FileSystemInterface::delete() for immediate removal,
      // unlike $file->delete() which only marks files for cron cleanup.
      if ($this->fileSystem->delete($file_uri)) {
        // Physical file deleted successfully. Remove the database record.
        $file->delete();
        $this->messenger->addMessage($this->t('Deleted the associated file %name.', [
          '%name' => $file_name,
        ]));
        return TRUE;
      }
      else {
        // Physical deletion failed but don't block media deletion.
        // This is a best-effort approach prioritizing media deletion.
        $this->messenger->addWarning($this->t('Could not delete the physical file %name from the filesystem, but the file record was removed.', [
          '%name' => $file_name,
        ]));
        $this->loggerFactory->get('ys_file_management')->warning('File system deletion failed but continuing: @uri', [
          '@uri' => $file_uri,
        ]);
        // Still delete the file entity to maintain database consistency.
        $file->delete();
        return FALSE;
      }
    }
    catch (FileException $e) {
      // Handle file-specific exceptions (permissions, locks, etc.).
      $this->loggerFactory->get('ys_file_management')->error('File system error deleting @file: @error', [
        '@file' => $file_uri,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('A file system error occurred while deleting %name. The file may be locked or inaccessible.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
    catch (EntityStorageException $e) {
      // Handle database/entity storage errors.
      $this->loggerFactory->get('ys_file_management')->error('Database error deleting file entity @fid: @error', [
        '@fid' => $file->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('A database error occurred while deleting the file record for %name.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
    catch (\Exception $e) {
      // Catch any unexpected exceptions.
      $this->loggerFactory->get('ys_file_management')->error('Unexpected error deleting file @file: @error', [
        '@file' => $file_uri,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('An unexpected error occurred while deleting the file %name.', [
        '%name' => $file_name,
      ]));
      return FALSE;
    }
  }

}
