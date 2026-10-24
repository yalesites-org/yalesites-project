<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests Claro's field_multiple_value_form preprocess against contrib headers.
 *
 * Regression test for the white-screen fatal when adding a Gallery inline block
 * in Layout Builder:
 *
 * @code
 * Error: Call to a member function removeClass() on array in
 * claro_preprocess_field_multiple_value_form() (line 1079 of claro.theme).
 * @endcode
 *
 * The Gallery block's multi-value field (field_gallery_items, cardinality -1,
 * paragraphs widget) is rendered through field_multiple_value_form. Contrib
 * (Paragraphs) restructures the table header so that
 * $variables['table']['#header'][0]['data']['#attributes'] is a plain array
 * rather than an \Drupal\Core\Template\Attribute object, and Claro's
 * isset()-only guard then calls ->removeClass() on that array. This is the
 * pre-existing core/contrib interaction documented at drupal.org #3099026 /
 * paragraphs #3099024; the composer patch (patches/core) type-guards the guard
 * with `instanceof Attribute`.
 *
 * The preprocess function has no service dependencies, so it is loaded directly
 * and exercised with the two header shapes: the contrib array (must not fatal)
 * and a normal Attribute object (Claro's classes must still be applied).
 *
 * @group ys_core
 */
class ClaroFieldMultipleValueFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Claro is a core theme; load the .theme file so its preprocess functions
    // are defined. The function under test only manipulates $variables and the
    // Attribute class, so no theme install or container service is required.
    require_once DRUPAL_ROOT . '/core/themes/claro/claro.theme';
  }

  /**
   * A plain-array header (as left by the Paragraphs widget) must not fatal.
   *
   * This reproduces the exact operand of the reported crash: a multi-value
   * form whose header cell #attributes is a plain array. Before the patch this
   * throws "Call to a member function removeClass() on array"; after the patch
   * the instanceof guard skips the Claro-specific manipulation and leaves the
   * array untouched.
   */
  public function testContribArrayHeaderDoesNotFatal(): void {
    $variables = [
      'element' => [],
      'multiple' => TRUE,
      'table' => [
        '#header' => [
          0 => [
            'data' => [
              '#attributes' => ['class' => ['label']],
            ],
          ],
        ],
      ],
    ];

    claro_preprocess_field_multiple_value_form($variables);

    // Reaching this line means no fatal was thrown. The non-Attribute header is
    // left as-is.
    $this->assertSame(
      ['label'],
      $variables['table']['#header'][0]['data']['#attributes']['class'],
      'A plain-array header cell is left untouched instead of fataling.'
    );
  }

  /**
   * A normal Attribute header must still receive Claro's label classes.
   *
   * Control case proving the patch does not regress ordinary multi-value
   * fields: when #attributes is a real Attribute object, Claro removes the
   * generic "label" class and adds its form-item label classes.
   */
  public function testAttributeHeaderStillGetsClaroClasses(): void {
    $variables = [
      'element' => [],
      'multiple' => TRUE,
      'table' => [
        '#header' => [
          0 => [
            'data' => [
              '#attributes' => new Attribute(['class' => ['label']]),
            ],
          ],
        ],
      ],
    ];

    claro_preprocess_field_multiple_value_form($variables);

    $attributes = $variables['table']['#header'][0]['data']['#attributes'];
    $this->assertInstanceOf(Attribute::class, $attributes);
    $this->assertFalse($attributes->hasClass('label'), 'The generic "label" class is removed.');
    $this->assertTrue($attributes->hasClass('form-item__label'), 'The form-item__label class is added.');
    $this->assertTrue(
      $attributes->hasClass('form-item__label--multiple-value-form'),
      'The multiple-value-form modifier class is added.'
    );
  }

}
