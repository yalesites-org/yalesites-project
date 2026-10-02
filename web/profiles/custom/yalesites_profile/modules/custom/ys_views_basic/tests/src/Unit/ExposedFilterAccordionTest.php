<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
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
   * {@inheritdoc}
   *
   * ::groupFieldDisplayRow() builds its intro with the global t() and
   * concatenates the result, which forces __toString() on the
   * TranslatableMarkup and so needs a container with string_translation on
   * it. Without this, every test that runs that callback errors with
   * ContainerNotInitializedException before reaching a single assertion —
   * which is how two of the tests below were failing.
   *
   * It is the global t() rather than $this->t() because the method is a
   * static #after_build callback, so there is no $this to call it on. And
   * the container has to be built here because UnitTestCase::setUp()
   * deliberately leaves the test container-less.
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

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
   * container used for the flex row's layout and CSS — the control itself is
   * never hidden, it is disabled when its paired multi-select is empty; see
   * that method's docblock — and each radios keeps its own
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
   * Builds a group resembling the built "Tags, sorting & pinned" tab.
   *
   * Mirrors what buildTermIncludeExclude(), buildSortControl() and
   * buildPinnedControls() leave in 'filter_and_sort' before its two
   * #after_build passes run. The operator radios arrive already wrapped by
   * ::buildTermOperator() in a #type container that exists purely for the
   * flex row's layout and CSS — that control is never hidden, it is disabled
   * when its paired multi-select is empty (see that method's docblock).
   *
   * tag_filters_intro is a CONTAINER with generically-named children, not a
   * leaf, and that is deliberate here: 'heading' and 'help' are the most
   * collision-prone keys in either subtree, because every other intro in the
   * widget is uniquely keyed (exposed_filter_options_intro,
   * result_content_intro) while these two are not. Modelling this as a plain
   * #markup leaf would keep the two keys that actually matter out of the
   * guard below.
   */
  private function filterAndSortGroup(): array {
    return [
      'tag_filters_intro' => [
        '#type' => 'container',
        '#weight' => -10,
        'heading' => ['#markup' => '<h3>Tag filters</h3>'],
        'help' => ['#markup' => '<p>Choose which tags narrow this list.</p>'],
      ],
      'terms_include' => [
        '#type' => 'select',
        '#input' => TRUE,
        '#multiple' => TRUE,
        '#value' => ['7'],
      ],
      'include_operator_wrapper' => [
        '#type' => 'container',
        'include_operator' => ['#type' => 'radios', '#input' => TRUE, '#value' => '+'],
      ],
      'terms_exclude' => [
        '#type' => 'select',
        '#input' => TRUE,
        '#multiple' => TRUE,
        '#value' => [],
      ],
      'exclude_operator_wrapper' => [
        '#type' => 'container',
        'exclude_operator' => ['#type' => 'radios', '#input' => TRUE, '#value' => ','],
      ],
      'sort_by' => ['#type' => 'select', '#input' => TRUE, '#value' => 'field_event_date'],
      'pinned_to_top' => ['#type' => 'checkbox', '#input' => TRUE, '#value' => 1],
      'pin_label' => ['#type' => 'textfield', '#input' => TRUE, '#value' => 'Featured'],
    ];
  }

  /**
   * Walks a built subtree the way ::flattenBuiltElements() does, but as a list.
   *
   * The production method builds a key => element MAP with `$flat += ...`,
   * which is a union: the first occurrence of a key wins and any later one is
   * dropped without a word. That is the behaviour this helper exists to
   * measure — it applies the identical descent rule and returns every value
   * element key it meets, duplicates included, so the two can be compared.
   *
   * @param array $element
   *   A built form element.
   *
   * @return string[]
   *   Every value element key in the subtree, in encounter order.
   */
  private function valueElementKeys(array $element): array {
    $keys = [];
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      if (empty($child['#input']) && Element::children($child)) {
        $keys = array_merge($keys, $this->valueElementKeys($child));
        continue;
      }
      $keys[] = $key;
    }
    return $keys;
  }

  /**
   * Asserts flattening a built subtree loses nothing to a key collision.
   *
   * @param array $built
   *   The subtree as the #after_build callbacks leave it.
   * @param string $label
   *   Which subtree, for the failure message.
   */
  private function assertFlattenIsLossless(array $built, string $label): void {
    $found = $this->valueElementKeys($built);
    $flat = $this->invokeStatic('flattenBuiltElements', [$built]);

    $collisions = array_keys(array_filter(array_count_values($found), fn($n) => $n > 1));
    sort($collisions);

    $this->assertSame([], $collisions, sprintf(
      'Two elements in the built %s subtree share the key "%s". '
      . 'flattenBuiltElements() merges with +=, so the first one wins and the '
      . 'second is dropped, with nothing logged. If massageFormValues() reads '
      . 'that key off the flattened map it will store the wrong element\'s '
      . 'value; if it does not read it today, the next person to add a read '
      . 'inherits the trap. Give the later element a globally unique key — '
      . 'include_operator/exclude_operator are the worked example.',
      $label,
      implode('", "', $collisions)
    ));

    // Stated separately from the collision list because it also catches this
    // walk drifting from the production descent rule, which would leave the
    // assertion above quietly measuring a different tree.
    //
    // Identity rather than a count: a count matches on drift that swaps one
    // key for another — if this walk descended into a single-child wrapper
    // that flattenBuiltElements() treats as a leaf, both sides would report
    // one key and the trees would still be different. Both walks emit in the
    // same order and `+=` preserves insertion order, so with no collisions
    // the two are identical, not merely the same length.
    $this->assertSame($found, array_keys($flat), sprintf(
      'Flattening the built %s subtree did not produce exactly the value '
      . 'elements this test walked to — either a key collided, or this test '
      . 'no longer walks the tree the way flattenBuiltElements() does.',
      $label
    ));
  }

  /**
   * No two relocated elements collide when a subtree is flattened.
   *
   * The #after_build passes move value elements into new wrappers, and
   * ::flattenBuiltElements() keys the result by each element's OWN key rather
   * than by its path — so two elements anywhere in one subtree that happen to
   * share a key silently become one, and the survivor is whichever the walk
   * reaches first. Nothing in the form, in Drupal or in CI would report it.
   * For the keys massageFormValues() reads off the flattened map — among them
   * include_operator and exclude_operator, which it reads with no
   * null-coalesce — that means storing one control's value for another.
   *
   * ::buildTermOperator() already avoids this by hand, giving each operator a
   * globally unique key instead of a generic one, and its docblock says so.
   * That is a convention held in a comment; this is the check that holds it.
   * Both subtrees that get flattened are covered, with every relocating
   * callback applied in its registered order.
   *
   * This is the narrow first step the review asked for. The wider fix — keying
   * the flattened map by path, or not relocating elements at all — is the
   * #after_build redesign, deliberately out of scope here.
   *
   * @covers ::flattenBuiltElements
   */
  public function testNoTwoGroupsShareTheSameChildKey() {
    $form_state = $this->createMock(FormStateInterface::class);

    // 'entity_and_view_mode': accordion first, then the display row — the
    // order they are registered in formElement().
    $entity_and_view_mode = EventViewWidget::groupFieldDisplayRow(
      EventViewWidget::buildExposedFilterAccordion($this->group(), $form_state),
      $form_state
    );
    $this->assertFlattenIsLossless($entity_and_view_mode, 'entity_and_view_mode');

    // 'filter_and_sort': term operator rows, then the pinned row.
    $filter_and_sort = EventViewWidget::groupPinnedRow(
      EventViewWidget::groupTermOperatorRows($this->filterAndSortGroup(), $form_state),
      $form_state
    );
    $this->assertFlattenIsLossless($filter_and_sort, 'filter_and_sort');
  }

  /**
   * The collision check actually detects a collision.
   *
   * Without this, the test above passes on a tree that happens to be fine and
   * nobody knows whether it would notice one that is not — the same reason the
   * guards in this module's sibling repos pair every invariant with a
   * synthetic failing case.
   *
   * @covers ::flattenBuiltElements
   */
  public function testCollisionCheckDetectsDuplicateKeys() {
    $group = $this->filterAndSortGroup();
    // 'include_operator' specifically, not any key: massageFormValues() reads
    // it straight off the flattened map with no null-coalesce
    // (ViewsBasicWidgetBase:440), so a collision here corrupts a save rather
    // than being merely untidy. Keys such as sort_by and pin_label come from
    // $form_state->getValue() instead and would survive a collision unharmed,
    // which would make for a weaker demonstration.
    $group['rogue_wrapper'] = [
      '#type' => 'container',
      'include_operator' => ['#type' => 'radios', '#input' => TRUE, '#value' => 'OR'],
    ];

    $found = $this->valueElementKeys($group);
    $flat = $this->invokeStatic('flattenBuiltElements', [$group]);

    // The walk sees both; the production flatten silently keeps one.
    $this->assertSame(2, count(array_keys($found, 'include_operator', TRUE)));
    $this->assertCount(count($found) - 1, $flat);
    // And it is the FIRST that survives, which is why the loss is silent: the
    // value that reaches storage is a plausible one, just the wrong element's.
    $this->assertSame('+', $flat['include_operator']['#value']);
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
