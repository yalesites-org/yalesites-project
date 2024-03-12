<?php

namespace Drupal\ys_localist\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Get duration between two date fields in minutes.
 *
 * Available configuration keys:
 * - start_date:
 *
 * Examples:
 *
 * @code
 * process:
 *   plugin: get_duration
 *   source: end_date
 *   start: start_date
 * @endcode
 *
 * This will perform the mathematical operation on the date strings.
 *
 * @MigrateProcessPlugin(
 *   id = "get_date_duration"
 * )
 */
class GetDateDuration extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $startDate = strtotime($row->getSourceProperty($this->configuration['start']));
    $endDate = strtotime($value);

    $duration = ($endDate - $startDate) / 60;

    return $duration;
  }

}
