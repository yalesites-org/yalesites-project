<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\search_api\processor\InvisibleHtml;

/**
 * Proves script and style bodies never become part of an indexed chunk.
 *
 * With html_filter no longer applied to Beacon's rendered_item, ai_search's
 * converter is what turns the rendered HTML into the stored chunk - and its
 * strip_tags option flattens an element it cannot express in Markdown down to
 * the *text* inside it. So without this processor an analytics snippet or a CSS
 * rule is stored as prose, embedded into the vector, and quoted back in the
 * Citations panel (yalesites-org/YaleSites-Internal#1534). Structural markup is
 * deliberately left alone here: restoring it is the point of that issue, and
 * the converter handles wrappers and attributes on its own.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\search_api\processor\InvisibleHtml
 */
class InvisibleHtmlProcessorTest extends UnitTestCase {

  /**
   * Non-content elements go, and the text inside them goes with them.
   *
   * @dataProvider providerInvisibleElements
   *
   * @covers ::process
   */
  public function testInvisibleElementsAreRemovedWithTheirText(string $html, string $leak): void {
    $cleaned = $this->process($html);

    $this->assertStringNotContainsString($leak, $cleaned);
    $this->assertStringContainsString('Visit us.', $cleaned, 'Real page content is untouched.');
  }

  /**
   * Non-content elements and the text each one would otherwise leak.
   */
  public static function providerInvisibleElements(): array {
    return [
      'script body' => [
        '<p>Visit us.</p><script>window.dataLayer=[];gtag("js");</script>',
        'dataLayer',
      ],
      'style body' => [
        '<p>Visit us.</p><style>.embed__inner{color:red}</style>',
        'color:red',
      ],
      'noscript body' => [
        '<p>Visit us.</p><noscript><p>Enable JavaScript.</p></noscript>',
        'Enable JavaScript',
      ],
      'svg title' => [
        '<p>Visit us.</p><svg><title>decorative chevron</title></svg>',
        'decorative chevron',
      ],
      'template body' => [
        '<p>Visit us.</p><template><p>hidden draft</p></template>',
        'hidden draft',
      ],
      'iframe' => [
        '<p>Visit us.</p><iframe src="https://www.instagram.com/embed"></iframe>',
        'instagram',
      ],
      'object' => [
        '<p>Visit us.</p><object data="movie.swf">fallback copy</object>',
        'fallback copy',
      ],
      'unclosed script swallowing the page' => [
        '<p>Visit us.</p><script>var s = "</script>";</script>',
        'var s',
      ],
      // The HTML5 parser ends a tag name at any character outside ":_-",
      // digits and letters, so each of these is a live element even though the
      // character after the name is not whitespace, ">" or "/". A pre-check
      // that only looked for those three would skip the removal entirely and
      // index the body as prose.
      'script with a quote after the name' => [
        '<p>Visit us.</p><script"x">window.dataLayer=[];</script>',
        'dataLayer',
      ],
      'script with an equals after the name' => [
        '<p>Visit us.</p><script=1>window.dataLayer=[];</script>',
        'dataLayer',
      ],
      'style with quotes after the name' => [
        '<p>Visit us.</p><style"">.x{color:red}</style>',
        'color:red',
      ],
    ];
  }

  /**
   * An element name appearing in prose is not mistaken for a tag.
   *
   * @covers ::process
   */
  public function testElementNameInsideWordIsNotTreatedAsMarkup(): void {
    $html = '<p>Read the scripture and the mapping guide.</p>';

    $this->assertSame($html, $this->process($html));
  }

  /**
   * Structural markup survives: restoring it is the point of the change.
   *
   * @covers ::process
   */
  public function testStructuralMarkupIsLeftAlone(): void {
    $html = '<div class="layout"><h2>Deadlines</h2>'
      . '<p>Read the <a href="/guide">guide</a> by <strong>March 1</strong>.</p>'
      . '<ul><li>one</li></ul>'
      . '<table><tr><th>Term</th><td>Fall</td></tr></table></div>';

    // Verbatim, including the wrapper: ai_search's converter flattens wrappers
    // and attributes, and its TableConverter needs the table markup intact.
    $this->assertSame($html, $this->process($html));
  }

  /**
   * A value with nothing to remove is returned untouched, not re-serialized.
   *
   * @covers ::process
   */
  public function testValueWithoutInvisibleElementsIsNotRewritten(): void {
    $html = '  <p>Already clean, oddly indented.</p>  ';

    $this->assertSame($html, $this->process($html));
  }

  /**
   * Runs the processor's value seam over one string.
   *
   * Which field the seam is reached for is Search API's own concern
   * (FieldsProcessorPluginBase::testField() against the configured "fields"
   * list); this covers what the processor itself does to a value.
   */
  private function process(string $html): string {
    $processor = new class([], 'ys_beacon_invisible_html', []) extends InvisibleHtml {

      /**
       * Exposes the protected value seam for testing.
       */
      public function processValue(string $value): string {
        $this->process($value);
        return $value;
      }

    };

    return $processor->processValue($html);
  }

}
