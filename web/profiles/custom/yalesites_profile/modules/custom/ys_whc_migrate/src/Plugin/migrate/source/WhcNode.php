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

    if ($this->configuration['node_type'] === 'event') {
      // Only fetch events whose calendar selection is "WHC Event" or not set.
      $query->leftJoin('field_data_field_calendar_selection', 'fcs', 'n.nid = fcs.entity_id');
      $or = $query->orConditionGroup();
      $or->condition('fcs.field_calendar_selection_value', 1)
        ->isNull('fcs.field_calendar_selection_value');
      $query->condition($or);
    }

    $query->leftJoin('url_alias', 'ua', "ua.source=CONCAT('node/', n.nid)");
    $query->leftJoin('pathauto_state', 'ps', "ps.entity_id=n.nid AND ps.entity_type='node'");
    $query->fields('ua', ['alias']);
    $query->fields('ps', ['pathauto']);

    return $query;
  }

}
