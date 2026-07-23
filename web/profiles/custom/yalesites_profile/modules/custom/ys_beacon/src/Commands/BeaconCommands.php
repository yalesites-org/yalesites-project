<?php

namespace Drupal\ys_beacon\Commands;

use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Beacon Azure AI Search operations.
 */
class BeaconCommands extends DrushCommands {

  public function __construct(
    protected BeaconIndexManager $indexManager,
  ) {
    parent::__construct();
  }

  /**
   * Repoints this site to a different Azure AI Search service.
   *
   * Pins the given endpoint, then creates this site's index on it and queues a
   * full reindex. The paired API key is resolved automatically from the
   * "azure_ai_search_api_keys" map, which must already contain an entry for the
   * new endpoint (the command refuses otherwise). The index on the previous
   * service is left in place - orphaned - for manual cleanup.
   *
   * @param string $url
   *   The new Azure AI Search endpoint URL.
   *
   * @command ys_beacon:repin
   * @aliases ys-beacon-repin
   * @usage ys_beacon:repin https://new-service.search.windows.net
   *   Repoints this site's Beacon index to the given Azure AI Search service.
   */
  public function repin(string $url): void {
    $name = $this->indexManager->repin($url);
    $this->logger()->success(dt('Repinned Beacon to @url; index "@name" provisioned there and content queued for reindex.', [
      '@url' => $url,
      '@name' => $name,
    ]));
  }

}
