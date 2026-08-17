<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Stores and versions the per-site Beacon system instructions.
 *
 * Instructions are kept verbatim in a dedicated table with a monotonically
 * increasing version number; exactly one row is active at a time.
 */
class SystemInstructionsStorage {

  /**
   * The table name.
   */
  const TABLE_NAME = 'ys_beacon_system_instructions';

  /**
   * Constructs a SystemInstructionsStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {
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
   *   Whether to use a pager. Defaults to FALSE.
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
   *
   * @return int
   *   The new version number.
   */
  public function createVersion(string $instructions, string $notes = ''): int {
    // A transaction keeps the single-active-row invariant and makes the
    // version numbering atomic.
    $transaction = $this->database->startTransaction();
    try {
      $next_version = $this->getNextVersionNumber();
      $this->deactivateAllVersions();

      $this->database->insert(self::TABLE_NAME)
        ->fields([
          'instructions' => $this->normalizeLineEndings($instructions),
          'version' => $next_version,
          'created_by' => $this->currentUser->id(),
          'created_date' => $this->time->getRequestTime(),
          'is_active' => 1,
          'notes' => $notes,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }

    // Explicitly commit the transaction.
    unset($transaction);
    return $next_version;
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
    $existing = $this->getVersion($version);
    if (!$existing) {
      return FALSE;
    }

    // A transaction keeps the single-active-row invariant.
    $transaction = $this->database->startTransaction();
    try {
      $this->deactivateAllVersions();

      $this->database->update(self::TABLE_NAME)
        ->fields(['is_active' => 1])
        ->condition('version', $version)
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }

    // Explicitly commit the transaction.
    unset($transaction);
    return TRUE;
  }

  /**
   * Deactivates every version; the UPDATE locks the rows it touches.
   */
  protected function deactivateAllVersions(): void {
    $this->database->update(self::TABLE_NAME)
      ->fields(['is_active' => 0])
      ->condition('is_active', 1)
      ->execute();
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

    return trim($this->normalizeLineEndings($active['instructions'])) !== trim($this->normalizeLineEndings($instructions));
  }

  /**
   * Normalizes line endings to \n so browser CRLF submits compare stably.
   */
  protected function normalizeLineEndings(string $text): string {
    return str_replace(["\r\n", "\r"], "\n", $text);
  }

  /**
   * Get the next version number.
   *
   * Locks the rows it reads so concurrent saves cannot both claim the same
   * number; must run inside a transaction.
   *
   * @return int
   *   The next version number.
   */
  protected function getNextVersionNumber(): int {
    $query = $this->database->select(self::TABLE_NAME, 'si')
      ->forUpdate();
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
