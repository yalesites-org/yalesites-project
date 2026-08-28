<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Processor\FieldsProcessorPluginBase;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->indexableHtmlFilter = $container->get('ys_beacon.indexable_html_filter');

    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  protected function process(&$value) {
    $value = $this->indexableHtmlFilter->filter((string) $value);
  }

}
