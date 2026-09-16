<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_beacon\Service\PdfTextIndexer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Excludes content that does not belong in the Beacon vector index.
 *
 * Applies the shared Beacon indexability rule: unpublished content, content
 * anonymous visitors cannot view, and content marked with the
 * ai_disable_indexing metatag are all dropped from indexing. When an
 * already-indexed entity stops being indexable and is saved, Search API
 * re-tracks it, this processor drops it, and the AI Search backend removes
 * its chunks from the vector database.
 *
 * A PDF document with no extracted text is dropped for the same reason: its
 * only remaining body is the rendered file link, so it would be embedded as a
 * filename-only chunk that matches filename-shaped queries and cites a
 * document with nothing behind it. Once the text is stored the media save
 * re-tracks the item and it is indexed normally.
 */
#[SearchApiProcessor(
  id: 'ys_beacon_exclude_ai_disabled',
  label: new TranslatableMarkup('Beacon AI indexing exclusion'),
  description: new TranslatableMarkup('Excludes unpublished, access-restricted, and AI-disabled content, and documents with no extracted text, from the Beacon index.'),
  stages: [
    'alter_items' => 0,
  ],
)]
class ExcludeAiDisabled extends ProcessorPluginBase {

  /**
   * The Beacon indexability service.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexability
   */
  protected BeaconIndexability $indexability;

  /**
   * The PDF text indexer.
   *
   * @var \Drupal\ys_beacon\Service\PdfTextIndexer
   */
  protected PdfTextIndexer $pdfTextIndexer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->indexability = $container->get('ys_beacon.indexability');
    $processor->pdfTextIndexer = $container->get('ys_beacon.pdf_text_indexer');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    foreach ($items as $item_id => $item) {
      $entity = $item->getOriginalObject()?->getValue();
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      if (!$this->indexability->isIndexable($entity)) {
        unset($items[$item_id]);
        continue;
      }
      // A PDF still waiting on extraction has nothing but its filename to
      // contribute; leave it out until its text lands.
      if ($entity instanceof MediaInterface && $this->pdfTextIndexer->lacksExtractedText($entity)) {
        unset($items[$item_id]);
      }
    }
  }

}
