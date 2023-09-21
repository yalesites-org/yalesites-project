<?php

namespace Drupal\ys_starterkit\Plugin\Action;

use Drupal\single_content_sync\Plugin\Action\ContentBulkExport;

/**
 * This action is used to export multiple contents in a bulk operation.
 *
 * @Action(
 *  id = "media_bulk_export",
 *  label = @Translation("Export media"),
 *  type = "media",
 * )
 */
class MediaBulkExport extends ContentBulkExport {
}
