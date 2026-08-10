<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Tests\UnitTestCase;
use Drupal\gin_lb\HookHandler\Preprocess;
use Drupal\gin_lb\Service\ContextValidatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that gin_lb leaves Claro a usable field table header attribute object.
 *
 * Regression test for the HTTP 500 an editor hit when a Layout Builder block
 * form re-rendered a multi-value field after a validation failure — e.g.
 * adding a Quick Links block with a mistyped protocol such as
 * "htp://example.com". Core builds the field table's header cell with a
 * \Drupal\Core\Template\Attribute object, but gin_lb replaced that cell
 * wholesale using a plain PHP array. Claro's preprocess runs after every
 * module preprocess and calls ->removeClass() on it, fatalling with "Call to
 * a member function removeClass() on array".
 *
 * The fix is the upstream gin_lb patch for
 * https://www.drupal.org/project/gin_lb/issues/3387157, carried in
 * patches/contrib/gin_lb/ because YaleSites pins gin_lb 2.0.0-beta1 and the
 * fix only shipped in 3.0.x.
 *
 * These tests fail if that patch is dropped, but only on a local PHPUnit run —
 * this project's CI runs no PHPUnit (composer unit-test is a stub), so nothing
 * automated re-verifies the patch is still registered.
 *
 * @group yalesites
 * @group ys_layouts
 */
class GinLbHeaderAttributesTest extends UnitTestCase {

  /**
   * The gin_lb preprocess hook handler under test.
   *
   * @var \Drupal\gin_lb\HookHandler\Preprocess
   */
  protected Preprocess $preprocess;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // claro_preprocess_field_multiple_value_form() is the function that
    // fatalled, so load the theme that declares it.
    if (!function_exists('claro_preprocess_field_multiple_value_form')) {
      require_once DRUPAL_ROOT . '/core/themes/claro/claro.theme';
    }

    $this->preprocess = new Preprocess(
      $this->createMock(RequestStack::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ContextValidatorInterface::class)
    );
  }

  /**
   * Claro can still preprocess a header cell that gin_lb has rebuilt.
   */
  public function testHeaderAttributesSurviveClaroPreprocess(): void {
    $variables = $this->buildVariables();

    $this->preprocess->preprocessFieldMultipleValueForm($variables);
    claro_preprocess_field_multiple_value_form($variables);

    $this->assertGinLbRebuiltTheHeader($variables);

    $attributes = $variables['table']['#header'][0]['data']['#attributes'];
    $this->assertInstanceOf(Attribute::class, $attributes);

    // Claro's addClass() only lands if it received an Attribute object.
    $classes = explode(' ', (string) $attributes['class']);
    $this->assertContains('form-item__label', $classes);
    $this->assertContains('form-item__label--multiple-value-form', $classes);
  }

  /**
   * The required-field marker classes gin_lb sets are preserved.
   */
  public function testRequiredMarkerClassesArePreserved(): void {
    $variables = $this->buildVariables();

    $this->preprocess->preprocessFieldMultipleValueForm($variables);
    claro_preprocess_field_multiple_value_form($variables);

    $this->assertGinLbRebuiltTheHeader($variables);

    $attributes = $variables['table']['#header'][0]['data']['#attributes'];
    $classes = explode(' ', (string) $attributes['class']);
    $this->assertContains('js-form-required', $classes);
    $this->assertContains('form-required', $classes);
  }

  /**
   * A disabled multi-value field is preprocessed without fatalling too.
   *
   * Gin_lb and Claro both walk every header cell in their disabled branch, so
   * this covers the other path through the code the patch touches.
   */
  public function testDisabledFieldHeaderSurvivesClaroPreprocess(): void {
    $variables = $this->buildVariables();
    $variables['element']['#disabled'] = TRUE;

    $this->preprocess->preprocessFieldMultipleValueForm($variables);
    claro_preprocess_field_multiple_value_form($variables);

    $this->assertGinLbRebuiltTheHeader($variables);

    $attributes = $variables['table']['#header'][0]['data']['#attributes'];
    $this->assertInstanceOf(Attribute::class, $attributes);
    $this->assertContains(
      'tabledrag-disabled',
      $variables['table']['#attributes']['class']
    );
  }

  /**
   * Asserts gin_lb's branch ran, so the calling test cannot pass vacuously.
   *
   * Only gin_lb adds the glb-table class. Without this anchor a test could
   * pass on Claro's output alone — Claro also adds tabledrag-disabled and the
   * form-item__label classes, and the fixture seeds its own Attribute object.
   *
   * @param array $variables
   *   The preprocessed variables.
   */
  protected function assertGinLbRebuiltTheHeader(array $variables): void {
    $this->assertContains(
      'glb-table',
      $variables['table']['#attributes']['class']
    );
  }

  /**
   * Builds the variables core hands a multi-value field form template.
   *
   * Mirrors the shape template_preprocess_field_multiple_value_form() produces
   * for a required, unlimited-cardinality field inside a gin_lb-styled Layout
   * Builder form.
   *
   * @return array
   *   The preprocess variables.
   *
   * @see template_preprocess_field_multiple_value_form()
   */
  protected function buildVariables(): array {
    return [
      'element' => [
        '#field_name' => 'field_links',
        '#title' => 'Links',
        '#required' => TRUE,
        '#gin_lb_form' => TRUE,
      ],
      'multiple' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'h4',
              '#value' => 'Links',
              '#attributes' => new Attribute([
                'class' => ['label', 'js-form-required', 'form-required'],
              ]),
            ],
            'colspan' => 2,
            'class' => ['field-label'],
          ],
          [],
          'Order',
        ],
        '#rows' => [],
        '#attributes' => [
          'id' => 'field-links-values',
          'class' => ['field-multiple-table'],
        ],
      ],
    ];
  }

}
