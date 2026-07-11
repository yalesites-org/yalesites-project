<?php

namespace Drupal\ys_content_export\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Views;
use Drupal\ys_content_export\ContentExportBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a CSV export of a content type's nodes for the Manage pages.
 */
class ContentExportController extends ControllerBase {

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
   * How many nodes to load and write per batch.
   *
   * Bounds memory on large exports: nodes are loaded, written, and released one
   * chunk at a time rather than all at once.
   */
  const CHUNK_SIZE = 50;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

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
    $nids = $this->filteredNids($bundle, $request);
    $columns = array_values(ContentExportBuilder::getColumns($bundle));

    $response = new StreamedResponse(function () use ($nids, $bundle, $columns) {
      $handle = fopen('php://output', 'w');
      // UTF-8 BOM so spreadsheet apps read accented characters correctly.
      fwrite($handle, "\xEF\xBB\xBF");
      fputcsv($handle, $columns);
      foreach (array_chunk($nids, self::CHUNK_SIZE) as $chunk) {
        $nodes = $this->nodeStorage->loadMultiple($chunk);
        foreach ($chunk as $nid) {
          if (isset($nodes[$nid])) {
            fputcsv($handle, ContentExportBuilder::getRow($nodes[$nid], $bundle));
          }
        }
        // Release the chunk so memory stays bounded on large content lists.
        $this->nodeStorage->resetCache($chunk);
      }
      fclose($handle);
    });

    $filename = $bundle . '-content-' . date('Y-m-d') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  /**
   * Resolves the node ids the Manage view returns for the current filters.
   *
   * Runs the view's built query to get just the ids — the view's filter and
   * sort logic without loading every entity — so the result can be streamed in
   * chunks.
   *
   * @param string $bundle
   *   The node bundle machine name.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return int[]
   *   The matching node ids, in the view's sort order, de-duplicated.
   */
  protected function filteredNids(string $bundle, Request $request): array {
    $view_id = self::BUNDLE_VIEW[$bundle] ?? NULL;
    $view = $view_id ? Views::getView($view_id) : NULL;
    if (!$view) {
      throw new NotFoundHttpException();
    }
    $view->setDisplay('page_1');

    // Replay the Manage page's exposed filters; the pager `page` is irrelevant
    // to an unpaged export.
    $query = $request->query->all();
    unset($query['page']);
    $view->setExposedInput($query);

    // Export every matching row, not just the on-screen page.
    $view->setItemsPerPage(0);

    $view->preExecute();
    $view->build();

    $select = $view->build_info['query'];
    $nids = array_column($select->execute()->fetchAll(), 'nid');
    $view->destroy();

    return array_unique(array_map('intval', $nids));
  }

}
