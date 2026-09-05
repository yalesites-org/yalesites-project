<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\EventViewWidget;

/**
 * Tests the exposed-filter accordion relocation on the listing widget (#1337).
 *
 * Each exposed filter is paired with the settings that configure it so the pair
 * renders as one row. The move happens after the form is built, which is what
 * keeps the submitted values intact, so these tests pin both halves: that the
 * rows come out in the right shape, and that the relocated elements can still
 * be found (and are still addressed by the same input names) afterwards.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase
 *
 * @group ys_views_basic
 * @group yalesites
 */
class ExposedFilterAccordionTest extends UnitTestCase {

  /**
   * Builds a group resembling the built "Field display & filters" tab.
   *
   * Mirrors what buildExposedFilterControls() produces: a fieldset of
   * individual checkboxes, with the conditional settings still flat siblings.
   * #parents are set as FormBuilder would have set them by #after_build time.
   */
  private function group(): array {
    return [
      'field_options' => [
        '#type' => 'checkboxes',
        '#input' => TRUE,
        '#value' => ['show_tags' => 'show_tags'],
        // A #type checkboxes builds a child per option once processed.
        'show_tags' => ['#type' => 'checkbox', '#input' => TRUE, '#value' => 'show_tags'],
        'show_categories' => ['#type' => 'checkbox', '#input' => TRUE, '#value' => 0],
      ],
      'exposed_filter_options' => [
        '#type' => 'fieldset',
        '#tree' => TRUE,
        'show_search_filter' => [
          '#type' => 'checkbox',
          '#input' => TRUE,
          '#value' => 1,
          '#parents' => ['exposed_filter_options', 'show_search_filter'],
        ],
        'show_category_filter' => [
          '#type' => 'checkbox',
          '#input' => TRUE,
          '#value' => 0,
          '#parents' => ['exposed_filter_options', 'show_category_filter'],
        ],
        'show_custom_vocab_filter' => [
          '#type' => 'checkbox',
          '#input' => TRUE,
          '#value' => 0,
          '#parents' => ['exposed_filter_options', 'show_custom_vocab_filter'],
        ],
      ],
      'category_filter_label' => [
        '#type' => 'textfield',
        '#input' => TRUE,
        '#value' => 'Topic',
        '#parents' => ['category_filter_label'],
      ],
      'category_included_terms' => [
        '#type' => 'select',
        '#input' => TRUE,
        '#value' => '7',
        '#parents' => ['category_included_terms'],
      ],
      'custom_vocab_included_terms' => [
        '#type' => 'select',
        '#input' => TRUE,
        '#value' => '',
        '#parents' => ['custom_vocab_included_terms'],
      ],
    ];
  }

