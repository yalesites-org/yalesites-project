<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_themes\ColorTokenResolver;
use Drupal\ys_themes\ThemeSettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ColorTokenResolver service.
 *
 * Token data is never read from the real npm-installed dist/tokens.json;
 * instead the protected jsonPath property is pointed at a fixture under
 * tests/fixtures/ via reflection, so these tests are independent of whether
 * the atomic theme's node_modules have been installed.
 *
 * @coversDefaultClass \Drupal\ys_themes\ColorTokenResolver
 * @group ys_themes
 * @group yalesites
 */
class ColorTokenResolverTest extends UnitTestCase {

  /**
   * Path to the representative fixture tokens.json.
   *
   * @var string
   */
  protected string $fixturePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixturePath = __DIR__ . '/../../fixtures/tokens-fixture.json';
  }

  /**
   * Builds a ColorTokenResolver with mocked dependencies.
   *
   * @param string|null $json_path
   *   If given, overrides the resolved jsonPath via reflection.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Logger to use; a permissive mock is created if omitted.
   * @param string $global_theme_setting
   *   The value ThemeSettingsManager::getSetting('global_theme') returns.
   *
   * @return \Drupal\ys_themes\ColorTokenResolver
   *   The resolver under test.
   */
  protected function createResolver(?string $json_path = NULL, ?LoggerInterface $logger = NULL, string $global_theme_setting = 'one'): ColorTokenResolver {
    $logger = $logger ?? $this->createMock(LoggerInterface::class);

    $theme_extension_list = $this->createMock(ThemeExtensionList::class);
    // A path that never resolves to a real file, so the constructor's own
    // file lookup doesn't depend on whether node_modules is installed.
    $theme_extension_list->method('getPath')->with('atomic')->willReturn('nonexistent-atomic-theme-path');

    $theme_settings_manager = $this->createMock(ThemeSettingsManager::class);
    $theme_settings_manager->method('getSetting')->willReturn($global_theme_setting);

    $renderer = $this->createMock(RendererInterface::class);

    $resolver = new ColorTokenResolver($logger, $theme_extension_list, $theme_settings_manager, $renderer);

    if ($json_path !== NULL) {
      $property = new \ReflectionProperty(ColorTokenResolver::class, 'jsonPath');
      $property->setAccessible(TRUE);
      $property->setValue($resolver, $json_path);
    }

    return $resolver;
  }

  /**
   * Invokes a protected/private method via reflection.
   */
  protected function invokeMethod(object $object, string $method, array $args = []) {
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $args);
  }

  /**
   * Falls back to the default theme path when the extension list throws.
   *
   * FindTokenFiles() logs a warning for both the fallback and the (still
   * missing) file.
   *
   * @covers ::__construct
   */
  public function testConstructFallsBackWhenThemeExtensionListThrows(): void {
    $warnings = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(function ($message, $context = []) use (&$warnings) {
      $warnings[] = [$message, $context];
    });

    $theme_extension_list = $this->createMock(ThemeExtensionList::class);
    $theme_extension_list->method('getPath')->willThrowException(new \Exception('boom'));

    $theme_settings_manager = $this->createMock(ThemeSettingsManager::class);
    $renderer = $this->createMock(RendererInterface::class);

    $resolver = new ColorTokenResolver($logger, $theme_extension_list, $theme_settings_manager, $renderer);

    $property = new \ReflectionProperty(ColorTokenResolver::class, 'jsonPath');
    $property->setAccessible(TRUE);
    $json_path = $property->getValue($resolver);

    $this->assertStringEndsWith('/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig/dist/tokens.json', $json_path);
    // The fallback warning always fires. A second "file not found" warning
    // would also fire in an environment where the fallback path doesn't
    // happen to resolve to a real, npm-installed tokens.json -- but in this
    // dev environment the atomic theme's node_modules are installed at
    // exactly that fallback path, so the file *is* found and no second
    // warning is logged. Asserting only the first warning keeps this test
    // meaningful regardless of that environment-dependent state.
    $this->assertNotEmpty($warnings);
    $this->assertSame('Could not get atomic theme path from extension list, using fallback: @path', $warnings[0][0]);
  }

  /**
   * Tests construct logs warning when token file not found.
   *
   * FindTokenFiles() logs a single warning when the composed path doesn't
   * exist but the theme extension list itself resolved successfully.
   *
   * @covers ::__construct
   */
  public function testConstructLogsWarningWhenTokenFileNotFound(): void {
    $warnings = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(function ($message, $context = []) use (&$warnings) {
      $warnings[] = [$message, $context];
    });

    $resolver = $this->createResolver(NULL, $logger);

    $property = new \ReflectionProperty(ColorTokenResolver::class, 'jsonPath');
    $property->setAccessible(TRUE);
    $json_path = $property->getValue($resolver);

    $this->assertStringEndsWith('/nonexistent-atomic-theme-path/node_modules/@yalesites-org/component-library-twig/dist/tokens.json', $json_path);
    $this->assertCount(1, $warnings);
    $this->assertSame('Token file not found; expected at @path', $warnings[0][0]);
    $this->assertSame($json_path, $warnings[0][1]['@path']);
  }

  /**
   * Tests get global theme colors resolves hex and names.
   *
   * GetGlobalThemeColors() resolves HSL values to hex and looks up token
   * names from the color object, falling back to a slot-derived name when
   * no match exists.
   *
   * @covers ::getGlobalThemeColors
   * @covers ::parseBuiltJson
   * @covers ::buildColorLookup
   * @covers ::traverseColorObject
   * @covers ::hslToHex
   * @covers ::getSlotName
   */
  public function testGetGlobalThemeColorsResolvesHexAndNames(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $themes = $resolver->getGlobalThemeColors();

    $this->assertSame(['one', 'two'], array_keys($themes));

    $this->assertSame('Old Blues', $themes['one']['label']);
    $this->assertSame([
      'hsl' => 'hsl(210, 100%, 21%)',
      'hex' => '#00366b',
      'token' => 'slot-one',
      'name' => 'Blue Yale',
      'css_var' => '--global-themes-one-colors-slot-one',
    ], $themes['one']['colors']['slot-one']);
    // No matching color-object entry for this HSL value, so the name falls
    // back to a slot-derived label instead of a token name.
    $this->assertSame([
      'hsl' => 'hsl(0, 0%, 50%)',
      'hex' => '#808080',
      'token' => 'slot-two',
      'name' => 'Slot Two',
      'css_var' => '--global-themes-one-colors-slot-two',
    ], $themes['one']['colors']['slot-two']);

    $this->assertSame('New Haven Green', $themes['two']['label']);
    $this->assertSame('#26734d', $themes['two']['colors']['slot-one']['hex']);
  }

  /**
   * Tests get global theme colors returns empty array when file missing.
   *
   * GetGlobalThemeColors() returns an empty array and logs a warning when the
   * token file doesn't exist.
   *
   * @covers ::getGlobalThemeColors
   */
  public function testGetGlobalThemeColorsReturnsEmptyArrayWhenFileMissing(): void {
    $warnings = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(function ($message, $context = []) use (&$warnings) {
      $warnings[] = [$message, $context];
    });

    $resolver = $this->createResolver('/nonexistent/path/tokens.json', $logger);

    $this->assertSame([], $resolver->getGlobalThemeColors());
    $this->assertContains(['Color token JSON file not found: @json', ['@json' => '/nonexistent/path/tokens.json']], $warnings);
  }

  /**
   * Tests get global theme colors returns empty array when global themes.
   *
   * GetGlobalThemeColors() returns an empty array and logs a warning when the
   * decoded JSON has no global-themes key.
   *
   * @covers ::getGlobalThemeColors
   */
  public function testGetGlobalThemeColorsReturnsEmptyArrayWhenGlobalThemesKeyMissing(): void {
    $resolver = $this->createResolver(__DIR__ . '/../../fixtures/tokens-fixture-no-global-themes.json');
    $this->assertSame([], $resolver->getGlobalThemeColors());
  }

  /**
   * Tests get global theme colors treats malformed json as missing key.
   *
   * Malformed JSON decodes to NULL rather than throwing, so it is handled by
   * the same "missing global-themes key" branch as well-formed JSON that
   * simply lacks the key -- the try/catch in getGlobalThemeColors() is never
   * reached for this input, since json_decode() doesn't throw by default.
   *
   * @covers ::getGlobalThemeColors
   */
  public function testGetGlobalThemeColorsTreatsMalformedJsonAsMissingKey(): void {
    $resolver = $this->createResolver(__DIR__ . '/../../fixtures/tokens-fixture-malformed.json');
    $this->assertSame([], $resolver->getGlobalThemeColors());
  }

  /**
   * GetThemeColors() returns the colors sub-array for a known theme ID.
   *
   * @covers ::getThemeColors
   */
  public function testGetThemeColorsReturnsColorsForKnownTheme(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $colors = $resolver->getThemeColors('one');
    $this->assertSame(['slot-one', 'slot-two'], array_keys($colors));
  }

  /**
   * GetThemeColors() returns an empty array for an unknown theme ID.
   *
   * @covers ::getThemeColors
   */
  public function testGetThemeColorsReturnsEmptyArrayForUnknownTheme(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([], $resolver->getThemeColors('does-not-exist'));
  }

  /**
   * Tests hsl to hex converts across hue boundaries.
   *
   * HslToHex() converts across each of the six 60-degree hue segments, plus
   * grayscale and malformed input.
   *
   * @covers ::hslToHex
   *
   * @dataProvider hslToHexProvider
   */
  public function testHslToHexConvertsAcrossHueBoundaries(string $hsl, string $expected_hex): void {
    $resolver = $this->createResolver();
    $this->assertSame($expected_hex, $this->invokeMethod($resolver, 'hslToHex', [$hsl]));
  }

  /**
   * Data provider for testHslToHexConvertsAcrossHueBoundaries().
   */
  public static function hslToHexProvider(): array {
    return [
      'red boundary (h=0)' => ['hsl(0, 100%, 50%)', '#ff0000'],
      'yellow boundary (h=60)' => ['hsl(60, 100%, 50%)', '#ffff00'],
      'green boundary (h=120)' => ['hsl(120, 100%, 50%)', '#00ff00'],
      'cyan boundary (h=180)' => ['hsl(180, 100%, 50%)', '#00ffff'],
      'blue boundary (h=240)' => ['hsl(240, 100%, 50%)', '#0000ff'],
      'magenta boundary (h=300)' => ['hsl(300, 100%, 50%)', '#ff00ff'],
      'black' => ['hsl(0, 0%, 0%)', '#000000'],
      'white' => ['hsl(0, 0%, 100%)', '#ffffff'],
      'mid-range yale blue' => ['hsl(210, 100%, 21%)', '#00366b'],
      'malformed string returns empty' => ['not-a-color', ''],
      'empty string returns empty' => ['', ''],
    ];
  }

  /**
   * BuildColorInfo() returns a fixed structure for the 'default' option.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoForDefaultOption(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => '#ffffff',
      'hex' => '#ffffff',
      'token_name' => 'Default',
      'token_ref' => 'some-var',
    ], $resolver->buildColorInfo('default', 'some-var'));
  }

  /**
   * Tests build color info for default option without background var.
   *
   * BuildColorInfo() falls back to 'default' as the token_ref for the
   * 'default' option when no background color variable is given.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoForDefaultOptionWithoutBackgroundVar(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame('default', $resolver->buildColorInfo('default', NULL)['token_ref']);
  }

  /**
   * Tests build color info returns empty for missing background color.
   *
   * BuildColorInfo() returns an all-empty structure when no background color
   * variable is given for a non-default option.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsEmptyForMissingBackgroundColor(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => '',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('one', NULL));
  }

  /**
   * Tests build color info returns empty for non scalar background color.
   *
   * BuildColorInfo() returns an all-empty structure when the background
   * color variable is a non-scalar value (e.g. an array).
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsEmptyForNonScalarBackgroundColor(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => '',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('one', ['not', 'scalar']));
  }

  /**
   * Tests build color info resolves global theme slot variable.
   *
   * BuildColorInfo() resolves a global-themes slot CSS variable to its hex
   * value and token name.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoResolvesGlobalThemeSlotVariable(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => 'var(--global-themes-one-colors-slot-one)',
      'hex' => '#00366b',
      'token_name' => 'Blue Yale',
      'token_ref' => 'slot-one',
    ], $resolver->buildColorInfo('one', 'var(--global-themes-one-colors-slot-one)'));
  }

  /**
   * Tests build color info returns empty hex for unmatched slot.
   *
   * BuildColorInfo() keeps the raw css_var but leaves hex/name/ref empty when
   * the slot in a global-themes variable doesn't exist for that theme.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsEmptyHexForUnmatchedSlot(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => 'var(--global-themes-one-colors-slot-nine)',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('one', 'var(--global-themes-one-colors-slot-nine)'));
  }

  /**
   * Tests build color info resolves component theme variable.
   *
   * BuildColorInfo() resolves a component-themes CSS variable directly from
   * the component-themes section of the token JSON.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoResolvesComponentThemeVariable(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => 'var(--component-themes-five-background)',
      'hex' => '#e3f7f5',
      'token_name' => '',
      'token_ref' => 'component-themes-five-background',
    ], $resolver->buildColorInfo('five', 'var(--component-themes-five-background)'));
  }

  /**
   * Tests build color info returns empty for missing component theme.
   *
   * BuildColorInfo() leaves hex/name/ref empty when the component-themes
   * property doesn't exist.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsEmptyForMissingComponentThemeProperty(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => 'var(--component-themes-five-nonexistent)',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('five', 'var(--component-themes-five-nonexistent)'));
  }

  /**
   * Tests build color info returns empty for unmatched var pattern.
   *
   * BuildColorInfo() keeps the raw css_var but leaves hex/name/ref empty when
   * a var() value matches neither the global-themes nor component-themes
   * variable name patterns.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsEmptyForUnmatchedVarPattern(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => 'var(--something-else)',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('one', 'var(--something-else)'));
  }

  /**
   * Tests build color info returns raw value for non var string.
   *
   * BuildColorInfo() passes through a raw, non-var() string as the css_var
   * with hex/name/ref left empty.
   *
   * @covers ::buildColorInfo
   */
  public function testBuildColorInfoReturnsRawValueForNonVarString(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $this->assertSame([
      'css_var' => '#ffffff',
      'hex' => '',
      'token_name' => '',
      'token_ref' => '',
    ], $resolver->buildColorInfo('one', '#ffffff'));
  }

  /**
   * Tests get color styles for entity default base mapping is one to one.
   *
   * GetColorStylesForEntity() maps options directly to same-numbered slots
   * (1:1) for entity types/bundles with no custom mapping.
   *
   * @covers ::getColorStylesForEntity
   * @covers ::buildColorStyles
   */
  public function testGetColorStylesForEntityDefaultBaseMappingIsOneToOne(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity(NULL, NULL);

    $this->assertSame(['var(--global-themes-one-colors-slot-one)'], $styles['one']['one']);
    $this->assertSame(['var(--global-themes-one-colors-slot-five)'], $styles['one']['five']);
    // Global theme 'four' gets no swap under the base mapping.
    $this->assertSame(['var(--global-themes-four-colors-slot-two)'], $styles['four']['two']);
  }

  /**
   * Tests get color styles for entity applies layout swap for theme four.
   *
   * GetColorStylesForEntity() swaps slot-two/slot-five for global theme
   * 'four' under the layout_section/ys_layout_options mapping.
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityAppliesLayoutSwapForThemeFour(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('layout_section', 'ys_layout_options');

    // Base layout mapping: one->one, two->four, three->five, four->two.
    $this->assertSame(['var(--global-themes-one-colors-slot-one)'], $styles['one']['one']);
    $this->assertSame(['var(--global-themes-one-colors-slot-four)'], $styles['one']['two']);
    $this->assertSame(['var(--global-themes-one-colors-slot-five)'], $styles['one']['three']);
    $this->assertSame(['var(--global-themes-one-colors-slot-two)'], $styles['one']['four']);

    // Theme 'four' gets the two<->five slot swap on top of the layout
    // mapping. The swap is keyed by the resolved *slot identifier*, not the
    // option key: option 'two' resolves to slot-four, which isn't a swap
    // key, so it passes through unchanged; options 'three' (slot-five) and
    // 'four' (slot-two) do swap.
    $this->assertSame(['var(--global-themes-four-colors-slot-four)'], $styles['four']['two']);
    $this->assertSame(['var(--global-themes-four-colors-slot-two)'], $styles['four']['three']);
    $this->assertSame(['var(--global-themes-four-colors-slot-five)'], $styles['four']['four']);
  }

  /**
   * Tests get color styles for entity callout bundle mapping.
   *
   * GetColorStylesForEntity() applies the callout-family mapping, the
   * slot-five->slot-two swap (one direction only) for theme 'four', and the
   * component-themes-five-background direct override for option 'five'.
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityCalloutBundleMapping(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('block_content', 'callout');

    $this->assertSame(['var(--global-themes-four-colors-slot-one)'], $styles['four']['one']);
    $this->assertSame(['var(--global-themes-four-colors-slot-four)'], $styles['four']['two']);
    $this->assertSame(['var(--global-themes-four-colors-slot-two)'], $styles['four']['three']);
    $this->assertSame(['var(--global-themes-four-colors-slot-three)'], $styles['four']['four']);
    $this->assertSame(['var(--component-themes-five-background)'], $styles['four']['five']);

    // Other callout-family bundles share the same mapping.
    $spotlight_styles = $resolver->getColorStylesForEntity('block_content', 'content_spotlight');
    $this->assertSame($styles['four'], $spotlight_styles['four']);
  }

  /**
   * Tests get color styles for entity facts bundle uses four option override.
   *
   * GetColorStylesForEntity() applies the facts-specific override, which
   * targets option 'four' (not 'five') for the component-theme override.
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityFactsBundleUsesFourOptionOverride(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('block_content', 'facts');

    $this->assertSame(['var(--component-themes-five-background)'], $styles['four']['four']);
    $this->assertSame(['var(--global-themes-four-colors-slot-three)'], $styles['four']['five']);
  }

  /**
   * GetColorStylesForEntity() applies the quote_callout/link_grid mapping.
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityQuoteCalloutBundleMapping(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('block_content', 'quote_callout');

    $this->assertSame(['var(--global-themes-four-colors-slot-three)'], $styles['four']['two']);
    $this->assertSame(['var(--global-themes-four-colors-slot-four)'], $styles['four']['four']);
    $this->assertSame(['var(--component-themes-five-background)'], $styles['four']['five']);

    $link_grid_styles = $resolver->getColorStylesForEntity('block_content', 'link_grid');
    $this->assertSame($styles['four'], $link_grid_styles['four']);
  }

  /**
   * Tests get color styles for entity inline message bundle mapping.
   *
   * GetColorStylesForEntity() applies the inline_message mapping, which
   * applies uniformly across global themes (no theme-'four' swap).
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityInlineMessageBundleMapping(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('block_content', 'inline_message');

    $this->assertSame(['var(--global-themes-one-colors-slot-four)'], $styles['one']['one']);
    $this->assertSame(['var(--global-themes-one-colors-slot-one)'], $styles['one']['two']);
    $this->assertSame(['var(--global-themes-one-colors-slot-two)'], $styles['one']['three']);
    $this->assertSame(['var(--global-themes-one-colors-slot-three)'], $styles['one']['four']);
    $this->assertSame(['var(--global-themes-one-colors-slot-five)'], $styles['one']['five']);
  }

  /**
   * Tests get color styles for entity accordion adds default accent.
   *
   * GetColorStylesForEntity() adds an explicit accent default for accordion,
   * on top of the base 1:1 mapping.
   *
   * @covers ::getColorStylesForEntity
   */
  public function testGetColorStylesForEntityAccordionAddsDefaultAccent(): void {
    $resolver = $this->createResolver($this->fixturePath);
    $styles = $resolver->getColorStylesForEntity('block_content', 'accordion');

    $this->assertSame(['var(--color-accordion-accent)'], $styles['one']['default']);
    $this->assertSame(['var(--color-accordion-accent)'], $styles['seven']['default']);
    // Base mapping is untouched.
    $this->assertSame(['var(--global-themes-one-colors-slot-one)'], $styles['one']['one']);
  }

  /**
   * Tests reorder palette options preserves order then appends remaining.
   *
   * ReorderPaletteOptions() moves the desired-order keys to the front, in
   * order, then appends any remaining options in their original order.
   *
   * @covers ::reorderPaletteOptions
   */
  public function testReorderPaletteOptionsPreservesOrderThenAppendsRemaining(): void {
    $resolver = $this->createResolver();
    $result = $this->invokeMethod($resolver, 'reorderPaletteOptions', [
      ['two' => 'Two', 'one' => 'One', 'extra' => 'Extra'],
      ['default', 'one', 'two'],
    ]);

    $this->assertSame(['one' => 'One', 'two' => 'Two', 'extra' => 'Extra'], $result);
  }

  /**
   * Tests process color picker builds color info for base mapping.
   *
   * ProcessColorPicker() strips the '_none' option from the internal palette
   * data (but not from $element['#options'] itself), resolves color info for
   * the base mapping, and hides the native select element.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerBuildsColorInfoForBaseMapping(): void {
    $resolver = $this->createResolver($this->fixturePath, NULL, 'one');

    $rendered_argument = NULL;
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered_argument) {
      $rendered_argument = $element;
      return '<div class="rendered-palette"></div>';
    });
    $this->setRendererOnResolver($resolver, $renderer);

    $element = [
      '#default_value' => 'two',
      '#options' => ['_none' => '- None -', 'one' => 'One', 'two' => 'Two'],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = $resolver->processColorPicker($element, $form_state, $complete_form);

    // $element['#options'] itself is untouched outside the section-layout
    // branch -- '_none' remains there even though it's excluded from the
    // rendered palette below.
    $this->assertArrayHasKey('_none', $result['#options']);
    $this->assertSame('position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; overflow: hidden;', $result['#attributes']['style']);
    $this->assertContains('palette-select-hidden', $result['#attributes']['class']);
    $this->assertStringContainsString('rendered-palette', $result['#suffix']);

    $this->assertArrayNotHasKey('_none', $rendered_argument['#palette_options']);
    $this->assertSame('one', $rendered_argument['#global_theme']);
    $this->assertSame('two', $rendered_argument['#selected_value']);
    $this->assertSame('#00366b', $rendered_argument['#color_info']['one']['hex']);
    $this->assertSame('#808080', $rendered_argument['#color_info']['two']['hex']);
  }

  /**
   * Tests process color picker reorders options for section layout.
   *
   * ProcessColorPicker() reorders palette options and sets #palette_order
   * for the layout_section/ys_layout_options entity/bundle combination.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerReordersOptionsForSectionLayout(): void {
    $resolver = $this->createResolver($this->fixturePath, NULL, 'one');
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('render')->willReturnCallback(function (&$element) {
      return '<div></div>';
    });
    $this->setRendererOnResolver($resolver, $renderer);

    $element = [
      '#default_value' => 'one',
      '#options' => [
        '_none' => '- None -',
        'one' => 'One',
        'two' => 'Two',
        'three' => 'Three',
        'four' => 'Four',
        'default' => 'Default',
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = $resolver->processColorPicker($element, $form_state, $complete_form, 'layout_section', 'ys_layout_options');

    $this->assertSame(['default', 'one', 'two', 'three', 'four'], array_keys($result['#options']));
  }

  /**
   * Tests process color picker falls back to theme one for unknown global.
   *
   * ProcessColorPicker() falls back to global theme 'one' styles when the
   * current global theme setting isn't among the resolved color styles.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerFallsBackToThemeOneForUnknownGlobalTheme(): void {
    $resolver = $this->createResolver($this->fixturePath, NULL, 'unknown-theme');

    $rendered_argument = NULL;
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered_argument) {
      $rendered_argument = $element;
      return '<div></div>';
    });
    $this->setRendererOnResolver($resolver, $renderer);

    $element = ['#default_value' => 'one', '#options' => ['one' => 'One']];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $resolver->processColorPicker($element, $form_state, $complete_form);

    // 'unknown-theme' is reported as the global theme, but the color info
    // resolves against theme 'one' styles.
    $this->assertSame('unknown-theme', $rendered_argument['#global_theme']);
    $this->assertSame('#00366b', $rendered_argument['#color_info']['one']['hex']);
  }

  /**
   * Set renderer on resolver.
   *
   * Replaces the renderer service on a constructed resolver via reflection,
   * so tests can assert on what's passed to render() without predicting the
   * exact markup it returns.
   */
  protected function setRendererOnResolver(ColorTokenResolver $resolver, RendererInterface $renderer): void {
    $property = new \ReflectionProperty(ColorTokenResolver::class, 'renderer');
    $property->setAccessible(TRUE);
    $property->setValue($resolver, $renderer);
  }

}
