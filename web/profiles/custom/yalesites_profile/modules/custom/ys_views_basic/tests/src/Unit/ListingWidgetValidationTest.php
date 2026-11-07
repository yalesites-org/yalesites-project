<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget;
use Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the listing widget's server-side validation and shared helpers.
 *
 * Covers the PR 1299 review findings: the "limit" field is only required
 * while it is shown (F12), the per-type "Result content" subsection is built
 * by one helper (F16), the selects are validated by Form API rather than
 * flagged '#validated' (F19), and the module declares what it depends on
 * (F13).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\Field\FieldWidget\ViewsBasicWidgetBase
 *
 * @group yalesites
 */
class ListingWidgetValidationTest extends UnitTestCase {

  /**
   * The mocked views basic manager.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->manager = $this->createMock(ViewsBasicManager::class);
    $this->manager->method('getTaxonomyParents')
      ->willReturn(['' => 'All categories', 4 => 'Lectures']);
    $this->manager->method('sortByList')
      ->willReturn(['date_desc' => 'Newest first', 'title_asc' => 'Title']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // FormState's "any errors" flag is static; do not leak it to other tests.
    (new FormState())->clearErrors();
    parent::tearDown();
  }

  /**
   * Builds a post listing widget.
   */
  private function widget(): PostViewWidget {
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocab_storage = $this->createMock(EntityStorageInterface::class);
    $vocab_storage->method('load')->willReturn($vocabulary);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($vocab_storage);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getTargetBundle')->willReturn('post_card');

    $widget = new PostViewWidget(
      'post_view_widget',
      [],
      $field_definition,
      [],
      [],
      $this->manager,
      $entity_type_manager,
      $this->getConfigFactoryStub(['ys_core.site' => ['font_pairing' => 'yalenew']]),
    );
    $widget->setStringTranslation($this->getStringTranslationStub());
    return $widget;
  }

  /**
   * Invokes a protected method on the widget.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * Builds field items whose delta 0 carries the given params.
   */
  private function items(?string $params): FieldItemListInterface {
    $item = (object) ['params' => $params];
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn($item);
    return $items;
  }

  /**
   * The form selectors the build methods read.
   */
  private function selectors(): array {
    return [
      'display_ajax' => ':input[name="display"]',
      'show_category_filter_selector' => ':input[name="category"]',
      'show_custom_vocab_filter_selector' => ':input[name="custom_vocab"]',
    ];
  }

  /**
   * Runs the limit validator with the given display and limit values.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state, carrying any error set. Read it with getErrors():
   *   hasAnyErrors() is static across every FormState instance.
   */
  private function validateLimit(string $display, $limit): FormState {
    $parents = ['settings', 'block_form', 'group_user_selection', 'options'];
    $form_state = new FormState();
    $form_state->setValue(array_merge($parents, ['display']), $display);
    $element = [
      '#parents' => array_merge($parents, ['limit']),
      '#title' => 'Items',
      '#value' => $limit,
    ];
    ViewsBasicWidgetBase::validateLimit($element, $form_state);
    return $form_state;
  }

  /**
   * The limit field is never unconditionally required (F12).
   *
   * @covers ::buildDisplayControls
   */
  public function testLimitIsOnlyRequiredThroughStates() {
    $form = [];
    $this->invoke($this->widget(), 'buildDisplayControls', [&$form, $this->items(NULL), 0, $this->selectors()]);
    $limit = $form['group_user_selection']['options']['limit'];

    $this->assertArrayNotHasKey('#required', $limit);
    $this->assertArrayNotHasKey('#min', $limit);
    $this->assertSame([[PostViewWidget::class, 'validateLimit']], $limit['#element_validate']);
    $this->assertSame(['!value' => 'all'], $limit['#states']['required'][':input[name="display"]']);
    $this->assertSame(['!value' => 'all'], $limit['#states']['visible'][':input[name="display"]']);
  }

  /**
   * Showing every item ignores the hidden limit, whatever it holds (F12).
   *
   * @covers ::validateLimit
   */
  public function testLimitNotValidatedWhenDisplayingAll() {
    foreach (['', '0', '-3', 'abc'] as $limit) {
      $this->assertSame([], $this->validateLimit('all', $limit)->getErrors(), "limit '$limit' with display all");
    }
  }

  /**
   * A limit or pager display needs a positive whole number (F12).
   *
   * @covers ::validateLimit
   */
  public function testLimitRequiredWhenNotDisplayingAll() {
    foreach (['limit', 'pager'] as $display) {
      foreach (['', '0', '-1', '2.5'] as $limit) {
        $form_state = $this->validateLimit($display, $limit);
        $errors = $form_state->getErrors();
        $this->assertCount(1, $errors, "limit '$limit' with display $display");
        $this->assertSame(
          'Items must be a whole number greater than zero.',
          (string) $errors['settings][block_form][group_user_selection][options][limit']
        );
      }
      $this->assertSame([], $this->validateLimit($display, '12')->getErrors(), "limit 12 with display $display");
    }
  }

