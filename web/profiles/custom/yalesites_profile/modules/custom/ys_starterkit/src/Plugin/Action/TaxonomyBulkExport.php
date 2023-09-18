<?php

namespace Drupal\ys_starterkit\Plugin\Action;

use Drupal\single_content_sync\Plugin\Action\ContentBulkExport;

/**
 * This action is used to export multiple taxonomies in a bulk operation.
 *
 * @Action(
 *  id = "taxonomy_bulk_export",
 *  label = @Translation("Export taxonomy"),
 *  type = "taxonomy_term",
 * )
 */
class TaxonomyBulkExport extends ContentBulkExport {
}
