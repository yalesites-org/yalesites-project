<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * The 'whc_node' source plugin.
 *
 * @MigrateSource(
 *   id = "whc_node",
 *   source_module = "node",
 * )
 */
class WhcNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $query = parent::query();
    $query->condition('n.status', $this->configuration['status'] ?? 1);

    return $query;
  }

}
