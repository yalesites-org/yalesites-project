<?php

namespace Drupal\ys_beacon\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\ys_beacon\Service\PdfTextIndexer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts text from an uploaded PDF in the background.
 *
 * Queued on media save so a large PDF never slows the editorial save; the
 * worker runs on cron.
 *
 * @QueueWorker(
 *   id = "ys_beacon_pdf_text_extraction",
 *   title = @Translation("Beacon PDF text extraction"),
 *   cron = {"time" = 60}
 * )
 */
class PdfTextExtraction extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The PDF text indexer.
   *
   * @var \Drupal\ys_beacon\Service\PdfTextIndexer
   */
  protected PdfTextIndexer $indexer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->indexer = $container->get('ys_beacon.pdf_text_indexer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (isset($data['media_id'])) {
      $this->indexer->extractAndStore($data['media_id']);
    }
  }

}
