<?php

namespace Drupal\ys_ai_system_instructions\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for orchestrating system instructions management.
 */
class SystemInstructionsManagerService {

  /**
   * The storage service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService
   */
  protected $storageService;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The text format detection service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService
   */
  protected $textFormatDetection;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The assistant writer.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter
   */
  protected $assistantWriter;

  /**
   * Constructs a SystemInstructionsManagerService.
   *
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService $storage_service
   *   The storage service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService $text_format_detection
   *   The text format detection service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter $assistant_writer
   *   The assistant writer.
   */
  public function __construct(SystemInstructionsStorageService $storage_service, LoggerChannelFactoryInterface $logger_factory, TextFormatDetectionService $text_format_detection, AccountProxyInterface $current_user, SystemInstructionsAssistantWriter $assistant_writer) {
    $this->storageService = $storage_service;
    $this->logger = $logger_factory->get('ys_ai_system_instructions');
    $this->textFormatDetection = $text_format_detection;
    $this->currentUser = $current_user;
    $this->assistantWriter = $assistant_writer;
  }

  /**
   * Save system instructions and push them to the chatbot assistant.
   *
   * @param string $instructions
   *   The instructions to save.
   * @param string $notes
   *   Optional notes about this version.
   *
   * @return array
   *   Array with 'success' (bool), 'message' (string), 'version' (int).
   */
  public function saveInstructions(string $instructions, string $notes = ''): array {
    $user_id = $this->currentUser->id();
    $user_name = $this->currentUser->getDisplayName();
    $current_active = $this->storageService->getActiveInstructions();
    $old_version = $current_active['version'] ?? NULL;
    $instructions_length = strlen($instructions);

    $this->logger->info('User initiated save system instructions', [
      'user_id' => $user_id,
      'user_name' => $user_name,
      'instructions_length' => $instructions_length,
      'notes' => $notes,
      'current_version' => $old_version,
    ]);

    // First, check if instructions are different from current version.
    if (!$this->storageService->areInstructionsDifferent($instructions)) {
      $this->logger->info('No changes detected in system instructions save attempt', [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'current_version' => $old_version,
      ]);

      return [
        'success' => TRUE,
        'message' => 'No changes detected. Instructions not saved.',
        'version' => $old_version,
      ];
    }

    // Create local version first.
    $new_version = $this->storageService->createVersion($instructions, $notes);

    $this->logger->info('Local system instructions version created', [
      'user_id' => $user_id,
      'user_name' => $user_name,
      'old_version' => $old_version,
      'new_version' => $new_version,
      'instructions_length' => $instructions_length,
      'notes' => $notes,
    ]);

    // Push the active instructions to the chatbot's AI Assistant so the live
    // chatbot uses them at runtime.
    if (!$this->assistantWriter->writeInstructions($instructions)) {
      $this->logger->error('Failed to update chatbot assistant with system instructions.', [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'new_version' => $new_version,
        'instructions_length' => $instructions_length,
      ]);

      return [
        'success' => FALSE,
        'message' => 'Local version saved but the chatbot assistant could not be updated.',
        'version' => $new_version,
      ];
    }

    $this->logger->info('System instructions saved successfully. Version: @version', [
      '@version' => $new_version,
      'user_id' => $user_id,
      'user_name' => $user_name,
      'old_version' => $old_version,
      'instructions_length' => $instructions_length,
      'action' => 'save_instructions',
    ]);

    return [
      'success' => TRUE,
      'message' => 'Instructions saved successfully. Version: ' . $new_version,
      'version' => $new_version,
    ];
  }

  /**
   * Get the current active system instructions.
   *
   * @return array
   *   Array with 'instructions' and 'version' keys.
   */
  public function getCurrentInstructions(): array {
    // Seed the first version from the chatbot assistant so existing/default
    // instructions are never lost and the form is never blank.
    $this->seedFromAssistantIfEmpty();

    $active = $this->storageService->getActiveInstructions();

    if (!$active) {
      return [
        'instructions' => '',
        'version' => 0,
      ];
    }

    return [
      'instructions' => $this->textFormatDetection->formatText($active['instructions']),
      'version' => (int) $active['version'],
    ];
  }

