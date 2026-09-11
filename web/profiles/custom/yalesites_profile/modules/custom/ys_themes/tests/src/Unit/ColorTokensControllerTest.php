<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_themes\ColorTokenResolver;
use Drupal\ys_themes\Controller\ColorTokensController;

/**
 * Unit tests for the ColorTokensController.
 *
 * @coversDefaultClass \Drupal\ys_themes\Controller\ColorTokensController
 * @group ys_themes
 * @group yalesites
 */
class ColorTokensControllerTest extends UnitTestCase {

  /**
   * The mocked color token resolver.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $colorTokenResolver;

  /**
   * The controller under test.
   *
   * @var \Drupal\ys_themes\Controller\ColorTokensController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->colorTokenResolver = $this->createMock(ColorTokenResolver::class);
    $this->controller = new ColorTokensController($this->colorTokenResolver);

    // $this->t() returns a TranslatableMarkup object; it's only actually
    // translated -- via translateString(), not translate() -- when cast to
    // a string, which colorTable() does by concatenating it into markup.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(function (TranslatableMarkup $markup) {
      return $markup->getUntranslatedString();
    });
    $this->controller->setStringTranslation($translation);
  }

  /**
   * ColorTable() shows a "no tokens" message when the resolver has nothing.
   *
   * @covers ::colorTable
   */
  public function testColorTableShowsMessageWhenNoTokens(): void {
    $this->colorTokenResolver->method('getGlobalThemeColors')->willReturn([]);

    $build = $this->controller->colorTable();

    $this->assertSame('<p>No color tokens found.</p>', $build['#markup']);
  }

  /**
   * Tests color table sorts slots and builds rows.
   *
   * ColorTable() sorts a theme's slots numerically (not by insertion order),
   * builds one row per slot, and falls back to a computed CSS variable name
   * when the resolver data doesn't include one.
   *
   * @covers ::colorTable
   * @covers ::wordToNumber
   */
  public function testColorTableSortsSlotsAndBuildsRows(): void {
    $this->colorTokenResolver->method('getGlobalThemeColors')->willReturn([
      'one' => [
        'label' => 'Old Blues',
        'colors' => [
          // Inserted out of numeric order to exercise the uksort.
          'slot-three' => [
            'hex' => '#111111',
            'name' => 'Slot Three',
            'token' => 'slot-three',
            'css_var' => '--global-themes-one-colors-slot-three',
          ],
          'slot-one' => [
            'hex' => '#00366b',
            'name' => 'Blue Yale',
            'token' => 'slot-one',
            // No css_var key: exercises the fallback computation.
          ],
        ],
      ],
    ]);

    $build = $this->controller->colorTable();

    $this->assertSame('table', $build['#type']);
    $this->assertCount(7, $build['#header']);
    $this->assertSame(['color-tokens-table'], $build['#attributes']['class']);
    $this->assertSame(['ys_themes/color_tokens'], $build['#attached']['library']);

    $rows = $build['#rows'];
    $this->assertCount(2, $rows);

    // slot-one sorts before slot-three despite being inserted second.
    $first_row = $rows[0]['data'];
    $this->assertSame('Old Blues', $first_row[0]['data']);
    $this->assertSame('One', $first_row[1]['data']);
    $this->assertSame('Blue Yale', $first_row[2]['data']);
    $this->assertSame('#00366b', $first_row[4]['data']);
    // Fallback CSS variable name, computed since none was provided.
    $this->assertSame('--global-themes-one-colors-slot-one', $first_row[5]['data']);
    $this->assertSame('slot-one', $first_row[6]['data']);

    $second_row = $rows[1]['data'];
    $this->assertSame('Three', $second_row[1]['data']);
    $this->assertSame('--global-themes-one-colors-slot-three', $second_row[5]['data']);

    // The swatch is a render array styled with the resolved hex.
    $swatch = $first_row[3]['data'];
    $this->assertSame('html_tag', $swatch['#type']);
    $this->assertStringContainsString('#00366b', $swatch['#attributes']['style']);
  }

  /**
   * Tests word to number maps words to integers.
   *
   * WordToNumber() maps known word-numbers case-insensitively and falls back
   * to 999 for anything else.
   *
   * @covers ::wordToNumber
   *
   * @dataProvider wordToNumberProvider
   */
  public function testWordToNumberMapsWordsToIntegers(string $word, int $expected): void {
    $reflection = new \ReflectionMethod($this->controller, 'wordToNumber');
    $reflection->setAccessible(TRUE);
    $this->assertSame($expected, $reflection->invoke($this->controller, $word));
  }

  /**
   * Data provider for testWordToNumberMapsWordsToIntegers().
   */
  public static function wordToNumberProvider(): array {
    return [
      ['one', 1],
      ['two', 2],
      ['three', 3],
      ['four', 4],
      ['five', 5],
      ['six', 6],
      ['seven', 7],
      ['eight', 8],
      ['nine', 9],
      ['ten', 10],
      // Case-insensitive.
      ['One', 1],
      ['SEVEN', 7],
      // Unknown words fall back to a high sort value.
      ['eleven', 999],
      ['', 999],
    ];
  }

}
