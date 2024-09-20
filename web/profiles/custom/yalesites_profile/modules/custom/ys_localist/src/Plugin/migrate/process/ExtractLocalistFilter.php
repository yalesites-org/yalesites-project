<?php

declare(strict_types=1);

namespace Drupal\ys_localist\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Extracts filters from Localist events to prepare for entity reference.
 *
 * @MigrateProcessPlugin(
 *   id = "extract_localist_filter",
 *   handle_multiples = TRUE
 * )
 *
 * Localist events can contain filters which can be imported into Drupal by
 * way of an entity reference to a taxonomy term, for example. To choose the
 * filter, pass it in the filter parameter.
 *
 * @code
 *   field_event_type:
 *     plugin: extract_localist_filter
 *     source: event_filters
 *     filter: event_types
 * @endcode
 */
class ExtractLocalistFilter extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    $filter = $this->configuration['filter'];
    $filterArray = [];
    if (isset($value[$filter])) {
      foreach ($value[$filter] as $filterValues) {
        $filterArray[] = $filterValues['id'];
      }
    }
    return $filterArray;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple(): bool {
    return TRUE;
  }

}
