<?php

namespace Drupal\ys_mathjax;

/**
 * Detects whether a string contains MathJax math notation.
 *
 * Used to decide, per rendered field, whether the (large) MathJax library
 * needs to be attached to the page, so it loads only where math is actually
 * present rather than on every page that renders the text format.
 */
class MathDelimiterDetector {

  /**
   * Matches an opening math delimiter or a MathML root element.
   *
   * Delimiters mirror the YaleSites MathJax configuration: `\(` for inline
   * math and `$$` / `\[` for display math. Single-dollar inline math is
   * deliberately excluded so ordinary prose such as "$5" is never treated as
   * math. The check is a cheap heuristic: over-detection only loads MathJax on
   * a page that turns out to have no renderable math (harmless), while
   * under-detection would leave real math unrendered.
   */
  const MATH_PATTERN = '/\\\\[([]|\$\$|<math[\s>\/]/i';

  /**
   * Checks whether the given text contains math notation.
   *
   * @param string $text
   *   The (rendered) field text to inspect.
   *
   * @return bool
   *   TRUE if an opening math delimiter or a MathML element is present.
   */
  public static function hasMath(string $text): bool {
    return (bool) preg_match(self::MATH_PATTERN, $text);
  }

}
