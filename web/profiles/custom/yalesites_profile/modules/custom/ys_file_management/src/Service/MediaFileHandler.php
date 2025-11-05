<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for handling media file operations like deletion and cleanup.
 */
class MediaFileHandler {

  use StringTranslationTrait;

  /**
   * The file usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a MediaFileHandler object.
   *
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   *   The file usage service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    FileUsageInterface $file_usage,
    MessengerInterface $messenger,
    LoggerChannelInterface $logger,
    AccountInterface $current_user,
    RequestStack $request_stack,
  ) {
    $this->fileUsage = $file_usage;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * Marks a file as temporary for cron deletion.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to mark as temporary.
   */
  public function markAsTemporary(FileInterface $file): void {
    $filename = $file->getFilename();

    // Set file as temporary using Drupal best practices.
    $file->setTemporary();
    $file->save();

    $this->messenger->addMessage($this->t('The associated file %name has been marked as temporary and will be deleted by cron.', [
      '%name' => $filename,
    ]));

    // Log standard deletion.
    $this->logger->notice('File @fid (@filename) marked as temporary for cron deletion by user @uid.', [
      '@fid' => $file->id(),
      '@filename' => $filename,
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Force deletes a file immediately with full cleanup.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to force delete.
   */
  public function forceDelete(FileInterface $file): void {
    $filename = $file->getFilename();
    $file_id = $file->id();

    // Get all file usage records before deletion.
    $usage_records = $this->fileUsage->listUsage($file);

    // Clean up all file usage records by iterating through them.
    foreach ($usage_records as $module => $module_usages) {
      foreach ($module_usages as $type => $type_usages) {
        foreach ($type_usages as $id => $count) {
          $this->fileUsage->delete($file, $module, $type, $id, $count);
        }
      }
    }

    // Delete the managed file entity (this will also remove the physical file).
    $file->delete();

    $this->messenger->addMessage($this->t('The associated file %name has been immediately deleted. <strong>Warning:</strong> This may have broken other content that referenced this file.', [
      '%name' => $filename,
    ]), 'warning');

    // Log force deletion.
    $this->logger->warning('File @fid (@filename) FORCE deleted immediately by user @uid. This may break other content.', [
      '@fid' => $file_id,
      '@filename' => $filename,
      '@uid' => $this->currentUser->id(),
    ]);
  }

  /**
   * Gets a safe redirect URL after deletion.
   *
   * @return \Drupal\Core\Url
   *   A URL to redirect to after deletion.
   */
  public function getRedirectUrl(): Url {
    // Try to get the referring page from the request.
    $request = $this->requestStack->getCurrentRequest();
    $referer = $request->headers->get('referer');

    if ($referer) {
      // Parse the referer to get a relative URL.
      $parsed = parse_url($referer);
      if (isset($parsed['path']) && !empty($parsed['path'])) {
        // Avoid redirecting back to the delete form or media entity page.
        if (!preg_match('/\/(delete|media\/\d+)$/', $parsed['path'])) {
          try {
            return Url::fromUserInput($parsed['path']);
          }
          catch (\Exception $e) {
            // Fall through to default.
          }
        }
      }
    }

    // Default fallback to media overview page.
    try {
      return Url::fromRoute('entity.media.collection');
    }
    catch (\Exception $e) {
      // Ultimate fallback to homepage.
      return Url::fromRoute('<front>');
    }
  }

  /**
   * Handles file processing based on deletion type.
   *
   * @param \Drupal\file\FileInterface|null $file
   *   The file to process, or NULL if no file.
   * @param bool $force_delete_requested
   *   Whether force deletion was requested.
   * @param bool $can_force_delete
   *   Whether the user has force delete permissions.
   */
  public function processFile(?FileInterface $file, bool $force_delete_requested, bool $can_force_delete): void {
    if (!$file) {
      return;
    }

    if ($can_force_delete && $force_delete_requested) {
      // Platform admin requested force deletion - use cron cleanup.
      $this->markAsTemporary($file);
    }
    else {
      // Standard behavior: mark file as temporary for cron cleanup.
      $this->markAsTemporary($file);
    }
  }

}
