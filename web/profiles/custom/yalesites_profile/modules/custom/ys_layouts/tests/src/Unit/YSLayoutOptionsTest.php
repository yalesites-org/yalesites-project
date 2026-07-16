<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions;
use Drupal\ys_themes\ColorTokenResolver;

/**
 * Tests the YSLayoutOptions section layout plugin.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions
 *
 * @group yalesites
 * @group ys_layouts
 */
class YSLayoutOptionsTest extends UnitTestCase {

  /**
   * The color token resolver mock.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $colorTokenResolver;

  /**
   * The layout plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions
   */
  protected $layout;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->colorTokenResolver = $this->createMock(ColorTokenResolver::class);
    $definition = new LayoutDefinition([
      'regions' => ['content' => ['label' => 'Content']],
    ]);
    $this->layout = new YSLayoutOptions([], 'ys_layout_options', $definition, $this->colorTokenResolver);
  }

  /**
   * The default configuration adds an empty divider setting.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfigurationAddsDivider(): void {
    $this->assertSame('', $this->layout->defaultConfiguration()['divider']);
  }

  /**
   * The configuration form exposes the divider checkbox and theme select.
   *
   * @covers ::buildConfigurationForm
   */
  public function testBuildConfigurationFormExposesDividerAndTheme(): void {
    $this->layout->setConfiguration(['divider' => TRUE, 'theme' => 'two']);
    $this->layout->setStringTranslation($this->getStringTranslationStub());

    $form = $this->layout->buildConfigurationForm([], new FormState());

    $this->assertSame('checkbox', $form['divider']['#type']);
    $this->assertTrue($form['divider']['#default_value']);
    $this->assertSame('select', $form['theme']['#type']);
    $this->assertSame('two', $form['theme']['#default_value']);
  }

  /**
   * Submitting the configuration form stores the divider and theme values.
   *
   * @covers ::submitConfigurationForm
   */
  public function testSubmitConfigurationFormStoresValues(): void {
    $form_state = new FormState();
    $form_state->setValue('divider', TRUE);
    $form_state->setValue('theme', 'three');
    $form = [];

    $this->layout->submitConfigurationForm($form, $form_state);

    $configuration = $this->layout->getConfiguration();
    $this->assertTrue($configuration['divider']);
    $this->assertSame('three', $configuration['theme']);
  }

  /**
   * The color picker process callback maps to the section layout mapping.
   *
   * @covers ::processColorPicker
   */
  public function testProcessColorPickerDelegatesWithSectionLayoutMapping(): void {
    $element = ['#type' => 'select'];
    $form_state = new FormState();
    $complete_form = ['some' => 'form'];
    $form_state->setCompleteForm($complete_form);

    $this->colorTokenResolver->expects($this->once())
      ->method('processColorPicker')
      ->with($element, $form_state, $complete_form, 'layout_section', 'ys_layout_options')
      ->willReturn($element + ['#processed' => TRUE]);

    $result = $this->layout->processColorPicker($element, $form_state);

    $this->assertTrue($result['#processed']);
  }

}
