<?php

namespace Drupal\ys_content_export\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
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
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter, handed to the export builder for date columns.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
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
      $this->writeCsv($handle, $nids, $bundle, $columns);
      fclose($handle);
    });

    $filename = $bundle . '-content-' . date('Y-m-d') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  /**
   * Writes the BOM, header, data rows, and trailing summary row to a handle.
   *
   * A row that fails to build is replaced by a placeholder and logged, so one
   * bad node cannot end the download. Nodes the current user cannot view, or
   * that fail to load, are skipped. The summary row is written last, so a
   * stream that dies for any other reason visibly lacks it.
   *
   * @param resource $handle
   *   The open output stream.
   * @param int[] $nids
   *   The node ids to export, in order.
   * @param string $bundle
   *   The node bundle machine name.
   * @param string[] $columns
   *   The header labels.
   */
  protected function writeCsv($handle, array $nids, string $bundle, array $columns): void {
    // UTF-8 BOM so spreadsheet apps read accented characters correctly.
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $columns);
    $exported = $failed = 0;
    $skipped = [];
    $logger = $this->getLogger('ys_content_export');
    foreach (array_chunk($nids, self::CHUNK_SIZE) as $chunk) {
      try {
        $nodes = $this->nodeStorage->loadMultiple($chunk);
      }
      catch (\Throwable $e) {
        // Count the whole chunk as failed and carry on, so the summary row
        // reports the shortfall. Rethrowing would make the exception handler
        // print an error page into the CSV body and log the failure twice.
        $logger->error('Export failed, could not load nodes @nids: @message', [
          '@nids' => implode(', ', $chunk),
          '@message' => $e->getMessage(),
        ]);
        $failed += count($chunk);
        continue;
      }
      foreach ($chunk as $nid) {
        if (!isset($nodes[$nid])) {
          $skipped[] = $nid;
          continue;
        }
        // The access check is inside the try so an access hook that throws
        // becomes a placeholder row rather than a cut-off file.
        try {
          if (!$nodes[$nid]->access('view')) {
            $skipped[] = $nid;
            continue;
          }
          fputcsv($handle, ContentExportBuilder::getRow($nodes[$nid], $bundle, $this->dateFormatter));
          $exported++;
        }
        catch (\Throwable $e) {
          $failed++;
          $logger->error('Export failed for node @nid: @message', [
            '@nid' => $nid,
            '@message' => $e->getMessage(),
          ]);
          $placeholder = array_fill(0, count($columns), '');
          $placeholder[0] = 'Export failed for node ' . $nid;
          fputcsv($handle, $placeholder);
        }
      }
      // Release the chunk so memory stays bounded on large content lists.
      $this->nodeStorage->resetCache($chunk);
    }
    if ($skipped) {
      $logger->notice('Export skipped @count nodes (no access or not found): @nids', [
        '@count' => count($skipped),
        '@nids' => implode(', ', $skipped),
      ]);
    }
    fputcsv($handle, [sprintf('Export complete: %d of %d rows exported (%d failed, %d skipped)', $exported, count($nids), $failed, count($skipped))]);
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
