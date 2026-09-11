<?php

namespace Drupal\Tests\ys_mathjax\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\typogrify\Plugin\Filter\TypogrifyFilter;
use Drupal\ys_mathjax\Plugin\Filter\YsTypogrifyFilter;

/**
 * Unit tests for YsTypogrifyFilter.
 *
 * @coversDefaultClass \Drupal\ys_mathjax\Plugin\Filter\YsTypogrifyFilter
 * @group ys_mathjax
 * @group yalesites
 */
class YsTypogrifyFilterTest extends UnitTestCase {

  /**
   * Typogrify settings as stored by the YaleSites text formats.
   *
   * Mirrors the `typogrify` filter settings in filter.format.basic_html.yml,
   * filter.format.heading_html.yml and filter.format.restricted_html.yml,
   * which are identical to one another.
   */
  private const FORMAT_SETTINGS = [
    'smartypants_enabled' => 1,
    'smartypants_hyphens' => 2,
    'space_hyphens' => 0,
    'wrap_ampersand' => 0,
    'widont_enabled' => 0,
    'space_to_nbsp' => 1,
    'hyphenate_shy' => 0,
    'wrap_abbr' => 0,
    'wrap_caps' => 0,
    'wrap_initial_quotes' => 1,
    'wrap_numbers' => 0,
    'ligatures' => [],
    'arrows' => [],
    'fractions' => [],
    'quotes' => [',,' => ',,', "''" => "''"],
  ];

  /**
   * Builds a typogrify filter instance of the given class.
   *
   * @param string $class
   *   The filter class to instantiate.
   * @param array $overrides
   *   Settings to override on top of the stored format settings.
   *
   * @return \Drupal\typogrify\Plugin\Filter\TypogrifyFilter
   *   The configured filter.
   */
  private function filter(string $class, array $overrides = []): TypogrifyFilter {
    $settings = $overrides + self::FORMAT_SETTINGS;

    return new $class(
      ['settings' => $settings],
      'typogrify',
      [
        'id' => 'typogrify',
        'provider' => 'typogrify',
        'weight' => 10,
        'settings' => $settings,
      ]
    );
  }

  /**
   * Runs text through a typogrify filter instance of the given class.
   *
   * An explicit langcode keeps TypogrifyFilter from reaching for the language
   * manager service, which a unit test has no container for.
   *
   * @param string $class
   *   The filter class to instantiate.
   * @param string $text
   *   The text to process.
   *
   * @return string
   *   The processed text.
   */
  private function process(string $class, string $text): string {
    return $this->filter($class)->process($text, 'en')->getProcessedText();
  }

  /**
   * Tests that LaTeX inside math delimiters survives typogrify untouched.
   *
   * @param string $text
   *   Markup containing math notation.
   * @param string $fragment
   *   The LaTeX fragment that must survive verbatim.
   *
   * @dataProvider mathProvider
   * @covers ::process
   */
  public function testMathIsNotTypogrified(string $text, string $fragment): void {
    $this->assertStringContainsString($fragment, $this->process(YsTypogrifyFilter::class, $text));

    // Guard against passing for the wrong reason: the stock filter has to
    // actually destroy this fragment, otherwise the case proves nothing.
    $this->assertStringNotContainsString(
      $fragment,
      $this->process(TypogrifyFilter::class, $text),
      'Expected the unmodified typogrify filter to corrupt this fragment.'
    );
  }

  /**
   * Provides math markup and the LaTeX fragment that must survive.
   *
   * @return array
   *   Cases keyed by description: [text, fragment].
   */
  public static function mathProvider(): array {
    return [
      'bmatrix row separator' => [
        '<p>$$\begin{bmatrix} a &amp; b \\\\ c &amp; d \end{bmatrix}$$</p>',
        '\\\\',
      ],
      'pmatrix row separator' => [
        '<p>$$\begin{pmatrix} 1 &amp; 2 \\\\ 3 &amp; 4 \end{pmatrix}$$</p>',
        '\\\\',
      ],
      'aligned system of equations' => [
        '<p>\[\begin{aligned} x + y &amp;= 2 \\\\ x - y &amp;= 0 \end{aligned}\]</p>',
        '\\\\',
      ],
      'array row separator' => [
        '<p>$$\begin{array}{cc} a &amp; b \\\\ c &amp; d \end{array}$$</p>',
        '\\\\',
      ],
      'inline paren delimiters' => [
        '<p>The identity \(a \\\\ b\) holds.</p>',
        '\\\\',
      ],
      'thin space' => [
        '<p>$$\int_0^1 x\,dx$$</p>',
        '\,',
      ],
      'double prime' => [
        "<p>The second derivative \\(f''(x)\\) is positive.</p>",
        "f''(x)",
      ],
    ];
  }

