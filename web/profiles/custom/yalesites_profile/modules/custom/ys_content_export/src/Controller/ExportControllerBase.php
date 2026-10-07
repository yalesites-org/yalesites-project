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
 * Shared plumbing for the admin-list CSV exports.
 *
 * Both exports answer the same question — "give me the rows I am looking at,
 * as a spreadsheet" — so the two halves of that answer live here: resolving an
 * admin view's filtered entity ids, and streaming those entities to a CSV
 * download in bounded-memory chunks. A subclass supplies only what differs:
 * which view and entity type, the column headers, and how one entity becomes
 * one row.
 */
abstract class ExportControllerBase extends ControllerBase {

  /**
   * How many entities to load and write per batch.
   *
   * Bounds memory on large exports: entities are loaded, written, and released
   * one chunk at a time rather than all at once.
   */
  const CHUNK_SIZE = 50;

  /**
   * The admin views' page display id.
   *
   * Every list this module exports from is a single-page view.
   */
  const DISPLAY_ID = 'page_1';

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
   *   The date formatter, handed to the export builders for date columns.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->entityTypeManager = $entity_type_manager;
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
   * Resolves the entity ids an admin view returns for the current filters.
   *
   * Runs the view's built query to get just the ids — the view's filter and
   * sort logic without loading every entity — so the result can be streamed in
   * chunks.
   *
   * @param string $view_id
   *   The admin view's machine name.
   * @param string $id_column
   *   The base field the view selects ids into ("nid", "uid", …).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, carrying the admin page's exposed-filter query.
   *
   * @return int[]
   *   The matching entity ids, in the view's sort order, de-duplicated.
   */
  protected function filteredIds(string $view_id, string $id_column, Request $request): array {
    $view = Views::getView($view_id);
    if (!$view) {
      throw new NotFoundHttpException();
    }
    $view->setDisplay(static::DISPLAY_ID);

    // Replay the admin page's exposed filters; the pager `page` is irrelevant
    // to an unpaged export.
    $query = $request->query->all();
    unset($query['page']);
    $view->setExposedInput($query);

    // Export every matching row, not just the on-screen page.
    $view->setItemsPerPage(0);

    $view->preExecute();
    $view->build();

    $select = $view->build_info['query'];
    $ids = array_column($select->execute()->fetchAll(), $id_column);
    $view->destroy();

    // A view that joins a multi-value table (roles, taxonomy) can return the
    // same entity on several rows; the export wants one row per entity.
    return array_unique(array_map('intval', $ids));
  }

  /**
   * Streams the given entities to a CSV file download.
   *
   * @param string $entity_type_id
   *   The entity type to load the ids from.
   * @param int[] $ids
   *   The entity ids to export, in the order they should appear.
   * @param string[] $columns
   *   The ordered column headers.
   * @param callable $build_row
   *   Given one entity, returns its sanitised row values in column order.
   * @param string $filename
   *   The downloaded file's name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A streamed CSV file download response.
   */
  protected function streamCsv(string $entity_type_id, array $ids, array $columns, callable $build_row, string $filename): Response {
    $storage = $this->entityTypeManager()->getStorage($entity_type_id);

    $response = new StreamedResponse(function () use ($storage, $ids, $columns, $build_row) {
      $handle = fopen('php://output', 'w');
      // UTF-8 BOM so spreadsheet apps read accented characters correctly.
      fwrite($handle, "\xEF\xBB\xBF");
      ContentExportBuilder::writeRow($handle, $columns);
      foreach (array_chunk($ids, static::CHUNK_SIZE) as $chunk) {
        $entities = $storage->loadMultiple($chunk);
        foreach ($chunk as $id) {
          if (isset($entities[$id])) {
            ContentExportBuilder::writeRow($handle, $build_row($entities[$id]));
          }
        }
        // Release the chunk so memory stays bounded on large lists.
        $storage->resetCache($chunk);
      }
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  /**
   * Builds a dated export filename.
   *
   * @param string $prefix
   *   The leading part of the name, identifying what was exported.
   *
   * @return string
   *   The filename, e.g. "people-2026-10-02.csv".
   */
  protected function filename(string $prefix): string {
    return $prefix . '-' . date('Y-m-d') . '.csv';
  }

}
