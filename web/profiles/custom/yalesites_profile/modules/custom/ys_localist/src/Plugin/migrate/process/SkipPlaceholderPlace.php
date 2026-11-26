<?php

declare(strict_types=1);

namespace Drupal\ys_localist\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Stops the pipeline for empty or placeholder Localist location names.
 *
 * Localist editors type "Other", "TBD" and the like when there is no real
 * location. Those must not become Yale Location terms.
 *
 * @MigrateProcessPlugin(
 *   id = "skip_placeholder_place"
 * )
 *
 * @code
 *   field_event_place:
 *     -
 *       plugin: skip_placeholder_place
 *       source: place_name
 *     -
 *       plugin: entity_generate
 * @endcode
 */
class SkipPlaceholderPlace extends ProcessPluginBase {

  /**
   * Location names that mean "no location", compared case-insensitively.
   */
  const PLACEHOLDERS = ['', 'other', 'tbd', 'tba', 'n/a'];

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $name = trim((string) $value);
    if (in_array(mb_strtolower($name), self::PLACEHOLDERS, TRUE)) {
      $this->stopPipeline();
      return NULL;
    }
    return $name;
  }

}
