<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Unit tests for ViewsBasicManager.
 *
 * Characterizes the current behavior of the service that builds Views Basic
 * option lists, default values, and form selectors from the field's stored
 * JSON parameters. Query/view construction (setupView(), getView(),
 * initView()) is not covered here -- see the module's test log for why.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicManagerTest extends UnitTestCase {

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
   * @var \Drupal\ys_views_basic\ViewsBasicManager
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

    $this->manager = new ViewsBasicManager(
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
    // getTagLabel() reads name->value directly rather than calling label().
    $term->name = (object) ['value' => $name];
    return $term;
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

    $manager = ViewsBasicManager::create($container);
    $this->assertInstanceOf(ViewsBasicManager::class, $manager);
  }

  /**
   * EntityTypeList() returns a label plus inline <img> markup, per entity.
   *
   * @covers ::entityTypeList
   */
  public function testEntityTypeListReturnsLabelsWithImageMarkup() {
    $list = $this->manager->entityTypeList();

    $this->assertSame(['post', 'event', 'page', 'profile'], array_keys($list));
    $this->assertStringContainsString('Posts', $list['post']);
    $this->assertStringContainsString('<img src=', $list['post']);
  }

  /**
   * ViewModeList() returns the view modes configured for a content type.
   *
   * @covers ::viewModeList
   */
  public function testViewModeListForPost() {
    $list = $this->manager->viewModeList('post');

    $this->assertSame(['card', 'list_item', 'condensed'], array_keys($list));
    $this->assertStringContainsString('Post Card Grid', $list['card']);
  }

  /**
   * ViewModeList() includes the "directory" mode only for profiles.
   *
   * @covers ::viewModeList
   */
  public function testViewModeListForProfileIncludesDirectory() {
    $list = $this->manager->viewModeList('profile');

    $this->assertArrayHasKey('directory', $list);
  }

  /**
   * SortByList() returns the raw sort_by map for a content type.
   *
   * @covers ::sortByList
   */
  public function testSortByListForEvent() {
    $list = $this->manager->sortByList('event');

    $this->assertSame([
      'field_event_date:DESC' => 'Event Date - newer first',
      'field_event_date:ASC' => 'Event Date - older first',
    ], $list);
  }

  /**
   * GetLabel() with 'entity' returns the content type's human label.
   *
   * @covers ::getLabel
   */
  public function testGetLabelEntity() {
    $this->assertSame('Pages', $this->manager->getLabel('page', 'entity'));
  }

  /**
   * GetLabel() with a 'sort_by' sub_param returns the matching label string.
   *
   * @covers ::getLabel
   */
  public function testGetLabelSortBySubParam() {
    $this->assertSame(
      'Publish Date - newer first',
      $this->manager->getLabel('post', 'sort_by', 'field_publish_date:DESC')
    );
  }

  /**
   * GetLabel() with a 'view_modes' sub_param returns the whole config array.
   *
   * Unlike 'sort_by', the view_modes entry is itself an array (label, img,
   * img_alt) rather than a plain string -- getLabel() returns it as-is.
   *
   * @covers ::getLabel
   */
  public function testGetLabelViewModeSubParamReturnsWholeArray() {
    $result = $this->manager->getLabel('post', 'view_modes', 'list_item');

    $this->assertIsArray($result);
    $this->assertSame('Post List', $result['label']);
  }

  /**
   * GetLabel() returns an empty string when no sub_param is given.
   *
   * @covers ::getLabel
   */
  public function testGetLabelReturnsEmptyStringWithoutSubParam() {
    $this->assertSame('', $this->manager->getLabel('post', 'view_modes'));
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
    $params = json_encode(['filters' => ['types' => ['event', 'post']]]);
    $this->assertSame('event', $this->manager->getDefaultParamValue('types', $params));
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
    $params = json_encode([]);
    $this->assertSame('card', $this->manager->getDefaultParamValue('view_mode', $params));
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
    $params = json_encode([]);
    $this->assertSame('+', $this->manager->getDefaultParamValue('operator', $params));
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
   * GetDefaultParamValue('event_time_period', ...) defaults to 'future'.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueEventTimePeriodDefaultsToFuture() {
    $this->assertSame('future', $this->manager->getDefaultParamValue('event_time_period', json_encode([])));

    $params = json_encode(['filters' => ['event_time_period' => 'past']]);
    $this->assertSame('past', $this->manager->getDefaultParamValue('event_time_period', $params));
  }

  /**
   * GetDefaultParamValue('field_options', ...) defaults show_thumbnail on.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueFieldOptionsDefaultsToShowThumbnail() {
    $this->assertSame(
      ['show_thumbnail' => 'show_thumbnail'],
      $this->manager->getDefaultParamValue('field_options', json_encode([]))
    );
  }

  /**
   * GetDefaultParamValue() for the simple optional-array options.
   *
   * Event_field_options, post_field_options, and exposed_filter_options all
   * default to an empty array and otherwise pass the stored value through.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueSimpleArrayOptionsDefaultAndPassThrough() {
    $this->assertSame([], $this->manager->getDefaultParamValue('event_field_options', json_encode([])));
    $this->assertSame([], $this->manager->getDefaultParamValue('post_field_options', json_encode([])));
    $this->assertSame([], $this->manager->getDefaultParamValue('exposed_filter_options', json_encode([])));

    $params = json_encode(['event_field_options' => ['hide_add_to_calendar' => 1]]);
    $this->assertSame(
      ['hide_add_to_calendar' => 1],
      $this->manager->getDefaultParamValue('event_field_options', $params)
    );
  }

  /**
   * GetDefaultParamValue() for the nullable-by-default term/label options.
   *
   * Category_filter_label, category_included_terms, audience_included_terms,
   * and custom_vocab_included_terms all default to NULL.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueNullableOptionsDefaultToNull() {
    $this->assertNull($this->manager->getDefaultParamValue('category_filter_label', json_encode([])));
    $this->assertNull($this->manager->getDefaultParamValue('category_included_terms', json_encode([])));
    $this->assertNull($this->manager->getDefaultParamValue('audience_included_terms', json_encode([])));
    $this->assertNull($this->manager->getDefaultParamValue('custom_vocab_included_terms', json_encode([])));

    $params = json_encode(['category_filter_label' => 'Custom Label']);
    $this->assertSame('Custom Label', $this->manager->getDefaultParamValue('category_filter_label', $params));
  }

  /**
   * GetDefaultParamValue('pin_label', ...) defaults to the "Pinned" constant.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValuePinLabelDefaultsToConstant() {
    $this->assertSame(
      ViewsBasicManager::DEFAULT_PIN_LABEL,
      $this->manager->getDefaultParamValue('pin_label', json_encode([]))
    );

    $params = json_encode(['pin_label' => 'Featured']);
    $this->assertSame('Featured', $this->manager->getDefaultParamValue('pin_label', $params));
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
  }

  /**
   * GetDefaultParamValue('show_current_entity', ...) returns its own value.
   *
   * With the `break;` after the show_current_entity case, execution no longer
   * falls through into pinned_to_top, so the show_current_entity default is
   * returned as-is rather than being overwritten by the pinned_to_top value.
   *
   * @covers ::getDefaultParamValue
   */
  public function testGetDefaultParamValueShowCurrentEntityReturnsItsOwnValue() {
    $params = json_encode(['show_current_entity' => 1, 'pinned_to_top' => FALSE]);
    $this->assertEquals(1, $this->manager->getDefaultParamValue('show_current_entity', $params));
  }

  /**
   * GetAllTags() loads every term, sorted by "name (vocabulary)" label.
   *
   * @covers ::getAllTags
   */
  public function testGetAllTagsReturnsSortedLabelsWithVocabulary() {
    $this->vocabularyStorage->method('load')->willReturnMap([
      ['tags', $this->createVocabularyMock('tags', 'Tags')],
      ['event_category', $this->createVocabularyMock('event_category', 'Event Category')],
    ]);

    $termA = $this->createMockTermEntity(1, 'Zebra', 'tags');
    $termB = $this->createMockTermEntity(2, 'Apple', 'event_category');
    $this->termStorage->method('loadMultiple')->willReturn([$termA, $termB]);

    $tags = $this->manager->getAllTags();

    // Sorted alphabetically by label value (asort preserves keys).
    $this->assertSame([2, 1], array_keys($tags));
    $this->assertSame('Apple (Event Category)', $tags[2]);
    $this->assertSame('Zebra (Tags)', $tags[1]);
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
   * GetEventTags() merges terms across the four event-related vocabularies.
   *
   * @covers ::getEventTags
   */
  public function testGetEventTagsMergesAcrossVocabularies() {
    $this->termStorage->method('loadTree')
      ->willReturnMap([
        ['event_category', 0, NULL, FALSE, [$this->createTreeItem(1, 'Lecture')]],
        ['audience', 0, NULL, FALSE, [$this->createTreeItem(2, 'Students')]],
        ['custom_vocab', 0, NULL, FALSE, []],
        ['tags', 0, NULL, FALSE, [$this->createTreeItem(3, 'Music')]],
      ]);

    $tags = $this->manager->getEventTags();

    // asort() sorts by label value, alphabetically: Lecture, Music, Students.
    $this->assertSame([1, 3, 2], array_keys($tags));
    $this->assertSame('Lecture (event_category)', $tags[1]);
    $this->assertSame('Music (tags)', $tags[3]);
    $this->assertSame('Students (audience)', $tags[2]);
  }

  /**
   * GetTaxonomyParents() lists top-level terms with an "All Items" option.
   *
   * @covers ::getTaxonomyParents
   */
  public function testGetTaxonomyParentsIncludesAllItemsOption() {
    $this->termStorage->method('loadTree')
      ->with('event_category', 0, 1)
      ->willReturn([$this->createTreeItem(4, 'Lectures')]);

    $parents = $this->manager->getTaxonomyParents('event_category');

    $this->assertSame(['' => '-- All Items --', 4 => 'Lectures'], $parents);
  }

  /**
   * GetChildTermsByParentId() lists descendant term IDs keyed by themselves.
   *
   * @covers ::getChildTermsByParentId
   */
  public function testGetChildTermsByParentIdReturnsDescendantIds() {
    $this->termStorage->method('loadTree')
      ->with('event_category', 4, NULL)
      ->willReturn([$this->createTreeItem(5, 'Concerts'), $this->createTreeItem(6, 'Readings')]);

    $children = $this->manager->getChildTermsByParentId(4, 'event_category');

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
    $formState->method('isRebuilding')->willReturn(TRUE);
    $formState->method('getValues')->willReturn([
      'block_form' => [
        'group_user_selection' => [
          'entity_and_view_mode' => ['entity_types' => 'event'],
        ],
      ],
    ]);
    $formState->method('getCompleteForm')->willReturn([
      '#form_id' => 'layout_builder_add_block',
      'block_form' => ['#block' => $block],
    ]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState, NULL, 'post');

    $this->assertSame('event', $selectors['entity_types']);
    $this->assertSame(
      ':input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]',
      $selectors['entity_types_ajax']
    );
    $this->assertSame([
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'terms_include',
    ], $selectors['massage_terms_include_array']);
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
    $formState->method('isRebuilding')->willReturn(TRUE);
    $formState->method('getValues')->willReturn([
      'settings' => [
        'block_form' => [
          'group_user_selection' => [
            'entity_and_view_mode' => ['entity_types' => 'post'],
          ],
        ],
      ],
    ]);
    $formState->method('getCompleteForm')->willReturn([
      '#form_id' => 'layout_builder_update_block',
      'block_form' => ['#block' => $block],
    ]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState, NULL, 'page');

    $this->assertSame('post', $selectors['entity_types']);
    $this->assertSame(
      ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]',
      $selectors['entity_types_ajax']
    );
  }

  /**
   * GetFormSelectors() for a plain Drupal core block configuration form.
   *
   * @covers ::getFormSelectors
   */
  public function testGetFormSelectorsForCoreBlockForm() {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('isRebuilding')->willReturn(TRUE);
    $formState->method('getValues')->willReturn(['entity_types' => 'profile']);
    // An empty (falsy) complete form skips the Layout Builder branches.
    $formState->method('getCompleteForm')->willReturn([]);
    $formState->method('getValue')->willReturn(NULL);

    $selectors = $this->manager->getFormSelectors($formState, NULL, 'post');

    $this->assertSame('profile', $selectors['entity_types']);
    $this->assertSame(':input[name="entity_types"]', $selectors['entity_types_ajax']);
    $this->assertSame(['terms_include'], $selectors['massage_terms_include_array']);
  }

}
