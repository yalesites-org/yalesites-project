<?php

namespace Drupal\Tests\ys_views_content_resources\Unit\Plugin\Field\FieldWidget;

use PHPUnit\Framework\Error\Warning;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_content_resources\Plugin\Field\FieldWidget\ViewsContentResourcesDefaultWidget;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Unit tests for the ViewsContentResourcesDefaultWidget field widget.
 *
 * The manager service and the taxonomy vocabulary storage are mocked so the
 * widget's own form-building and value-massaging logic can be exercised in
 * isolation from the manager's (separately tested) business logic.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\Field\FieldWidget\ViewsContentResourcesDefaultWidget
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesDefaultWidgetTest extends UnitTestCase {

  /**
   * The manager service mock.
   *
   * @var \Drupal\ys_views_content_resources\ViewsContentResourcesManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $manager;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The widget under test.
   *
   * @var \Drupal\ys_views_content_resources\Plugin\Field\FieldWidget\ViewsContentResourcesDefaultWidget
   */
  protected $widget;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->manager = $this->createMock(ViewsContentResourcesManager::class);
    $this->manager->method('viewModeList')->willReturn([
      'card' => 'Card Grid',
      'list_item' => 'List',
    ]);
    $this->manager->method('sortByList')->willReturn([
      'field_publish_date:DESC' => 'Published Date - newer first',
    ]);
    $this->manager->method('getTaxonomyParents')->willReturn(['' => '-- All Items --']);
    $this->manager->method('getAllTags')->willReturn([1 => 'Music (Tags)']);
    $this->manager->method('getFormSelectors')->willReturn([
      'view_mode_input_selector' => ':input[name="view_mode"]',
      'show_search_filter_selector' => ':input[name="show_search_filter"]',
      'show_category_filter_selector' => ':input[name="show_category_filter"]',
      'show_custom_vocab_filter_selector' => ':input[name="show_custom_vocab_filter"]',
      'pinned_to_top_selector' => ':input[name="pinned_to_top"]',
      'massage_terms_include_array' => ['terms_include'],
      'massage_terms_exclude_array' => ['terms_exclude'],
      'sort_by_array' => ['sort_by'],
      'display_array' => ['display'],
      'limit_array' => ['limit'],
      'offset_array' => ['offset'],
      'pinned_to_top_array' => ['pinned_to_top'],
      'pin_label_array' => ['pin_label'],
    ]);

    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('label')->willReturn('Custom Vocab');
    $vocabularyStorage = $this->createMock('Drupal\taxonomy\VocabularyStorageInterface');
    $vocabularyStorage->method('load')->with('custom_vocab')->willReturn($vocabulary);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_vocabulary')->willReturn($vocabularyStorage);

    $fieldDefinition = $this->createMock(FieldDefinitionInterface::class);

    $this->widget = new ViewsContentResourcesDefaultWidget(
      'views_content_resources_default_widget',
      [],
      $fieldDefinition,
      [],
      [],
      $this->manager,
      $this->entityTypeManager
    );
    $this->widget->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a mock field item list whose delta 0 exposes a 'params' property.
   *
   * @param string|null $params
   *   The raw stored params string, or NULL for an unset field.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock item list.
   */
  protected function createItemsWithParams(?string $params): FieldItemListInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('offsetGet')->willReturn((object) ['params' => $params]);
    // The widget's params textarea default reads `$items[$delta]->params ??
    // NULL`; PHP's `??` on an ArrayAccess object checks offsetExists()
    // first, so that must be stubbed too or the coalesce always sees "unset".
    $items->method('offsetExists')->willReturn(TRUE);
    return $items;
  }

  /**
   * Builds a mock form state with the given complete-form '#id'.
   *
   * @param string $formId
   *   The value formElement() checks for the "new form" special case.
   *
   * @return \Drupal\Core\Form\FormStateInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock form state.
   */
  protected function createFormState(string $formId = 'node-resource-form'): FormStateInterface {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getCompleteForm')->willReturn(['#id' => $formId]);
    $formState->method('getValue')->willReturn(NULL);
    return $formState;
  }

  /**
   * FormElement() carries the manager's selectors onto the form array.
   *
   * @covers ::formElement
   */
  public function testFormElementAttachesFormSelectorsToForm() {
    $storedParams = json_encode(['filters' => ['types' => ['resource']]]);
    $items = $this->createItemsWithParams($storedParams);
    $form = [];
    $element = $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame(':input[name="view_mode"]', $form['#form_selectors']['view_mode_input_selector']);
    $this->assertContains('ys_views_basic/ys_views_basic', $form['#attached']['library']);
    $this->assertSame('container', $element['group_params']['#type']);
    $this->assertSame($storedParams, $element['group_params']['params']['#default_value']);
  }

  /**
   * The 'view_mode' radios default to the stored value when it is valid.
   *
   * @covers ::formElement
   */
  public function testFormElementViewModeDefaultsToStoredValueWhenValid() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'view_mode' ? 'list_item' : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'view_mode' => 'list_item']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame('list_item', $form['group_user_selection']['entity_and_view_mode']['view_mode']['#default_value']);
  }

  /**
   * An invalid/unknown stored view mode falls back to the first option.
   *
   * @covers ::formElement
   */
  public function testFormElementViewModeFallsBackToFirstOptionWhenInvalid() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'view_mode' ? 'no_longer_exists' : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'view_mode' => 'no_longer_exists']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    // 'card' is the first key in the mocked viewModeList() options.
    $this->assertSame('card', $form['group_user_selection']['entity_and_view_mode']['view_mode']['#default_value']);
  }

  /**
   * A brand-new block defaults field_options to thumbnail + teaser text.
   *
   * A content type is already selected (isolating this test from the
   * separately documented GAP around an unset content type), but no
   * field_options have been stored yet.
   *
   * @covers ::formElement
   */
  public function testFormElementNewBlockDefaultsFieldOptionsToThumbnailAndTeaser() {
    $params = json_encode(['filters' => ['types' => ['resource']]]);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement(
      $items,
      0,
      [],
      $form,
      $this->createFormState('layout-builder-add-block-abc')
    );

    $this->assertSame(
      ['show_thumbnail', 'show_teaser_text'],
      $form['group_user_selection']['entity_and_view_mode']['field_options']['#default_value']
    );
  }

  /**
   * An existing block with an empty stored field_options value stays empty.
   *
   * Only a *new* Layout Builder "add block" form gets the thumbnail/teaser
   * default; an existing (previously saved) block with stored params keeps
   * whatever the manager resolved, even an empty array -- the `??`
   * fallback below only triggers on a literal NULL, not an empty array.
   *
   * @covers ::formElement
   */
  public function testFormElementExistingBlockWithEmptyFieldOptionsStaysEmpty() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'field_options' ? [] : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'view_mode' => 'card']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame(
      [],
      $form['group_user_selection']['entity_and_view_mode']['field_options']['#default_value']
    );
  }

  /**
   * Field_options falls back to thumbnail/category only when NULL.
   *
   * The manager's real getDefaultParamValue() never actually returns NULL
   * for 'field_options' (it defaults to an empty array), so in practice
   * this fallback is defensive/unreachable -- but it is what the code says,
   * so it is characterized here.
   *
   * @covers ::formElement
   */
  public function testFormElementFieldOptionsFallsBackToThumbnailAndCategoryWhenManagerReturnsNull() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'field_options' ? NULL : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'view_mode' => 'card']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame(
      ['show_thumbnail' => 'show_thumbnail', 'show_category' => 'show_category'],
      $form['group_user_selection']['entity_and_view_mode']['field_options']['#default_value']
    );
  }

  /**
   * The items-per-page field is titled "Items" for the "limit" display mode.
   *
   * @covers ::formElement
   */
  public function testFormElementLimitTitleForLimitDisplay() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'display' ? 'limit' : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'display' => 'limit']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame('Items', (string) $form['group_user_selection']['options']['limit']['#title']);
  }

  /**
   * The items-per-page field is retitled "Items per Page" for pagination.
   *
   * @covers ::formElement
   */
  public function testFormElementLimitTitleForPagerDisplay() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'display' ? 'pager' : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'display' => 'pager']);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame('Items per Page', (string) $form['group_user_selection']['options']['limit']['#title']);
  }

  /**
   * Pin_label falls back to the DEFAULT_PIN_LABEL constant when NULL.
   *
   * Exercises the outer `?? DEFAULT_PIN_LABEL` fallback, which only matters
   * when getDefaultParamValue() itself returns NULL for 'pin_label'.
   *
   * @covers ::formElement
   */
  public function testFormElementPinLabelFallsBackToConstantWhenManagerReturnsNull() {
    $this->manager->method('getDefaultParamValue')->willReturnCallback(
      fn($type) => $type === 'pin_label' ? NULL : NULL
    );

    $params = json_encode(['filters' => ['types' => ['resource']], 'pin_label' => NULL]);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertSame(
      ViewsContentResourcesManager::DEFAULT_PIN_LABEL,
      $form['group_user_selection']['filter_and_sort']['pin_label']['#default_value']
    );
  }

  /**
   * The custom vocabulary's real label is used to title its filter options.
   *
   * @covers ::formElement
   */
  public function testFormElementUsesCustomVocabularyLabel() {
    $params = json_encode(['filters' => ['types' => ['resource']]]);
    $items = $this->createItemsWithParams($params);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertStringContainsString(
      'Custom Vocab',
      (string) $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options']['#options']['show_custom_vocab_filter']
    );
  }

  /**
   * Current behavior: reading an unset $entityValue fatals in strict tests.
   *
   * ViewsContentResourcesDefaultWidget::formElement() only assigns
   * $entityValue inside `if (!empty($decodedParams['filters']['types'][0]))`,
   * then unconditionally reads it a few lines later when calling
   * getFormSelectors(). That is an "Undefined variable $entityValue" PHP
   * warning whenever a block has no stored content type yet (e.g. a
   * brand-new, unsaved block) -- which, under PHPUnit's strict
   * warnings-as-errors handling, is fatal. In production Drupal the warning
   * is only logged and execution continues with NULL; $entityValue is not
   * even read by getFormSelectors() itself, so real users see no visible
   * effect -- but it is still a real bug. Paired with
   * testFormElementShouldNotErrorWithoutStoredContentType() -- delete once
   * the GAP is fixed.
   *
   * @covers ::formElement
   */
  public function testFormElementCurrentBehaviorErrorsWithoutStoredContentType() {
    $this->expectException(Warning::class);
    $this->expectExceptionMessage('Undefined variable $entityValue');

    $items = $this->createItemsWithParams(NULL);
    $form = [];
    $this->widget->formElement($items, 0, [], $form, $this->createFormState());
  }

  /**
   * Paired with testFormElementCurrentBehaviorErrorsWithoutStoredContentType().
   *
   * @covers ::formElement
   */
  public function testFormElementShouldNotErrorWithoutStoredContentType() {
    $this->markTestSkipped('GAP: ViewsContentResourcesDefaultWidget::formElement() reads $entityValue without initializing it when the stored params have no filters.types[0] (e.g. a brand-new, unsaved block) -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_views_content_resources.md');

    $items = $this->createItemsWithParams(NULL);
    $form = [];
    $element = $this->widget->formElement($items, 0, [], $form, $this->createFormState());

    $this->assertIsArray($element);
  }

  /**
   * ValidateSearchFields() errors when search is enabled with no fields.
   *
   * @covers ::validateSearchFields
   */
  public function testValidateSearchFieldsErrorsWhenSearchEnabledWithNoneSelected() {
    $element = ['#parents' => ['group_user_selection', 'entity_and_view_mode', 'search_fields'], '#value' => []];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')
      ->with(['group_user_selection', 'entity_and_view_mode', 'exposed_filter_options', 'show_search_filter'])
      ->willReturn(TRUE);
    $formState->expects($this->once())->method('setError');

    ViewsContentResourcesDefaultWidget::validateSearchFields($element, $formState);
  }

  /**
   * ValidateSearchFields() passes when at least one field is selected.
   *
   * @covers ::validateSearchFields
   */
  public function testValidateSearchFieldsPassesWhenFieldsSelected() {
    $element = [
      '#parents' => [
        'group_user_selection',
        'entity_and_view_mode',
        'search_fields',
      ],
      '#value' => ['title' => 'title'],
    ];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturn(TRUE);
    $formState->expects($this->never())->method('setError');

    ViewsContentResourcesDefaultWidget::validateSearchFields($element, $formState);
  }

  /**
   * ValidateSearchFields() does not error when search is disabled.
   *
   * @covers ::validateSearchFields
   */
  public function testValidateSearchFieldsSkipsWhenSearchDisabled() {
    $element = ['#parents' => ['group_user_selection', 'entity_and_view_mode', 'search_fields'], '#value' => []];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturn(FALSE);
    $formState->expects($this->never())->method('setError');

    ViewsContentResourcesDefaultWidget::validateSearchFields($element, $formState);
  }

  /**
   * MassageFormValues() encodes the submitted form values into JSON params.
   *
   * @covers ::massageFormValues
   */
  public function testMassageFormValuesEncodesFormStructureToJson() {
    // Uses the selector map already configured in setUp().
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturnMap([
      [['terms_include'], NULL, [1, 2]],
      [['terms_exclude'], NULL, [3]],
      [['sort_by'], NULL, 'field_publish_date:DESC'],
      [['display'], NULL, 'limit'],
      [['limit'], NULL, '10'],
      [['offset'], NULL, '0'],
      [['pinned_to_top'], NULL, TRUE],
      [['pin_label'], NULL, 'Featured'],
    ]);

    $form = [
      'group_user_selection' => [
        'entity_and_view_mode' => [
          'view_mode' => ['#value' => 'card'],
          'field_options' => ['#value' => ['show_thumbnail' => 'show_thumbnail']],
          'exposed_filter_options' => ['#value' => ['show_year_filter' => 'show_year_filter']],
          'search_fields' => ['#value' => ['title' => 'title']],
          'category_filter_label' => ['#value' => 'Category'],
          'category_included_terms' => ['#value' => ''],
          'custom_vocab_included_terms' => ['#value' => ''],
        ],
        'filter_and_sort' => [
          'term_operator' => ['#value' => '+'],
        ],
        'options' => [
          'show_current_entity' => ['#value' => 0],
        ],
      ],
    ];

    $values = [['params' => NULL]];
    $result = $this->widget->massageFormValues($values, $form, $formState);

    $decoded = json_decode($result[0]['params'], TRUE);

    $this->assertSame('card', $decoded['view_mode']);
    $this->assertSame(['show_thumbnail' => 'show_thumbnail'], $decoded['field_options']);
    $this->assertSame(['resource'], $decoded['filters']['types']);
    $this->assertSame([1, 2], $decoded['filters']['terms_include']);
    $this->assertSame([3], $decoded['filters']['terms_exclude']);
    $this->assertSame('field_publish_date:DESC', $decoded['sort_by']);
    $this->assertSame('limit', $decoded['display']);
    $this->assertSame(10, $decoded['limit']);
    $this->assertSame(0, $decoded['offset']);
    $this->assertTrue($decoded['pinned_to_top']);
    $this->assertSame('Featured', $decoded['pin_label']);
  }

}