  /**
   * Tests that text outside math delimiters is still typogrified.
   *
   * @covers ::process
   */
  public function testTypographyStillAppliedOutsideMath(): void {
    $processed = $this->process(
      YsTypogrifyFilter::class,
      '<p>She said "yes" -- and then $$a \\\\ b$$ appeared.</p>'
    );

    // Straight quotes and the double hyphen are still converted.
    $this->assertStringNotContainsString('"yes"', $processed);
    $this->assertStringNotContainsString(' -- ', $processed);
    // The math itself is untouched.
    $this->assertStringContainsString('$$a \\\\ b$$', $processed);
  }

  /**
   * Tests that math-free text is processed exactly as typogrify would.
   *
   * @param string $text
   *   Math-free markup.
   *
   * @dataProvider mathFreeProvider
   * @covers ::process
   */
  public function testMathFreeTextMatchesTypogrify(string $text): void {
    $this->assertSame(
      $this->process(TypogrifyFilter::class, $text),
      $this->process(YsTypogrifyFilter::class, $text)
    );
  }

  /**
   * Provides math-free markup that must round-trip identically.
   *
   * @return array
   *   Cases keyed by description: [text].
   */
  public static function mathFreeProvider(): array {
    return [
      'prose with quotes and dashes' => ['<p>He said "hello" -- twice.</p>'],
      'a single dollar amount' => ['<p>Tickets are $5 each.</p>'],
      'a path with backslashes' => ['<p>Open C:\\\\Users\\\\me now.</p>'],
      'an unbalanced display delimiter' => ['<p>The $$ sign is odd.</p>'],
      'an unbalanced inline delimiter' => ['<p>Half an expression \(x + y.</p>'],
      'empty markup' => ['<p></p>'],
    ];
  }

  /**
   * Tests that the placeholder survives every typogrify transformation.
   *
   * The stored formats leave the optional transformations off, but each is a
   * checkbox on the text format's settings form. If one of them can rewrite
   * the placeholder, the math it stands for is dropped on restore and the
   * reader sees the placeholder instead — worse than the bug being fixed. The
   * digit-grouping option is the one that bites: SmartyPants matches a bare
   * `\d+` with no word boundary.
   *
   * @param array $overrides
   *   Typogrify settings to switch on.
   *
   * @dataProvider aggressiveSettingsProvider
   * @covers ::process
   */
  public function testMathSurvivesEveryTypogrifyOption(array $overrides): void {
    $processed = $this->filter(YsTypogrifyFilter::class, $overrides)
      ->process('<p>Given $$\begin{bmatrix} a &amp; b \\\\ c &amp; d \end{bmatrix}$$ we proceed.</p>', 'en')
      ->getProcessedText();

    $this->assertStringContainsString('\\\\', $processed);
    $this->assertStringNotContainsString('ysmathmask', $processed);
  }

  /**
   * Provides each optional typogrify transformation, switched on.
   *
   * @return array
   *   Cases keyed by setting name: [overrides].
   */
  public static function aggressiveSettingsProvider(): array {
    return [
      'wrap_numbers span' => [['wrap_numbers' => 3]],
      'wrap_numbers just wrap' => [['wrap_numbers' => 4]],
      'wrap_caps' => [['wrap_caps' => 1]],
      'wrap_abbr' => [['wrap_abbr' => 3]],
      'widont' => [['widont_enabled' => 1]],
      'hyphenate_shy' => [['hyphenate_shy' => 1]],
      'space_hyphens' => [['space_hyphens' => 1]],
      'wrap_ampersand' => [['wrap_ampersand' => 1]],
      'everything at once' => [[
        'wrap_numbers' => 3,
        'wrap_caps' => 1,
        'wrap_abbr' => 3,
        'widont_enabled' => 1,
        'hyphenate_shy' => 1,
        'space_hyphens' => 1,
        'wrap_ampersand' => 1,
      ],
      ],
    ];
  }

  /**
   * Tests that the typogrify library is still attached.
   *
   * @covers ::process
   */
  public function testLibraryIsStillAttached(): void {
    $result = $this->filter(YsTypogrifyFilter::class)->process('<p>$$a \\\\ b$$</p>', 'en');

    $this->assertSame(['typogrify/typogrify'], $result->getAttachments()['library']);
  }

}
