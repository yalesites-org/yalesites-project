<?php

namespace Drupal\ys_starterkit\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\single_content_sync\Plugin\Action\ContentBulkExport;

/**
 * This action is used to export multiple taxonomies in a bulk operation.
 */
#[Action(
  id: 'taxonomy_bulk_export',
  label: new TranslatableMarkup('Export taxonomy'),
  type: 'taxonomy_term',
)]
class TaxonomyBulkExport extends ContentBulkExport {
}
