<?php

namespace Drupal\ys_mathjax\Plugin\Filter;

use Drupal\typogrify\Plugin\Filter\TypogrifyFilter;
use Drupal\ys_mathjax\MathDelimiterDetector;

/**
 * Applies typographic refinements to everything except math notation.
 *
 * Typogrify rewrites the punctuation that LaTeX is built from: SmartyPants
 * turns `\\` (end of row) into `&#92;` and `\,` (thin space) into a comma, and
 * the configured quote conversions turn `''` (double prime) into a right
 * double quote. MathJax runs in the browser, long after every text filter, so
 * it never sees the original notation and multi-row constructs such as
 * `bmatrix` collapse onto one line.
 *
 * Reordering filters cannot fix this: the MathJax filter only wraps the text
 * and attaches the library, so the LaTeX still reaches typogrify as plain text
 * whichever order they run in. Instead this subclass masks each math region
 * with an inert placeholder, lets typogrify do its real work on the
 * surrounding prose, and restores the math verbatim. It replaces the contrib
 * typogrify filter for every text format via ys_mathjax_filter_info_alter().
 *
 * Two consequences worth knowing:
 * - Masking restores exactly the bytes typogrify was handed, which have already
 *   been through filter_html at weight -10 in every format that runs typogrify,
 *   so it cannot reintroduce anything sanitisation removed. A future format
 *   that ran typogrify *without* filter_html would not have that guarantee.
 * - A pair of stray delimiters (two lone `$$`, say) masks the prose between
 *   them, which then skips typographic replacement. The text is restored
 *   verbatim, so nothing is corrupted, and MathJax would treat that span as
 *   math on the client for the same reason.
 *
 * @see \Drupal\ys_mathjax\MathDelimiterDetector
 */
class YsTypogrifyFilter extends TypogrifyFilter {

  /**
   * Builds the placeholder that stands in for the nth math region.
   *
   * Lowercase letters only. Typogrify rewrites punctuation, backslashes,
   * quotes and runs of capitals, so those are all out — and so are digits:
   * SmartyPants::smartNumbers() matches a bare `\d+` with no word boundary,
   * so with the format's "Digit grouping in numbers" setting turned on it
   * would wrap the index in `<span class="number">`, split the placeholder,
   * and lose the math entirely on restore. Hence the index is spelled with
   * letters rather than interpolated as a number.
   *
   * @param int $index
   *   Zero-based position of the math region within the text.
   *
   * @return string
   *   A token that survives every typogrify transformation unchanged.
   */
  private static function placeholder(int $index): string {
    return 'ysmathmask' . strtr((string) $index, '0123456789', 'abcdefghij') . 'ysmathmask';
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $math = [];
    $masked = preg_replace_callback(
      MathDelimiterDetector::MATH_REGION_PATTERN,
      function (array $match) use (&$math): string {
        $placeholder = self::placeholder(count($math));
        $math[$placeholder] = $match[0];
        return $placeholder;
      },
      $text
    );

    // Losing the field's whole content to a PCRE limit would be far worse than
    // losing the row separator, so fall back to unmasked processing.
    if ($masked === NULL) {
      return parent::process($text, $langcode);
    }

    $result = parent::process($masked, $langcode);

    if ($math) {
      $result->setProcessedText(strtr($result->getProcessedText(), $math));
    }

    return $result;
  }

}