  /**
   * Invokes a protected static method on the widget base.
   */
  private function invokeStatic(string $method, array $args) {
    $ref = new \ReflectionMethod(EventViewWidget::class, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs(NULL, $args);
  }

  /**
   * Each filter becomes a row; only those with settings get a body.
   *
   * @covers ::buildExposedFilterAccordion
   */
  public function testEachFilterBecomesItsOwnRow() {
    $form_state = $this->createMock(FormStateInterface::class);
    $result = EventViewWidget::buildExposedFilterAccordion($this->group(), $form_state);
    $rows = $result['exposed_filter_options'];

    $this->assertArrayHasKey('show_search_filter__row', $rows);
    $this->assertArrayHasKey('show_category_filter__row', $rows);
    $this->assertArrayHasKey('show_custom_vocab_filter__row', $rows);

    // Search has no settings, so it is a header-only row.
    $this->assertArrayNotHasKey('settings', $rows['show_search_filter__row']);
    $this->assertNotContains(
      'vb-filter-row--has-settings',
      $rows['show_search_filter__row']['#attributes']['class']
    );

    // Category owns both of its settings, in order, inside its body.
    $body = $rows['show_category_filter__row']['settings'];
    $this->assertSame(
      ['category_filter_label', 'category_included_terms'],
      array_values(array_filter(array_keys($body), fn($k) => !str_starts_with($k, '#')))
    );
    $this->assertContains(
      'vb-filter-row--has-settings',
      $rows['show_category_filter__row']['#attributes']['class']
    );

    // The settings no longer sit at the top level of the group.
    $this->assertArrayNotHasKey('category_filter_label', $result);
    $this->assertArrayNotHasKey('category_included_terms', $result);
    $this->assertArrayNotHasKey('custom_vocab_included_terms', $result);
  }

  /**
   * The header sorts above the body regardless of prior weights.
   *
   * @covers ::buildExposedFilterAccordion
   */
  public function testHeaderIsWeightedAboveItsBody() {
    $form_state = $this->createMock(FormStateInterface::class);
    $result = EventViewWidget::buildExposedFilterAccordion($this->group(), $form_state);
    $row = $result['exposed_filter_options']['show_category_filter__row'];

    $this->assertSame(0, $row['show_category_filter']['#weight']);
    $this->assertSame(1, $row['settings']['#weight']);
  }

  /**
   * Relocating an element must not change the input it submits to.
   *
   * The move is only safe because #parents are already fixed; if they were
   * rebuilt from the new tree position the #states selectors that address
   * these checkboxes by name would silently stop matching.
   *
   * @covers ::buildExposedFilterAccordion
   */
  public function testRelocationPreservesParents() {
    $form_state = $this->createMock(FormStateInterface::class);
    $result = EventViewWidget::buildExposedFilterAccordion($this->group(), $form_state);

    $this->assertSame(
      ['exposed_filter_options', 'show_category_filter'],
      $result['exposed_filter_options']['show_category_filter__row']['show_category_filter']['#parents']
    );
    $this->assertSame(
      ['category_included_terms'],
      $result['exposed_filter_options']['show_category_filter__row']['settings']['category_included_terms']['#parents']
    );
  }

  /**
   * Values stay findable after the move, including through the fieldset.
   *
   * @covers ::flattenBuiltElements
   */
  public function testFlattenFindsRelocatedValues() {
    $form_state = $this->createMock(FormStateInterface::class);
    $result = EventViewWidget::buildExposedFilterAccordion($this->group(), $form_state);
    $flat = $this->invokeStatic('flattenBuiltElements', [$result]);

    $this->assertSame(1, $flat['show_search_filter']['#value']);
    $this->assertSame(0, $flat['show_category_filter']['#value']);
    $this->assertSame('Topic', $flat['category_filter_label']['#value']);
    $this->assertSame('7', $flat['category_included_terms']['#value']);
  }

  /**
   * Include/exclude operators are found through their hide/reveal wrapper.
   *
   * BuildTermOperator() nests each operator radios one level inside a #type
   * container (the hide/reveal hook — #type radios has nowhere of its own to
   * put it; see that method's docblock), and each radios keeps its own
   * globally-unique key ('include_operator'/'exclude_operator') rather than
   * a generic one, specifically so the two do not collide when flattened
   * from the same 'filter_and_sort' subtree — this is what
   * massageFormValues() relies on to read them independently (#1316).
   *
   * @covers ::flattenBuiltElements
   */
  public function testFlattenFindsIndependentTermOperators() {
    $filter_and_sort = [
      'include_operator_wrapper' => [
        '#type' => 'container',
        '#attributes' => ['hidden' => 'hidden'],
        'include_operator' => [
          '#type' => 'radios',
          '#input' => TRUE,
          '#value' => ',',
        ],
      ],
      'exclude_operator_wrapper' => [
        '#type' => 'container',
        '#attributes' => ['hidden' => 'hidden'],
        'exclude_operator' => [
          '#type' => 'radios',
          '#input' => TRUE,
          '#value' => '+',
        ],
      ],
    ];

    $flat = $this->invokeStatic('flattenBuiltElements', [$filter_and_sort]);

    $this->assertSame(',', $flat['include_operator']['#value']);
    $this->assertSame('+', $flat['exclude_operator']['#value']);
  }

  /**
   * A #type checkboxes is returned whole, not descended into.
   *
   * Its per-option children share their parent's option keys, so descending
   * would shadow the element whose #value is what actually gets stored.
   *
   * @covers ::flattenBuiltElements
   */
  public function testFlattenDoesNotDescendIntoValueElements() {
    $flat = $this->invokeStatic('flattenBuiltElements', [$this->group()]);

    $this->assertArrayHasKey('field_options', $flat);
    $this->assertSame(['show_tags' => 'show_tags'], $flat['field_options']['#value']);
    // The option children must not have replaced it.
    $this->assertArrayNotHasKey('show_categories', $flat);
  }

  /**
   * The field options and preview are gathered onto one display row.
   *
   * @covers ::groupFieldDisplayRow
   */
  public function testFieldOptionsAndPreviewShareOneDisplayRow() {
    $form_state = $this->createMock(FormStateInterface::class);
    $group = $this->group();
    $group['preview'] = ['#theme' => 'views_basic_mockup_preview'];

    $result = EventViewWidget::groupFieldDisplayRow($group, $form_state);

    $this->assertArrayHasKey('display_row', $result);
    $this->assertArrayHasKey('result_content', $result['display_row']);
    $this->assertArrayHasKey('field_options', $result['display_row']['result_content']);
    $this->assertArrayHasKey('preview', $result['display_row']);
    $this->assertArrayNotHasKey('field_options', $result);
    $this->assertArrayNotHasKey('preview', $result);
    // Exposed filters stay out of that row so they cannot stretch the preview.
    $this->assertArrayHasKey('exposed_filter_options', $result);
    $this->assertArrayNotHasKey('exposed_filter_options', $result['display_row']);
  }

  /**
   * The event/post field options join field_options in the row.
   *
   * @covers ::groupFieldDisplayRow
   */
  public function testEntitySpecificFieldOptionsJoinResultContent() {
    $form_state = $this->createMock(FormStateInterface::class);
    $group = $this->group();
    $group['event_field_options'] = [
      '#type' => 'checkboxes',
      '#input' => TRUE,
      '#value' => ['hide_add_to_calendar' => 'hide_add_to_calendar'],
    ];

    $result = EventViewWidget::groupFieldDisplayRow($group, $form_state);

    $this->assertArrayHasKey('result_content', $result['display_row']);
    $this->assertArrayHasKey('field_options', $result['display_row']['result_content']);
    $this->assertArrayHasKey('event_field_options', $result['display_row']['result_content']);
    $this->assertArrayNotHasKey('event_field_options', $result);

    // Values are still findable through the extra fieldset layer.
    $flat = $this->invokeStatic('flattenBuiltElements', [$result]);
    $this->assertSame(
      ['hide_add_to_calendar' => 'hide_add_to_calendar'],
      $flat['event_field_options']['#value']
    );
  }

}
