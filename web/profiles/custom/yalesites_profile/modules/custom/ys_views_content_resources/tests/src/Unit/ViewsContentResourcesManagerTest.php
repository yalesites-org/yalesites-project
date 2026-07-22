<?php

namespace Drupal\Tests\ys_views_content_resources\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;

/**
 * Unit tests for ViewsContentResourcesManager.
 *
 * Characterizes the current behavior of the service that builds the
 * "content_resources" view's option lists, default values, and form
 * selectors from the field's stored JSON parameters. Query/view
 * construction (setupView(), getView(), initView()) is not covered here --
 * see the module's test log for why.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\ViewsContentResourcesManager
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesManagerTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The entity display repository mock.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepository|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityDisplayRepository;

  /**
   * The term storage mock, returned by the entity type manager.
   *
   * @var \Drupal\taxonomy\TermStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $termStorage;

  /**
   * The vocabulary storage mock, returned by the entity type manager.
   *
   * @var \Drupal\taxonomy\VocabularyStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $vocabularyStorage;

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The cache tags invalidator mock.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cacheTagsInvalidator;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_views_content_resources\ViewsContentResourcesManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->termStorage = $this->createMock(TermStorageInterface::class);
    $this->vocabularyStorage = $this->createMock('Drupal\taxonomy\VocabularyStorageInterface');

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['taxonomy_term', $this->termStorage],
        ['taxonomy_vocabulary', $this->vocabularyStorage],
      ]);

    $this->entityDisplayRepository = $this->createMock(EntityDisplayRepository::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    $this->manager = new ViewsContentResourcesManager(
      $this->entityTypeManager,
      $this->entityDisplayRepository,
      $this->routeMatch,
      $this->cacheTagsInvalidator
    );
  }

  /**
   * Creates a mock taxonomy term entity (as returned by loadMultiple/load).
   *
   * @param int $id
   *   The term ID.
   * @param string $name
   *   The term label.
   * @param string $bundle
   *   The vocabulary machine name.
   *
   * @return \Drupal\taxonomy\TermInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock term entity.
   */
  protected function createMockTermEntity($id, $name, $bundle) {
    $term = $this->createMock('Drupal\taxonomy\TermInterface');
    $term->method('id')->willReturn($id);
    $term->method('label')->willReturn($name);
    $term->method('bundle')->willReturn($bundle);
    // GetTagLabel() reads name->value directly rather than calling label().
    $term->name = (object) ['value' => $name];
    return $term;
  }

  /**
   * Creates a mock vocabulary entity.
   *
   * @param string $id
   *   The vocabulary machine name.
   * @param string $label
   *   The vocabulary label.
   *
   * @return \Drupal\taxonomy\VocabularyInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock vocabulary.
   */
  protected function createVocabularyMock($id, $label) {
    $vocabulary = $this->createMock('Drupal\taxonomy\VocabularyInterface');
    $vocabulary->method('id')->willReturn($id);
    $vocabulary->method('label')->willReturn($label);
    return $vocabulary;
  }

  /**
   * Builds a stdClass tree item as returned by TermStorage::loadTree().
   *
   * @param int $tid
   *   The term ID.
   * @param string $name
   *   The term name.
   *
   * @return \stdClass
   *   The tree item.
   */
  protected function createTreeItem($tid, $name) {
    $item = new \stdClass();
    $item->tid = $tid;
    $item->name = $name;
    return $item;
  }

  /**
   * The create() factory builds the manager from the container's services.
   *
   * @covers ::create
   * @covers ::__construct
   */
  public function testCreateBuildsManagerFromContainer() {
    $container = $this->createMock('Symfony\Component\DependencyInjection\ContainerInterface');
    $container->method('get')
      ->willReturnMap([
        ['entity_type.manager', 1, $this->entityTypeManager],
        ['entity_display.repository', 1, $this->entityDisplayRepository],
        ['current_route_match', 1, $this->routeMatch],
        ['cache_tags.invalidator', 1, $this->cacheTagsInvalidator],
      ]);

    $manager = ViewsContentResourcesManager::create($container);
    $this->assertInstanceOf(ViewsContentResourcesManager::class, $manager);
  }

  /**
   * ViewModeList() returns the four allowed view modes with image markup.
   *
   * @covers ::viewModeList
   */
  public function testViewModeListReturnsAllowedModesWithImageMarkup() {
    $list = $this->manager->viewModeList();

    $this->assertSame(['card', 'portrait_grid', 'list_item', 'condensed'], array_keys($list));
    $this->assertStringContainsString('Card Grid', $list['card']);
    $this->assertStringContainsString('<img src=', $list['card']);
  }

  /**
   * SortByList() returns the raw sort_by map, keyed by field and direction.
   *
   * @covers ::sortByList
   */
  public function testSortByListReturnsPublishDateOptions() {
    $this->assertSame([
      'field_publish_date:DESC' => 'Published Date - newer first',
      'field_publish_date:ASC' => 'Published Date - older first',
    ], $this->manager->sortByList());
  }

  /**
   * GetLabel() always returns the constant 'Resources' string.
   *
   * Unlike the sibling ViewsBasicManager, this manager only ever manages one
   * content type, so getLabel() ignores its arguments entirely.
   *
   * @covers ::getLabel
   */
  public function testGetLabelAlwaysReturnsResources() {
    $this->assertSame('Resources', $this->manager->getLabel('resource', 'entity'));
    $this->assertSame('Resources', $this->manager->getLabel('resource', 'sort_by', 'field_publish_date:DESC'));
  }

  /**
   * GetTagLabel() returns the term's name when the term exists.
   *
   * @covers ::getTagLabel
   */
  public function testGetTagLabelWithExistingTerm() {
    $term = $this->createMockTermEntity(5, 'Music', 'tags');
    $this->termStorage->method('load')->with(5)->willReturn($term);

    $this->assertSame('Music', $this->manager->getTagLabel(5));
  }

  /**
   * GetTagLabel() returns an empty string when the term cannot be loaded.
   *
   * @covers ::getTagLabel
   */
  public function testGetTagLabelWithMissingTermReturnsEmptyString() {
    $this->termStorage->method('load')->with(999)->willReturn(NULL);

    $this->assertSame('', $this->manager->getTagLabel(999));
  }

  /**
   * GetDefaultParamValue('types', ...) returns the first selected type.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueTypesReturnsFirstType() {
    $params = json_encode(['filters' => ['types' => ['resource', 'post']]]);
    $this->assertSame('resource', $this->manager->getDefaultParamValue('types', $params));
  }

  /**
   * GetDefaultParamValue('terms_include', ...) resolves plain string term IDs.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueTermsIncludeWithPlainIds() {
    $params = json_encode(['filters' => ['terms_include' => ['3', '7']]]);
    $this->assertSame([3, 7], $this->manager->getDefaultParamValue('terms_include', $params));
  }

  /**
   * GetDefaultParamValue() resolves legacy target_id arrays for terms_exclude.
   *
   * Older stored values represent a referenced term as an array with a
   * 'target_id' key rather than a bare ID string; getTermId() normalizes
   * either shape to an integer.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueTermsExcludeWithLegacyTargetIdArrays() {
    $params = json_encode(['filters' => ['terms_exclude' => [['target_id' => '9']]]]);
    $this->assertSame([9], $this->manager->getDefaultParamValue('terms_exclude', $params));
  }

  /**
   * GetDefaultParamValue('view_mode', ...) defaults to 'card' when unset.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueViewModeDefaultsToCard() {
    $this->assertSame('card', $this->manager->getDefaultParamValue('view_mode', json_encode([])));
  }

  /**
   * GetDefaultParamValue('view_mode', ...) returns the stored value when set.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueViewModeReturnsStoredValue() {
    $params = json_encode(['view_mode' => 'list_item']);
    $this->assertSame('list_item', $this->manager->getDefaultParamValue('view_mode', $params));
  }

  /**
   * GetDefaultParamValue('operator', ...) defaults to '+' (OR).
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueOperatorDefaultsToPlus() {
    $this->assertSame('+', $this->manager->getDefaultParamValue('operator', json_encode([])));
  }

  /**
   * GetDefaultParamValue('limit', ...) defaults to 10 and casts to int.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueLimitDefaultsToTenAndCasts() {
    $this->assertSame(10, $this->manager->getDefaultParamValue('limit', json_encode([])));
    $this->assertSame(5, $this->manager->getDefaultParamValue('limit', json_encode(['limit' => '5'])));
  }

  /**
   * GetDefaultParamValue('offset', ...) defaults to 0 and casts to int.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueOffsetDefaultsToZeroAndCasts() {
    $this->assertSame(0, $this->manager->getDefaultParamValue('offset', json_encode([])));
    $this->assertSame(3, $this->manager->getDefaultParamValue('offset', json_encode(['offset' => '3'])));
  }

  /**
   * GetDefaultParamValue('exposed_filter_options', ...) default/pass-through.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueExposedFilterOptionsDefaultsAndPassesThrough() {
    $this->assertSame([], $this->manager->getDefaultParamValue('exposed_filter_options', json_encode([])));

    $params = json_encode(['exposed_filter_options' => ['show_year_filter' => 1]]);
    $this->assertSame(
      ['show_year_filter' => 1],
      $this->manager->getDefaultParamValue('exposed_filter_options', $params)
    );
  }

  /**
   * GetDefaultParamValue() for the nullable-by-default label/term options.
   *
   * Category_filter_label, category_included_terms, and
   * custom_vocab_included_terms all default to NULL.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueNullableOptionsDefaultToNull() {
    $this->assertNull($this->manager->getDefaultParamValue('category_filter_label', json_encode([])));
    $this->assertNull($this->manager->getDefaultParamValue('category_included_terms', json_encode([])));
    $this->assertNull($this->manager->getDefaultParamValue('custom_vocab_included_terms', json_encode([])));

    $params = json_encode(['category_filter_label' => 'Custom Label']);
    $this->assertSame('Custom Label', $this->manager->getDefaultParamValue('category_filter_label', $params));
  }

  /**
   * GetDefaultParamValue('show_current_entity', ...) defaults to 0.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueShowCurrentEntityDefaultsToZero() {
    $this->assertSame(0, $this->manager->getDefaultParamValue('show_current_entity', json_encode([])));

    $params = json_encode(['show_current_entity' => 1]);
    $this->assertSame(1, $this->manager->getDefaultParamValue('show_current_entity', $params));
  }

  /**
   * GetDefaultParamValue('pinned_to_top', ...) defaults to FALSE and casts.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValuePinnedToTopDefaultsToFalseAndCasts() {
    $this->assertFalse($this->manager->getDefaultParamValue('pinned_to_top', json_encode([])));

    $params = json_encode(['pinned_to_top' => 1]);
    $this->assertTrue($this->manager->getDefaultParamValue('pinned_to_top', $params));
  }

  /**
   * GetDefaultParamValue('pin_label', ...) defaults to the "Pinned" constant.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValuePinLabelDefaultsToConstant() {
    $this->assertSame(
      ViewsContentResourcesManager::DEFAULT_PIN_LABEL,
      $this->manager->getDefaultParamValue('pin_label', json_encode([]))
    );

    $params = json_encode(['pin_label' => 'Featured']);
    $this->assertSame('Featured', $this->manager->getDefaultParamValue('pin_label', $params));
  }

  /**
   * GetDefaultParamValue('field_options', ...) defaults to an empty array.
   *
   * A non-array stored value is also treated as absent.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueFieldOptionsDefaultsToEmptyArray() {
    $this->assertSame([], $this->manager->getDefaultParamValue('field_options', json_encode([])));
    $this->assertSame([], $this->manager->getDefaultParamValue('field_options', json_encode(['field_options' => 'not-an-array'])));

    $params = json_encode(['field_options' => ['show_authors' => TRUE]]);
    $this->assertSame(['show_authors' => TRUE], $this->manager->getDefaultParamValue('field_options', $params));
  }

  /**
   * GetDefaultParamValue() defaults search_fields to the title/teaser set.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueSearchFieldsDefaultsToTitleAndTeaser() {
    $expectedDefault = [
      'title' => 'title',
      'field_teaser_text' => 'field_teaser_text',
      'field_teaser_title' => 'field_teaser_title',
    ];
    $this->assertSame($expectedDefault, $this->manager->getDefaultParamValue('search_fields', json_encode([])));
    // A non-array stored value also falls back to the default.
    $this->assertSame($expectedDefault, $this->manager->getDefaultParamValue('search_fields', json_encode(['search_fields' => 'title'])));
  }

  /**
   * GetDefaultParamValue('search_fields', ...) passes through a stored array.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueSearchFieldsPassesThroughStoredArray() {
    $params = json_encode(['search_fields' => ['title' => 'title']]);
    $this->assertSame(['title' => 'title'], $this->manager->getDefaultParamValue('search_fields', $params));
  }

  /**
   * GetDefaultParamValue() for an unrecognized type reads the raw key.
   *
   * The default case in the switch falls through to a direct array lookup
   * on the decoded params, with no isset() guard.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueUnknownTypeReadsRawKey() {
    $params = json_encode(['some_custom_key' => 'raw value']);
    $this->assertSame('raw value', $this->manager->getDefaultParamValue('some_custom_key', $params));
    $this->assertNull($this->manager->getDefaultParamValue('missing_key', json_encode([])));
  }

  /**
   * GetAllTags() returns an empty array when the term query finds no IDs.
   *
   * @covers ::getAllTags
   */
  public function testGetAllTagsReturnsEmptyArrayWithNoResults() {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->termStorage->method('getQuery')->willReturn($query);

    $this->assertSame([], $this->manager->getAllTags());
  }

  /**
   * GetAllTags() loads every term, sorted by "name (vocabulary)" label.
   *
   * @covers ::getAllTags
   */
  public function testGetAllTagsReturnsSortedLabelsWithVocabulary() {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1, 2]);
    $this->termStorage->method('getQuery')->willReturn($query);

    $this->vocabularyStorage->method('load')->willReturnMap([
      ['tags', $this->createVocabularyMock('tags', 'Tags')],
      ['resource_category', $this->createVocabularyMock('resource_category', 'Resource Category')],
    ]);

    $termA = $this->createMockTermEntity(1, 'Zebra', 'tags');
    $termB = $this->createMockTermEntity(2, 'Apple', 'resource_category');
    $this->termStorage->method('loadMultiple')->with([1, 2])->willReturn([$termA, $termB]);

    $tags = $this->manager->getAllTags();

    // Asort() preserves keys while sorting alphabetically by label value.
    $this->assertSame([2, 1], array_keys($tags));
    $this->assertSame('Apple (Resource Category)', $tags[2]);
    $this->assertSame('Zebra (Tags)', $tags[1]);
  }

  /**
   * GetTaxonomyParents() lists top-level terms with an "All Items" option.
   *
   * @covers ::getTaxonomyParents
   */
  public function testGetTaxonomyParentsIncludesAllItemsOption() {
    $this->termStorage->method('loadTree')
      ->with('resource_category', 0, 1)
      ->willReturn([$this->createTreeItem(4, 'Journals')]);

    $parents = $this->manager->getTaxonomyParents('resource_category');

    $this->assertSame(['' => '-- All Items --', 4 => 'Journals'], $parents);
  }

  /**
   * GetChildTermsByParentId() lists descendant term IDs keyed by themselves.
   *
   * @covers ::getChildTermsByParentId
   */
  public function testGetChildTermsByParentIdReturnsDescendantIds() {
    $this->termStorage->method('loadTree')
      ->with('resource_category', 4, NULL)
      ->willReturn([$this->createTreeItem(5, 'Articles'), $this->createTreeItem(6, 'Reports')]);

    $children = $this->manager->getChildTermsByParentId(4, 'resource_category');

    $this->assertSame([5 => 5, 6 => 6], $children);
  }

  /**
   * GetTermId() (private) normalizes both legacy and current term shapes.
   *
   * @covers ::getTermId
   */
  public function testGetTermIdNormalizesLegacyAndCurrentShapes() {
    $reflection = new \ReflectionClass($this->manager);
    $method = $reflection->getMethod('getTermId');
    $method->setAccessible(TRUE);

    $this->assertSame(12, $method->invoke($this->manager, '12'));
    $this->assertSame(12, $method->invoke($this->manager, ['target_id' => '12']));
  }

  /**
   * GetFormSelectors() for a reusable Layout Builder block form.
   *
   * @covers ::getFormSelectors
   */
  public function testGetFormSelectorsForReusableLayoutBuilderBlock() {
    $block = $this->createMock('Drupal\block_content\BlockContentInterface');
    $block->method('isReusable')->willReturn(TRUE);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getCompleteForm')->willReturn([
      '#form_id' => 'layout_builder_add_block',
      'block_form' => ['#block' => $block],
    ]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState);

    $this->assertSame(
      ':input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]',
      $selectors['view_mode_input_selector']
    );
    $this->assertSame([
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'terms_include',
    ], $selectors['massage_terms_include_array']);
    $this->assertSame(':input[name="block_form[group_user_selection][options][display]"]', $selectors['display_ajax']);
    $this->assertSame(['block_form', 'group_user_selection', 'options', 'offset'], $selectors['offset_array']);
    $this->assertSame(
      ':input[name="block_form[group_user_selection][filter_and_sort][pinned_to_top]"]',
      $selectors['pinned_to_top_selector']
    );
    // With no $form passed, the ajax-populated entries fall back to NULL/the
    // pin label default.
    $this->assertNull($selectors['pinned_to_top']);
    $this->assertSame(ViewsContentResourcesManager::DEFAULT_PIN_LABEL, $selectors['pin_label']);
  }

  /**
   * GetFormSelectors() for a non-reusable (regular) Layout Builder block.
   *
   * @covers ::getFormSelectors
   */
  public function testGetFormSelectorsForRegularLayoutBuilderBlock() {
    $block = $this->createMock('Drupal\block_content\BlockContentInterface');
    $block->method('isReusable')->willReturn(FALSE);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getCompleteForm')->willReturn([
      '#form_id' => 'layout_builder_update_block',
      'block_form' => ['#block' => $block],
    ]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState);

    $this->assertSame(
      ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]',
      $selectors['view_mode_input_selector']
    );
    $this->assertSame([
      'settings',
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'terms_include',
    ], $selectors['massage_terms_include_array']);
    $this->assertSame(':input[name="settings[block_form][group_user_selection][options][display]"]', $selectors['display_ajax']);
    $this->assertSame(
      ':input[name="settings[block_form][group_user_selection][filter_and_sort][pinned_to_top]"]',
      $selectors['pinned_to_top_selector']
    );
    $this->assertSame(ViewsContentResourcesManager::DEFAULT_PIN_LABEL, $selectors['pin_label_ajax']);
  }

  /**
   * GetFormSelectors() for a plain Drupal core block configuration form.
   *
   * @covers ::getFormSelectors
   */
  public function testGetFormSelectorsForCoreBlockForm() {
    $formState = $this->createMock(FormStateInterface::class);
    // An empty (falsy) complete form skips the Layout Builder branches.
    $formState->method('getCompleteForm')->willReturn([]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState);

    $this->assertSame(':input[name="view_mode"]', $selectors['view_mode_input_selector']);
    $this->assertSame(['terms_include'], $selectors['massage_terms_include_array']);
    $this->assertSame(':input[name="display"]', $selectors['display_ajax']);
    $this->assertSame(['offset'], $selectors['offset_array']);
    $this->assertSame(['pinned_to_top'], $selectors['pinned_to_top_array']);
    $this->assertSame(ViewsContentResourcesManager::DEFAULT_PIN_LABEL, $selectors['pin_label_ajax']);
  }

}
