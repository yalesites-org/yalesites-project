<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_themes\ColorTokenResolver;
use Drupal\ys_themes\Plugin\Field\FieldWidget\ComponentColorPicker;
use Drupal\ys_themes\ThemeSettingsManager;

/**
 * Unit tests for the ComponentColorPicker field widget.
 *
 * FormElement() itself is not covered here: OptionsSelectWidget's parent
 * formElement() resolves options from the field's OptionsProviderInterface,
 * which needs a real field storage definition and entity -- more practical
 * as Kernel-test territory than to fake through mocks. processColorPicker()
 * is the widget's own logic and is independent of that machinery, so it is
 * exercised directly here with the same #palette_options/#global_theme/
 * #selected_value/#color_styles shape formElement() would have set.
 *
 * @coversDefaultClass \Drupal\ys_themes\Plugin\Field\FieldWidget\ComponentColorPicker
 * @group ys_themes
 * @group yalesites
 */
class ComponentColorPickerTest extends UnitTestCase {

  /**
   * The mocked color token resolver.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $colorTokenResolver;

  /**
   * The mocked renderer, capturing the render array passed to it.
   *
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * The widget under test.
   *
   * @var \Drupal\ys_themes\Plugin\Field\FieldWidget\ComponentColorPicker
   */
  protected $widget;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $theme_settings_manager = $this->createMock(ThemeSettingsManager::class);
    $this->colorTokenResolver = $this->createMock(ColorTokenResolver::class);
    $this->colorTokenResolver->method('buildColorInfo')->willReturnCallback(function ($key, $var) {
      return [
        'css_var' => (string) $var,
        'hex' => '#abcabc',
        'token_name' => 'Fake ' . $key,
        'token_ref' => 'slot-' . $key,
      ];
    });
    $this->renderer = $this->createMock(RendererInterface::class);

    // OptionsWidgetBase::__construct() -- the widget's parent class -- reads
    // the field storage definition's property names immediately, so this
    // has to be in place even though these tests never reach formElement().
    $field_storage_definition = $this->createMock(FieldStorageDefinitionInterface::class);
    $field_storage_definition->method('getPropertyNames')->willReturn(['value']);
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getFieldStorageDefinition')->willReturn($field_storage_definition);

    $this->widget = new ComponentColorPicker(
      'component_color_picker',
      [],
      $field_definition,
      [],
      [],
      $theme_settings_manager,
      $this->colorTokenResolver,
      $this->renderer
    );
  }

  /**
   * Tests process color picker builds color info and cleans up element.
   *
   * ProcessColorPicker() removes its internal palette keys from $element,
   * leaving only the #prefix/#suffix wrapper, and resolves color info for
   * each palette option using the given color styles.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerBuildsColorInfoAndCleansUpElement(): void {
    $rendered = NULL;
    $this->renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered) {
      $rendered = $element;
      return '<div class="rendered"></div>';
    });

    $element = [
      '#palette_options' => ['one' => 'One', 'two' => 'Two'],
      '#global_theme' => 'one',
      '#selected_value' => 'two',
      '#color_styles' => [
        'one' => ['var(--x-one)'],
        'two' => ['var(--x-two)'],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $result = $this->widget->processColorPicker($element, $form_state, $complete_form);

    $this->assertSame([
      '#prefix' => '<div class="component-color-picker-wrapper" style="position: relative;">',
      '#suffix' => '<div class="rendered"></div></div>',
    ], $result);

    $this->assertSame('one', $rendered['#global_theme']);
    $this->assertSame('two', $rendered['#selected_value']);
    $this->assertSame(['one' => 'One', 'two' => 'Two'], $rendered['#palette_options']);
    $this->assertSame('var(--x-one)', $rendered['#color_info']['one']['css_var']);
    $this->assertSame('var(--x-two)', $rendered['#color_info']['two']['css_var']);
  }

  /**
   * Tests process color picker normalizes array selected value.
   *
   * An array-valued #selected_value (as OptionsWidgetBase produces for
   * multi-value fields) is normalized to its first element as a string.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerNormalizesArraySelectedValue(): void {
    $rendered = NULL;
    $this->renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered) {
      $rendered = $element;
      return '<div></div>';
    });

    $element = [
      '#palette_options' => ['one' => 'One', 'two' => 'Two'],
      '#global_theme' => 'one',
      '#selected_value' => ['two' => 'two'],
      '#color_styles' => [],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $this->widget->processColorPicker($element, $form_state, $complete_form);

    $this->assertSame('two', $rendered['#selected_value']);
  }

  /**
   * Tests process color picker defaults to default option when selected.
   *
   * An empty #selected_value defaults to 'default' when a 'default' option
   * is present among the palette options.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerDefaultsToDefaultOptionWhenSelectedValueEmpty(): void {
    $rendered = NULL;
    $this->renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered) {
      $rendered = $element;
      return '<div></div>';
    });

    $element = [
      '#palette_options' => ['default' => 'Default', 'one' => 'One'],
      '#global_theme' => 'one',
      '#selected_value' => NULL,
      '#color_styles' => [],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $this->widget->processColorPicker($element, $form_state, $complete_form);

    $this->assertSame('default', $rendered['#selected_value']);
  }

  /**
   * Tests process color picker falls back to key for non scalar label.
   *
   * A non-scalar palette option label (e.g. an array) falls back to the
   * stringified key rather than being passed through as-is.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerFallsBackToKeyForNonScalarLabel(): void {
    $rendered = NULL;
    $this->renderer->method('render')->willReturnCallback(function (&$element) use (&$rendered) {
      $rendered = $element;
      return '<div></div>';
    });

    $element = [
      '#palette_options' => [1 => ['unexpected' => 'array-label'], 'two' => 'Two'],
      '#global_theme' => 'one',
      '#selected_value' => 'two',
      '#color_styles' => [],
    ];
    $form_state = $this->createMock(FormStateInterface::class);
    $complete_form = [];

    $this->widget->processColorPicker($element, $form_state, $complete_form);

    $this->assertSame(['1' => '1', 'two' => 'Two'], $rendered['#palette_options']);
  }

}
