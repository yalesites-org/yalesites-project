<?php

namespace Drupal\ys_ai\Commands;

use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Drush\Commands\DrushCommands;

/**
 * Drush command file for YaleSites AI operations.
 */
class YsAiCommands extends DrushCommands {

  /**
   * The Beacon index provisioner.
   *
   * @var \Drupal\ys_ai\Service\BeaconIndexProvisioner
   */
  protected $beaconIndexProvisioner;

  /**
   * Constructs a YsAiCommands object.
   *
   * @param \Drupal\ys_ai\Service\BeaconIndexProvisioner $beacon_index_provisioner
   *   The Beacon index provisioner.
   */
  public function __construct(BeaconIndexProvisioner $beacon_index_provisioner) {
    parent::__construct();
    $this->beaconIndexProvisioner = $beacon_index_provisioner;
  }

  /**
   * Creates the Beacon Azure AI Search index for the chatbot if it is missing.
   *
   * @command ys-ai:create-index
   * @option force Create or update the index even if it already exists, applying
   *   the current schema (for example, to add new filterable fields).
   * @option recreate Drop the existing index and recreate it from the schema.
   *   Required for schema changes Azure cannot apply in place (for example
   *   changing a field's data type). This discards all indexed documents, so
   *   re-index content afterwards (drush search-api:index beacon_index).
   * @aliases ys-ai-create-index
   * @usage drush ys-ai:create-index
   *   Creates the Beacon Azure AI Search index if it does not already exist.
   * @usage drush ys-ai:create-index --force
   *   Applies the current schema to the index even if it already exists.
   * @usage drush ys-ai:create-index --recreate
   *   Drops and recreates the index (then re-index content to repopulate it).
   */
  public function createIndex(array $options = ['force' => FALSE, 'recreate' => FALSE]): int {
    $result = $this->beaconIndexProvisioner->ensureIndexExists(
      (bool) ($options['force'] ?? FALSE),
      (bool) ($options['recreate'] ?? FALSE),
    );

    if ($result->isFailure()) {
      $this->logger->error($result->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->logger->success($result->getMessage());
    return self::EXIT_SUCCESS;
  }

}
