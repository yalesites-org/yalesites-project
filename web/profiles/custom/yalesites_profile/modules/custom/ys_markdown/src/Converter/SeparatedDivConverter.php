<?php

namespace Drupal\ys_markdown\Converter;

use League\HTMLToMarkdown\Converter\DivConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Keeps a div apart from whatever content comes before it.
 *
 * The stock converter only ends a div with a blank line, so text or an inline
 * element that precedes the div (a label, say) runs into the div's content.
 * Any non-whitespace earlier sibling triggers a separator: a blank line, or a
 * single space inside a link, list item or table cell, where a line break
 * would split the link, list or row.
 */
class SeparatedDivConverter extends DivConverter {

  /**
   * {@inheritdoc}
   */
  public function convert(ElementInterface $element): string {
    // Walk the live siblings: an element's cached previous sibling goes stale
    // once the converter has replaced that sibling with its Markdown.
    $previous = NULL;
    foreach ($element->getParent()?->getChildren() ?? [] as $sibling) {
      if ($sibling->equals($element)) {
        break;
      }
      $previous = $sibling->isWhitespace() ? $previous : $sibling;
    }
    $markdown = parent::convert($element);
    if ($previous === NULL) {
      return $markdown;
    }
    return ($element->isDescendantOf(['a', 'li', 'td', 'th']) ? ' ' : "\n\n") . $markdown;
  }

}
