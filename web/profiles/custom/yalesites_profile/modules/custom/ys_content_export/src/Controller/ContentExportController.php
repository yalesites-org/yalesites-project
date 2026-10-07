<?php

namespace Drupal\ys_content_export\Controller;

use Drupal\ys_content_export\ContentExportBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a CSV export of a content type's nodes for the Manage pages.
 */
class ContentExportController extends ExportControllerBase {

  /**
   * Maps a node bundle to the Manage view that lists it.
   *
   * The export reuses the view's exposed-filter logic so a filtered export
   * matches what the editor sees on screen, rather than maintaining a second
   * copy of the filter logic in this controller.
   */
  const BUNDLE_VIEW = [
    'page' => 'manage_pages',
    'post' => 'manage_posts',
    'event' => 'manage_events',
    'profile' => 'manage_profiles',
    'resource' => 'manage_resources',
  ];

  /**
   * Streams a CSV download of the nodes shown on a Manage page.
   *
   * The route permission ("yalesites manage settings") gates access, mirroring
   * the Manage views. The exported rows come from the matching Manage view with
   * the request's exposed-filter query replayed, so the CSV reflects the same
   * filtered, sorted list the editor is viewing.
   *
   * @param string $bundle
   *   The node bundle machine name (supplied as a route default).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, carrying the Manage page's exposed-filter query.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A streamed CSV file download response.
   */
  public function export(string $bundle, Request $request): Response {
    $view_id = self::BUNDLE_VIEW[$bundle] ?? NULL;
    if (!$view_id) {
      throw new NotFoundHttpException();
    }

    return $this->streamCsv(
      'node',
      $this->filteredIds($view_id, 'nid', $request),
      array_values(ContentExportBuilder::getColumns($bundle)),
      fn($node) => ContentExportBuilder::getRow($node, $bundle, $this->dateFormatter),
      $this->filename($bundle . '-content')
    );
  }

}
