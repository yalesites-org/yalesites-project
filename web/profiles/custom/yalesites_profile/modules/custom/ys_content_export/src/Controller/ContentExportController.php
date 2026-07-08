<?php

namespace Drupal\ys_content_export\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ys_content_export\ContentExportBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a CSV export of a content type's nodes for the Manage pages.
 */
class ContentExportController extends ControllerBase {

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
   * Builds a CSV download of the nodes of the given bundle.
   *
   * Access is enforced by the route permission ("yalesites manage settings"),
   * mirroring the Manage views; the query respects node access grants, so the
   * export lists the same content the requesting user sees in the view.
   *
   * @param string $bundle
   *   The node bundle machine name (supplied as a route default).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A CSV file download response.
   */
  public function export(string $bundle): Response {
    $nids = $this->nodeStorage->getQuery()
      ->condition('type', $bundle)
      ->accessCheck(TRUE)
      ->sort('title')
      ->execute();
    $nodes = $this->nodeStorage->loadMultiple($nids);

    $handle = fopen('php://temp', 'r+');
    // UTF-8 BOM so spreadsheet apps read accented characters correctly.
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, array_values(ContentExportBuilder::getColumns($bundle)));
    foreach ($nids as $nid) {
      if (isset($nodes[$nid])) {
        fputcsv($handle, ContentExportBuilder::getRow($nodes[$nid], $bundle));
      }
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $filename = $bundle . '-content-' . date('Y-m-d') . '.csv';
    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

}
