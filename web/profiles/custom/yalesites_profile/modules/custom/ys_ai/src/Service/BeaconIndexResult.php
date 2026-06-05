<?php

namespace Drupal\ys_ai\Service;

/**
 * Immutable outcome of a Beacon index provisioning attempt.
 *
 * Lets callers (the Drush command, the chat settings form) present the result
 * without re-deriving anything: the status drives success vs. error handling
 * and the message is human-readable output.
 */
class BeaconIndexResult {

  /**
   * The Azure index was created by this run.
   */
  const CREATED = 'created';

  /**
   * The Azure index already existed and its schema was updated (--force).
   */
  const UPDATED = 'updated';

  /**
   * The Azure index was dropped and recreated from the schema (--recreate).
   */
  const RECREATED = 'recreated';

  /**
   * The Azure index already existed; nothing was changed.
   */
  const EXISTS = 'exists';

  /**
   * Provisioning failed; see the message for the reason.
   */
  const FAILED = 'failed';

  /**
   * Constructs a BeaconIndexResult.
   *
   * @param string $status
   *   One of self::CREATED, self::EXISTS or self::FAILED.
   * @param string $message
   *   A human-readable description of the outcome.
   * @param string $indexName
   *   The Azure index name, when known.
   */
  public function __construct(
    protected string $status,
    protected string $message,
    protected string $indexName = '',
  ) {}

  /**
   * Creates a "created" result.
   *
   * @param string $index_name
   *   The Azure index name.
   *
   * @return self
   *   The result.
   */
  public static function created(string $index_name): self {
    return new self(self::CREATED, sprintf('Created Azure AI Search index "%s".', $index_name), $index_name);
  }

  /**
   * Creates an "updated" result.
   *
   * @param string $index_name
   *   The Azure index name.
   *
   * @return self
   *   The result.
   */
  public static function updated(string $index_name): self {
    return new self(self::UPDATED, sprintf('Updated Azure AI Search index "%s".', $index_name), $index_name);
  }

  /**
   * Creates a "recreated" result.
   *
   * @param string $index_name
   *   The Azure index name.
   *
   * @return self
   *   The result.
   */
  public static function recreated(string $index_name): self {
    return new self(self::RECREATED, sprintf('Recreated Azure AI Search index "%s" from the current schema. Re-index content to repopulate it.', $index_name), $index_name);
  }

  /**
   * Creates an "already exists" result.
   *
   * @param string $index_name
   *   The Azure index name.
   *
   * @return self
   *   The result.
   */
  public static function alreadyExists(string $index_name): self {
    return new self(self::EXISTS, sprintf('Azure AI Search index "%s" already exists; no changes made.', $index_name), $index_name);
  }

  /**
   * Creates a "failed" result.
   *
   * @param string $message
   *   The reason for the failure.
   * @param string $index_name
   *   The Azure index name, when known.
   *
   * @return self
   *   The result.
   */
  public static function failed(string $message, string $index_name = ''): self {
    return new self(self::FAILED, $message, $index_name);
  }

  /**
   * Gets the status.
   *
   * @return string
   *   One of self::CREATED, self::EXISTS or self::FAILED.
   */
  public function getStatus(): string {
    return $this->status;
  }

  /**
   * Gets the human-readable message.
   *
   * @return string
   *   The message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Gets the Azure index name.
   *
   * @return string
   *   The index name, or an empty string when it could not be resolved.
   */
  public function getIndexName(): string {
    return $this->indexName;
  }

  /**
   * Whether provisioning failed.
   *
   * @return bool
   *   TRUE when the status is self::FAILED.
   */
  public function isFailure(): bool {
    return $this->status === self::FAILED;
  }

}