  /**
   * Seed the first version from the chatbot assistant when none exists.
   *
   * On first use there are no local versions, but the chatbot's AI Assistant
   * already holds instructions (an admin's prior value or the shipped default).
   * Importing them as version 1 migrates that content without loss and keeps
   * the editing form from showing up empty. The assistant already holds this
   * text, so no write-back to the assistant is performed.
   */
  protected function seedFromAssistantIfEmpty(): void {
    if ($this->storageService->getActiveInstructions()) {
      return;
    }

    $assistant_instructions = $this->assistantWriter->readInstructions();
    if (empty(trim((string) $assistant_instructions))) {
      return;
    }

    $version = $this->storageService->createVersion($assistant_instructions, 'Imported from Beacon assistant');

    $this->logger->info('Seeded system instructions version @version from the chatbot assistant.', [
      '@version' => $version,
      'action' => 'seed_from_assistant',
      'instructions_length' => strlen($assistant_instructions),
    ]);
  }

  /**
   * Revert to a previous version and push it to the chatbot assistant.
   *
   * @param int $version
   *   The version number to revert to.
   *
   * @return array
   *   Array with 'success' (bool) and 'message' (string).
   */
  public function revertToVersion(int $version): array {
    $user_id = $this->currentUser->id();
    $user_name = $this->currentUser->getDisplayName();
    $current_active = $this->storageService->getActiveInstructions();
    $current_version = $current_active['version'] ?? NULL;

    $this->logger->info('User initiated revert to system instructions version', [
      'user_id' => $user_id,
      'user_name' => $user_name,
      'current_version' => $current_version,
      'target_version' => $version,
      'action' => 'revert_version',
    ]);

    $target_version = $this->storageService->getVersion($version);

    if (!$target_version) {
      $this->logger->warning('Attempted to revert to non-existent system instructions version', [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'target_version' => $version,
        'current_version' => $current_version,
      ]);

      return [
        'success' => FALSE,
        'message' => 'Version ' . $version . ' not found.',
      ];
    }

    $instructions_length = strlen($target_version['instructions']);

    // Set as active version.
    $this->storageService->setActiveVersion($version);

    $this->logger->info('Local system instructions version reverted', [
      'user_id' => $user_id,
      'user_name' => $user_name,
      'old_version' => $current_version,
      'new_version' => $version,
      'instructions_length' => $instructions_length,
    ]);

    // Push the reverted instructions to the chatbot's AI Assistant so the live
    // chatbot uses them at runtime.
    if (!$this->assistantWriter->writeInstructions($target_version['instructions'])) {
      $this->logger->error('Failed to update chatbot assistant when reverting system instructions.', [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'target_version' => $version,
        'instructions_length' => $instructions_length,
      ]);

      return [
        'success' => FALSE,
        'message' => 'Local version reverted but the chatbot assistant could not be updated.',
      ];
    }

    $this->logger->info('System instructions reverted to version: @version', [
      '@version' => $version,
      'user_id' => $user_id,
      'user_name' => $user_name,
      'old_version' => $current_version,
      'instructions_length' => $instructions_length,
      'action' => 'revert_completed',
    ]);

    return [
      'success' => TRUE,
      'message' => 'Successfully reverted to version ' . $version,
    ];
  }

  /**
   * Get all versions for display.
   *
   * @param bool $use_pager
   *   Whether to use a pager. Defaults to FALSE for backward compatibility.
   * @param int $items_per_page
   *   Number of items per page when using pager. Defaults to 25.
   *
   * @return array
   *   Array of version data for admin display.
   */
  public function getAllVersions(bool $use_pager = FALSE, int $items_per_page = 25): array {
    return $this->storageService->getAllVersions($use_pager, $items_per_page);
  }

  /**
   * Get version statistics.
   *
   * @return array
   *   Array with version count and active version info.
   */
  public function getVersionStats(): array {
    $active = $this->storageService->getActiveInstructions();

    return [
      'total_versions' => $this->storageService->getVersionCount(),
      'active_version' => $active ? (int) $active['version'] : 0,
      'active_created' => $active ? (int) $active['created_date'] : 0,
    ];
  }

  /**
   * Get the storage service.
   *
   * @return \Drupal\ys_ai_system_instructions\Service\SystemInstructionsStorageService
   *   The storage service.
   */
  public function getStorageService(): SystemInstructionsStorageService {
    return $this->storageService;
  }

}
