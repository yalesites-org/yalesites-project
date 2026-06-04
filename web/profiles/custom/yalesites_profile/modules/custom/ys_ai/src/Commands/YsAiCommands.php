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
   * @aliases ys-ai-create-index
   * @usage drush ys-ai:create-index
   *   Creates the Beacon Azure AI Search index if it does not already exist.
   */
  public function createIndex(): int {
    $result = $this->beaconIndexProvisioner->ensureIndexExists();

    if ($result->isFailure()) {
      $this->logger->error($result->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->logger->success($result->getMessage());
    return self::EXIT_SUCCESS;
  }

}
