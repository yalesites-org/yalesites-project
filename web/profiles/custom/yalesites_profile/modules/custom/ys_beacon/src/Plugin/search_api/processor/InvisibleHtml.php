<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Processor\FieldsProcessorPluginBase;

/**
 * Removes markup that is never page content, together with its text.
 *
 * Beacon's rendered_item field used to run through Search API's html_filter,
 * which flattened every paragraph, heading, list and link into one run-on line
 * before the AI indexer saw it, so the chat widget's Citations panel could only
 * ever show unstructured text. Removing that processor from the field restores
 * the structure - ai_search converts the HTML to Markdown - but it also gives
 * up the one useful thing html_filter was doing here:
 * removeInvisibleHtmlElements(), which deleted script, style, noscript, svg and
 * friends before anything else ran.
 *
 * That deletion is load-bearing, because ai_search's converter has
 * strip_tags enabled (EmbeddingStrategyPluginBase::__construct) and therefore
 * flattens an element it cannot express in Markdown down to its *text*. A
 * <script> body is dropped as a tag and kept as prose: measured on this repo,
 * an analytics snippet is stored as
 * "window.dataLayer=[];function gtag(){...}", and a <style> block as
 * ".embed__inner{color:red;padding:16px}". Those strings then become part of
 * the embedding vector and part of the excerpt quoted back to the visitor.
 *
 * So this processor does exactly the one job, and no more: the element list is
 * html_filter's own, plus "object" and "template", which it omits. Wrapper
 * markup and presentation attributes need no handling here - the converter's
 * strip_tags already discards them - and nothing narrows the surviving tags,
 * so ai_search's TableConverter still turns an editor's table into a Markdown
 * table rather than a run of concatenated cells.
 *
 * Query preprocessing is deliberately not declared as a supported stage: a
 * visitor's question is not HTML and has no business being parsed as a
 * document.
 *
 * @see \Drupal\search_api\Plugin\search_api\processor\HtmlFilter::removeInvisibleHtmlElements()
 * @see \Drupal\ai_search\Base\EmbeddingStrategyPluginBase::__construct()
 */
#[SearchApiProcessor(
  id: 'ys_beacon_invisible_html',
  label: new TranslatableMarkup('Beacon invisible HTML removal'),
  description: new TranslatableMarkup('Removes script, style and other non-content elements, along with the text inside them, before content is embedded.'),
  stages: [
    'pre_index_save' => 0,
    'preprocess_index' => -49,
  ],
)]
class InvisibleHtml extends FieldsProcessorPluginBase {

  /**
   * Elements removed together with their contents.
   *
   * @see \Drupal\search_api\Plugin\search_api\processor\HtmlFilter::removeInvisibleHtmlElements()
   */
  protected const REMOVED_ELEMENTS = [
    'applet', 'audio', 'canvas', 'command', 'embed', 'iframe', 'map', 'menu',
    'noembed', 'noframes', 'noscript', 'object', 'script', 'style', 'svg',
    'template', 'video',
  ];

  /**
   * {@inheritdoc}
   */
  protected function process(&$value) {
    if (!is_string($value) || !preg_match($this->detectionPattern(), $value)) {
      return;
    }

    // Parse rather than pattern-match the removal itself: an element's contents
    // can hold anything, including markup that looks like its own closing tag.
    $document = Html::load($value);
    $xpath = new \DOMXPath($document);
    foreach ($xpath->query('//' . implode('|//', static::REMOVED_ELEMENTS)) as $node) {
      $node->parentNode?->removeChild($node);
    }
    $value = trim(Html::serialize($document));
  }

  /**
   * Builds the "is any of these elements present?" pre-check pattern.
   *
   * The pattern only decides whether to parse, so it must never disagree with
   * the parser about where a tag name ends - a stricter pre-check would skip
   * parsing on markup the parser still turns into a live element, and the
   * element's text would be indexed. HTML5 ends a tag name at anything outside
   * ":_-", digits and letters (Tokenizer::tagName()), so the terminator here is
   * the complement of that set rather than a list of the plausible characters:
   * <script"x"> and <script=1> are both real script elements.
   *
   * @return string
   *   A PCRE pattern.
   */
  protected function detectionPattern(): string {
    return '#<(?:' . implode('|', static::REMOVED_ELEMENTS) . ')(?![:_\-0-9a-z])#i';
  }

}