  /**
   * The shared subsection helper builds the per-type checkboxes (F16).
   *
   * @covers ::buildResultOptionsSubsection
   */
  public function testBuildResultOptionsSubsection() {
    $element = $this->invoke($this->widget(), 'buildResultOptionsSubsection', [
      'Post options',
      ['show_eyebrow' => 'Show post teaser lead-in'],
      ['show_eyebrow'],
    ]);

    $this->assertSame([
      '#type' => 'checkboxes',
      '#prefix' => '<div class="vb-result-content__subsection">',
      '#suffix' => '</div>',
      '#options' => ['show_eyebrow' => 'Show post teaser lead-in'],
      '#title' => 'Post options',
      '#tree' => TRUE,
      '#default_value' => ['show_eyebrow'],
    ], $element);
  }

  /**
   * The post widget's options come from the shared helper (F16).
   *
   * @covers \Drupal\ys_views_basic\Plugin\Field\FieldWidget\PostViewWidget::buildEntitySpecificOptions
   */
  public function testPostOptionsUseSharedSubsection() {
    $this->manager->method('getDefaultParamValue')
      ->with('post_field_options')
      ->willReturn(['show_eyebrow' => 'show_eyebrow']);
    $form = [];
    $this->invoke($this->widget(), 'buildEntitySpecificOptions', [&$form, $this->items('{}'), 0]);
    $element = $form['group_user_selection']['entity_and_view_mode']['post_field_options'];

    $this->assertSame('<div class="vb-result-content__subsection">', $element['#prefix']);
    $this->assertSame(['show_eyebrow' => 'show_eyebrow'], $element['#default_value']);
  }

  /**
   * The term and sort selects are validated by Form API (F19).
   *
   * @covers ::buildExposedFilterControls
   * @covers ::buildSortControl
   */
  public function testSelectsAreNotFlaggedValidated() {
    $form = [];
    $widget = $this->widget();
    $this->invoke($widget, 'buildExposedFilterControls', [&$form, $this->items(NULL), 0, $this->selectors()]);
    $this->invoke($widget, 'buildSortControl', [&$form, $this->items(NULL), 0]);

    $elements = [
      $form['group_user_selection']['entity_and_view_mode']['category_included_terms'],
      $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'],
      $form['group_user_selection']['filter_and_sort']['sort_by'],
    ];
    foreach ($elements as $element) {
      $this->assertArrayNotHasKey('#validated', $element);
    }
    // The full allowed sets, as the manager reports them.
    $this->assertSame(['', 4], array_keys($elements[0]['#options']));
    $this->assertSame(['date_desc', 'title_asc'], array_keys($elements[2]['#options']));
  }

  /**
   * A stored choice the select no longer offers falls back to none (F19).
   *
   * Without '#validated', sending a deleted parent term back would fail the
   * illegal-choice check and block the save.
   *
   * @covers ::buildExposedFilterControls
   * @covers ::buildSortControl
   */
  public function testStaleStoredChoicesAreDropped() {
    $this->manager->method('getDefaultParamValue')->willReturnMap([
      ['category_filter_label', '{}', NULL],
      ['category_included_terms', '{}', '99'],
      ['custom_vocab_included_terms', '{}', '4'],
      ['exposed_filter_options', '{}', []],
      ['sort_by', '{}', 'gone_sort'],
    ]);
    $form = [];
    $widget = $this->widget();
    $this->invoke($widget, 'buildExposedFilterControls', [&$form, $this->items('{}'), 0, $this->selectors()]);
    $this->invoke($widget, 'buildSortControl', [&$form, $this->items('{}'), 0]);

    $this->assertNull($form['group_user_selection']['entity_and_view_mode']['category_included_terms']['#default_value']);
    $this->assertSame('4', $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms']['#default_value']);
    $this->assertNull($form['group_user_selection']['filter_and_sort']['sort_by']['#default_value']);
  }

  /**
   * The module declares the modules it uses and the core it supports (F13).
   */
  public function testInfoDeclaresDependencies() {
    $info = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/ys_views_basic.info.yml'));

    $this->assertSame('^10 || ^11', $info['core_version_requirement']);
    foreach ([
      'drupal:block_content',
      'drupal:layout_builder',
      'drupal:node',
      'drupal:taxonomy',
      'yalesites_profile:ys_core',
    ] as $dependency) {
      $this->assertContains($dependency, $info['dependencies']);
    }
  }

}
