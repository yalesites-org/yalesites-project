<?php

namespace Drupal\ys_mathjax\Plugin\Filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\mathjax\Plugin\Filter\MathjaxFilter;
use Drupal\ys_mathjax\MathDelimiterDetector;

/**
 * Renders math with MathJax, attaching the library only when math is present.
 *
 * Extends the contrib MathJax filter so the large MathJax library is attached
 * only to pages whose content actually contains math notation, instead of on
 * every page that renders this text format (the WYSIWYG Text block is used on
 * nearly every page). When no math is found the text is returned unchanged and
 * no library is attached.
 */
#[Filter(
  id: 'filter_ys_mathjax',
  title: new TranslatableMarkup('YaleSites MathJax (conditional)'),
  description: new TranslatableMarkup('Renders math inside the configured delimiters with MathJax, loading the library only on pages that contain math.'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: 100,
)]
class YsMathjaxFilter extends MathjaxFilter {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    if (!MathDelimiterDetector::hasMath($text)) {
      return new FilterProcessResult($text);
    }
    return parent::process($text, $langcode);
  }

}
