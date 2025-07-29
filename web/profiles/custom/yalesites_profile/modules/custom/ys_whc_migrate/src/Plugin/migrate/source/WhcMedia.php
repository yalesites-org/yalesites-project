<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate_file_to_media\Plugin\migrate\source\MediaEntityGeneratorD7;

/**
 * The 'whc_media_entity_generator' source plugin.
 *
 * @MigrateSource(
 *   id = "whc_media_entity_generator",
 *   source_module = "file",
 * )
 */
class WhcMedia extends MediaEntityGeneratorD7 {

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $query = parent::query();
    $query->condition('nr.status', $this->configuration['status'] ?? 1);

    return $query;
  }

}
