<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\FieldsProcessorPluginBase;
use Drupal\ys_beacon\Service\EntityCitationResolver;
use Drupal\ys_beacon\Service\IndexableHtmlFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Keeps content markup in indexed fields so Markdown reaches the model.
 *
 * Replaces Search API's html_filter on the Beacon index. html_filter stripped
 * every tag, which left ai_search's HTML-to-Markdown conversion nothing to
 * convert: headings, lists, emphasis and links were gone by the time a chunk
 * was embedded, and adjacent list items and table cells were run together into
 * single words. This processor removes only what is not content and leaves the
 * rest for that conversion to render as Markdown.
 *
 * html_filter's per-tag boosts are not carried over. The Beacon index is served
 * by a vector database rather than keyword search, so relevance comes from the
 * embedding and a boost on <h1> never affected retrieval.
 *
 * Which fields this runs on stays a configuration choice, exactly as it was for
 * html_filter, so a future Beacon field needs a config change and no code.
 */
#[SearchApiProcessor(
  id: 'ys_beacon_indexable_html',
  label: new TranslatableMarkup('Beacon indexable HTML'),
  description: new TranslatableMarkup('Removes non-content markup instead of stripping all tags, so headings, lists, tables, emphasis and links reach the language model as Markdown.'),
  stages: [
    'pre_index_save' => 0,
    'preprocess_index' => -15,
  ],
)]
class IndexableHtml extends FieldsProcessorPluginBase {

  /**
   * The indexable HTML filter.
   *
   * @var \Drupal\ys_beacon\Service\IndexableHtmlFilter
   */
  protected IndexableHtmlFilter $indexableHtmlFilter;

  /**
   * The citation URL resolver.
   *
   * @var \Drupal\ys_beacon\Service\EntityCitationResolver
   */
  protected EntityCitationResolver $citationResolver;

  /**
   * Absolute URL of the item being processed, or NULL when there is none.
   *
   * @var string|null
   */
  protected ?string $currentItemUrl = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->indexableHtmlFilter = $container->get('ys_beacon.indexable_html_filter');
    $processor->citationResolver = $container->get('ys_beacon.entity_citation_resolver');

    return $processor;
  }

  /**
   * {@inheritdoc}
   *
   * Overridden only to make the item's own URL reachable from process(). The
   * base class walks an item's fields and hands each value down as a bare
   * string, but the filter needs the page a value was rendered from before it
   * can turn an anchored heading into a deep link. Delegating one item at a
   * time is the narrowest way to supply that without reimplementing the walk.
   */
  public function preprocessIndexItems(array $items) {
    foreach ($items as $item) {
      $this->currentItemUrl = $this->resolveItemUrl($item);
      try {
        parent::preprocessIndexItems([$item]);
      }
      finally {
        // Cleared so the same instance cannot carry one item's URL into
        // another item, or into the search-query path, which shares process().
        $this->currentItemUrl = NULL;
      }
    }
  }

  /**
   * Resolves the absolute URL of the page an item was rendered from.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item being indexed.
   *
   * @return string|null
   *   An absolute URL, or NULL when the item has none to offer.
   */
  protected function resolveItemUrl(ItemInterface $item): ?string {
    try {
      $entity = $item->getOriginalObject()?->getValue();
    }
    catch (\Throwable $e) {
      // Deep links are an enhancement to a chunk that indexes fine without
      // them, so an item whose original object cannot be loaded is indexed
      // unlinked rather than failing the batch it arrived in.
      return NULL;
    }

    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }

    $url = $this->citationResolver->url($entity);

    // The filter's contract is an absolute target: a relative one reaching a
    // chat answer would resolve against the chat page, not the source page.
    // EntityCitationResolver can return a relative file URL for some media.
    return $url !== NULL && preg_match('#^https?://#i', $url) === 1 ? $url : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function process(&$value) {
    $value = $this->indexableHtmlFilter->filter((string) $value, $this->currentItemUrl);
  }

}
