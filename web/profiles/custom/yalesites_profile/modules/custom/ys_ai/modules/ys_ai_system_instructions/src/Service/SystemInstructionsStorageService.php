<?php

namespace Drupal\ys_ai_system_instructions\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for managing system instructions storage and versioning.
 */
class SystemInstructionsStorageService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The text format detection service.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService
   */
  protected $textFormatDetection;

  /**
   * The table name.
   */
  const TABLE_NAME = 'ys_ai_system_instructions';

  /**
   * Constructs a SystemInstructionsStorageService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService $text_format_detection
   *   The text format detection service.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user, TimeInterface $time, TextFormatDetectionService $text_format_detection) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->textFormatDetection = $text_format_detection;
  }

  /**
   * Get the current active system instructions.
   *
   * @return array|null
   *   Array with instruction data or NULL if none found.
   */
  public function getActiveInstructions(): ?array {
    $query = $this->database->select(self::TABLE_NAME, 'si')
      ->fields('si')
      ->condition('is_active', 1)
      ->orderBy('id', 'DESC')
      ->range(0, 1);

    $result = $query->execute()->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Get all versions of system instructions.
   *
   * @param bool $use_pager
   *   Whether to use a pager. Defaults to FALSE for backward compatibility.
   * @param int $items_per_page
   *   Number of items per page when using pager. Defaults to 25.
   *
   * @return array
   *   Array of instruction versions, ordered by version desc.
   */
  public function getAllVersions(bool $use_pager = FALSE, int $items_per_page = 25): array {
    $query = $this->database->select(self::TABLE_NAME, 'si')
      ->fields('si', ['id', 'version', 'created_by', 'created_date', 'is_active', 'notes'])
      ->orderBy('version', 'DESC');

    if ($use_pager) {
      $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')
        ->limit($items_per_page);
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Get a specific version of system instructions.
   *
   * @param int $version
   *   The version number.
   *
   * @return array|null
   *   Array with instruction data or NULL if not found.
   */
  public function getVersion(int $version): ?array {
    $query = $this->database->select(self::TABLE_NAME, 'si')
      ->fields('si')
      ->condition('version', $version)
      ->orderBy('id', 'DESC')
      ->range(0, 1);

    $result = $query->execute()->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Create a new version of system instructions.
   *
   * @param string $instructions
   *   The instructions content.
   * @param string $notes
   *   Optional notes about this version.
   * @param int $created_by
   *   User ID who created this version. Defaults to current user.
   *
   * @return int
   *   The new version number.
   */
  public function createVersion(string $instructions, string $notes = '', ?int $created_by = NULL): int {
    if ($created_by === NULL) {
      $created_by = $this->currentUser->id();
    }

    // Get the next version number.
    $next_version = $this->getNextVersionNumber();

    // Use database transaction for atomicity.
    $transaction = $this->database->startTransaction();
    try {
      // Lock active versions to prevent race conditions.
      // Use SELECT FOR UPDATE to lock active versions during transaction.
      $active_versions = $this->database->select(self::TABLE_NAME, 'si')
        ->fields('si', ['id'])
        ->condition('is_active', 1)
        ->forUpdate()
        ->execute()
        ->fetchAll();

      // Deactivate all existing versions.
      if (!empty($active_versions)) {
        $this->database->update(self::TABLE_NAME)
          ->fields(['is_active' => 0])
          ->condition('is_active', 1)
          ->execute();
      }

      // Insert the new version.
      $this->database->insert(self::TABLE_NAME)
        ->fields([
          'instructions' => $instructions,
          'version' => $next_version,
          'created_by' => $created_by,
          'created_date' => $this->time->getRequestTime(),
          'is_active' => 1,
          'notes' => $notes,
        ])
        ->execute();

      // Explicitly commit the transaction.
      unset($transaction);
      return $next_version;
    }
    catch (\Exception $e) {
      // Transaction will be rolled back automatically.
      throw $e;
    }
  }

  /**
   * Set a specific version as active.
   *
   * @param int $version
   *   The version number to activate.
   *
   * @return bool
   *   TRUE if successful, FALSE if version doesn't exist.
   */
  public function setActiveVersion(int $version): bool {
    // Check if the version exists.
    $existing = $this->getVersion($version);
    if (!$existing) {
      return FALSE;
    }

    // Use database transaction for atomicity.
    $transaction = $this->database->startTransaction();
    try {
      // Lock active versions to prevent race conditions.
      // Use SELECT FOR UPDATE to lock active versions during transaction.
      $active_versions = $this->database->select(self::TABLE_NAME, 'si')
        ->fields('si', ['id'])
        ->condition('is_active', 1)
        ->forUpdate()
        ->execute()
        ->fetchAll();

      // Deactivate all versions.
      if (!empty($active_versions)) {
        $this->database->update(self::TABLE_NAME)
          ->fields(['is_active' => 0])
          ->condition('is_active', 1)
          ->execute();
      }

      // Activate the specified version.
      $this->database->update(self::TABLE_NAME)
        ->fields(['is_active' => 1])
        ->condition('version', $version)
        ->execute();

      // Explicitly commit the transaction.
      unset($transaction);
      return TRUE;
    }
    catch (\Exception $e) {
      // Transaction will be rolled back automatically.
      throw $e;
    }
  }

  /**
   * Check if instructions are different from the current active version.
   *
   * @param string $instructions
   *   The instructions to compare.
   *
   * @return bool
   *   TRUE if different, FALSE if same.
   */
  public function areInstructionsDifferent(string $instructions): bool {
    $active = $this->getActiveInstructions();

    if (!$active) {
      // No active instructions, so these are different.
      return TRUE;
    }

    // Format both sides consistently for comparison.
    $active_formatted = $this->textFormatDetection->formatText($active['instructions']);
    $input_formatted = $this->textFormatDetection->formatText($instructions);

    return trim($active_formatted) !== trim($input_formatted);
  }

  /**
   * Get the next version number.
   *
   * @return int
   *   The next version number.
   */
  protected function getNextVersionNumber(): int {
    $query = $this->database->select(self::TABLE_NAME, 'si');
    $query->addExpression('MAX(version)', 'max_version');

    $result = $query->execute()->fetchField();

    return ($result ? (int) $result : 0) + 1;
  }

  /**
   * Get version count.
   *
   * @return int
   *   Total number of versions.
   */
  public function getVersionCount(): int {
    return (int) $this->database->select(self::TABLE_NAME)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Delete a specific version.
   *
   * @param int $version
   *   The version number to delete.
   *
   * @return bool
   *   TRUE if successful, FALSE if version doesn't exist or is active.
   */
  public function deleteVersion(int $version): bool {
    $existing = $this->getVersion($version);

    if (!$existing || $existing['is_active']) {
      // Can't delete non-existent or active versions.
      return FALSE;
    }

    $this->database->delete(self::TABLE_NAME)
      ->condition('version', $version)
      ->execute();

    return TRUE;
  }

}
