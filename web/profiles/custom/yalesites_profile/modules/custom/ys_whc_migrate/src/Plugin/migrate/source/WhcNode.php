<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Row;
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

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row): bool {
    $result = parent::prepareRow($row);
    if ($this->configuration['node_type'] === 'event') {
      $this->prepareRowEvent($row);
    }

    return $result;
  }

  /**
   * Prepare row for event nodes.
   *
   * @param \Drupal\migrate\Row $row
   *   The row.
   */
  private function prepareRowEvent(Row $row): void {
    $event_time = $row->getSourceProperty('field_event_time');
    if ($event_time) {
      $smart_date = [
        'value' => strtotime($event_time[0]['value']),
        'end_value' => strtotime($event_time[0]['value2']),
        'timezone' => 'America/New_York',
      ];

      if ($smart_date['value'] === $smart_date['end_value']) {
        $smart_date['end_value'] += 60 * 90;
      }

      $smart_date['duration'] = (int) round(($smart_date['end_value'] - $smart_date['value']) / 60);
      $event_time[0]['smart_date'] = $smart_date;

      $row->setSourceProperty('field_event_time', $event_time);
    }

    $series_id = $row->getSourceProperty('field_series/0/target_id') ?? -1;
    if (
      $event_time[0]['smart_date']['value'] < 1690869600 &&
      !in_array($series_id, [3, 14, 15, 16, 17, 27, 32, 48, 56])
    ) {
      // Fall 2023 - August 1st, 2023.
      $row->setSourceProperty('status', 0);
    }
  }

}
