<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\ys_views_basic\Service\ExposedTaxonomyFilterOptions;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the resource listing variant of ViewsBasicManager (#1723).
 *
 * Resource listings used to be served by ViewsContentResourcesManager, a
 * near-copy of this manager. These cases pin the logic that was genuinely
 * resource-specific there, now that ViewsBasicManager serves resources itself:
 * the option lists, the extra exposed filters and search fields, the
 * resource field-display flags, and the normalisation that moves a stored
 * resource_view params blob onto this manager's keys.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class ResourceListingTest extends UnitTestCase {

  /**
   * A view display's filters, trimmed to the ones these tests touch.
   */
  const FILTERS = [
    'status' => [],
    'combine' => [
      'fields' => [
        'title' => 'title',
        'field_teaser_text' => 'field_teaser_text',
        'field_teaser_title' => 'field_teaser_title',
        'field_journal_publication_name' => 'field_journal_publication_name',
      ],
    ],
    'resource_year_filter' => [],
    'field_academic_years_target_id' => [],
    'field_discipline_target_id' => [],
    'field_areas_of_study_target_id' => [],
    'field_geographic_areas_target_id' => [],
  ];

  /**
   * Resources offer the old widget's four designs under the same labels.
   *
   * @covers ::viewModeList
   */
  public function testResourceDesigns() {
    $labels = array_map(
      fn($view_mode) => $view_mode['label'],
      ViewsBasicManager::ALLOWED_ENTITIES['resource']['view_modes']
    );
    $this->assertSame([
      'card' => 'Card Grid',
      'portrait_grid' => 'Portrait Grid',
      'list_item' => 'List',
      'condensed' => 'Condensed',
    ], $labels);
    $this->assertSame('Resources', ViewsBasicManager::ALLOWED_ENTITIES['resource']['label']);
  }

  /**
   * Resources sort by publish date, under the old widget's labels.
   *
   * @covers ::sortByList
   */
  public function testResourceSortOptions() {
    $this->assertSame([
      'field_publish_date:DESC' => 'Published Date - newer first',
      'field_publish_date:ASC' => 'Published Date - older first',
    ], ViewsBasicManager::ALLOWED_ENTITIES['resource']['sort_by']);
  }

  /**
   * Search fields default to title and the two teaser fields until stored.
   *
   * @covers ::getDefaultParamValue
   */
  public function testSearchFieldsDefault() {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($this->createMock(TermStorageInterface::class));
    $manager = new ViewsBasicManager(
      $entity_type_manager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->createMock(ViewExecutableFactory::class),
      $this->createMock(ExposedTaxonomyFilterOptions::class),
    );
    $this->assertSame(ViewsBasicManager::RESOURCE_DEFAULT_SEARCH_FIELDS, $manager->getDefaultParamValue('search_fields', ''));
    $this->assertSame(ViewsBasicManager::RESOURCE_DEFAULT_SEARCH_FIELDS, $manager->getDefaultParamValue('search_fields', '{"search_fields":"title"}'));
    $this->assertSame(['title' => 'title'], $manager->getDefaultParamValue('search_fields', '{"search_fields":{"title":"title"}}'));
    $this->assertSame([], $manager->getDefaultParamValue('resource_field_options', '{"resource_field_options":"x"}'));
  }

  /**
   * A resource listing is built from its own scaffold view.
   *
   * @covers ::initView
   */
  public function testInitViewLoadsTheResourceScaffold() {
    $view_entity = $this->createMock(ViewEntityInterface::class);
    $view_storage = $this->createMock(ConfigEntityStorageInterface::class);
    $view_storage->expects($this->once())
      ->method('load')
      ->with('views_basic_scaffold_resources')
      ->willReturn($view_entity);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['taxonomy_term', $this->createMock(TermStorageInterface::class)],
      ['view', $view_storage],
    ]);
    $executable = $this->createMock(ViewExecutable::class);
    $factory = $this->createMock(ViewExecutableFactory::class);
    $factory->method('get')->willReturn($executable);

    $manager = new ViewsBasicManager(
      $entity_type_manager,
      $this->createMock(EntityDisplayRepository::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $factory,
      $this->createMock(ExposedTaxonomyFilterOptions::class),
    );
    $this->assertSame($executable, $manager->initView(['resource']));
    $this->assertContains('views_basic_scaffold_resources', ViewsBasicManager::SCAFFOLD_VIEWS);
  }

  /**
   * Each resource-only exposed filter is removed unless the block asks for it.
   *
   * @covers ::applyResourceFilters
   */
  public function testResourceExposedFiltersAreRemovedUnlessEnabled() {
    $filters = ViewsBasicManager::applyResourceFilters(self::FILTERS, ['exposed_filter_options' => []]);
    foreach (ViewsBasicManager::RESOURCE_EXPOSED_FILTERS as $filter) {
      $this->assertArrayNotHasKey($filter, $filters, "$filter is removed");
    }
    $this->assertArrayHasKey('status', $filters, 'Unrelated filters are left alone.');

    $enabled = array_combine(
      array_keys(ViewsBasicManager::RESOURCE_EXPOSED_FILTERS),
      array_keys(ViewsBasicManager::RESOURCE_EXPOSED_FILTERS)
    );
    $filters = ViewsBasicManager::applyResourceFilters(self::FILTERS, ['exposed_filter_options' => $enabled]);
    foreach (ViewsBasicManager::RESOURCE_EXPOSED_FILTERS as $filter) {
      $this->assertArrayHasKey($filter, $filters, "$filter is kept");
    }
  }

  /**
   * The five resource-only filters map to the view's own filter ids.
   *
   * @covers ::applyResourceFilters
   */
  public function testResourceExposedFilterMap() {
    $this->assertSame([
      'show_year_filter' => 'resource_year_filter',
      'show_academic_year_filter' => 'field_academic_years_target_id',
      'show_discipline_filter' => 'field_discipline_target_id',
      'show_areas_of_study_filter' => 'field_areas_of_study_target_id',
      'show_geographic_areas_filter' => 'field_geographic_areas_target_id',
    ], ViewsBasicManager::RESOURCE_EXPOSED_FILTERS);
  }

  /**
   * The search filter runs across the fields the block picked.
   *
   * @covers ::applyResourceFilters
   */
  public function testSearchFieldsNarrowTheCombineFilter() {
    $params = [
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'search_fields' => ['title' => 'title', 'field_teaser_text' => 0],
    ];
    $filters = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params);
    $this->assertSame(['title' => 'title'], $filters['combine']['fields']);

    // Nothing picked falls back to title and the two teaser fields.
    $params['search_fields'] = ['title' => 0];
    $filters = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params);
    $this->assertSame([
      'title' => 'title',
      'field_teaser_text' => 'field_teaser_text',
      'field_teaser_title' => 'field_teaser_title',
    ], $filters['combine']['fields']);

    // A block saved before search fields existed keeps the view's own set.
    unset($params['search_fields']);
    $filters = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params);
    $this->assertSame(self::FILTERS['combine']['fields'], $filters['combine']['fields']);
  }

  /**
   * Authors swaps in the author-aware search and never becomes a field.
   *
   * @covers ::applyResourceFilters
   */
  public function testAuthorsSearchFieldSwapsInAuthorCombine() {
    $params = [
      'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
      'search_fields' => ['title' => 'title', 'authors' => 'authors'],
    ];
    $combine = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params)['combine'];
    $this->assertSame(['title' => 'title'], $combine['fields']);
    $this->assertSame('resource_author_combine', $combine['field']);
    $this->assertSame('ys_views_basic_resource_author_combine', $combine['plugin_id']);

    $params['search_fields'] = ['authors' => 'authors'];
    $combine = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params)['combine'];
    $this->assertSame([], $combine['fields']);
    $this->assertSame('resource_author_combine', $combine['field']);

    // Without Authors the view's own combine filter stays in place.
    $params['search_fields'] = ['title' => 'title'];
    $combine = ViewsBasicManager::applyResourceFilters(self::FILTERS, $params)['combine'];
    $this->assertArrayNotHasKey('field', $combine);
    $this->assertArrayNotHasKey('plugin_id', $combine);
  }

  /**
   * The resource details become 0/1 flags, all off when nothing was stored.
   *
   * @covers ::resourceFieldDisplayOptions
   */
  public function testResourceFieldDisplayOptions() {
    $this->assertSame([
      'show_teaser_text' => 1,
      'show_discipline' => 0,
      'show_journal_name' => 0,
      'show_journal_issue' => 0,
      'show_authors' => 1,
      'show_publish_date' => 0,
    ], ViewsBasicManager::resourceFieldDisplayOptions([
      'resource_field_options' => [
        'show_teaser_text' => 'show_teaser_text',
        'show_authors' => 'show_authors',
        'show_discipline' => 0,
      ],
    ]));

    $this->assertSame(
      array_fill_keys(ViewsBasicManager::RESOURCE_FIELD_OPTIONS, 0),
      ViewsBasicManager::resourceFieldDisplayOptions([])
    );
  }

  /**
   * A stored resource_view blob is moved onto this manager's keys.
   *
   * @covers ::normalizeResourceParams
   */
  public function testNormalizeResourceParamsSplitsFieldOptions() {
    $normalized = ViewsBasicManager::normalizeResourceParams([
      'view_mode' => 'portrait_grid',
      'filters' => ['types' => ['resource'], 'terms_include' => ['92' => '92']],
      'field_options' => [
        'show_thumbnail' => 'show_thumbnail',
        'show_category' => 'show_category',
        'show_tags' => 0,
        'show_teaser_text' => 'show_teaser_text',
        'show_journal_issue' => 'show_journal_issue',
      ],
      'sort_by' => 'field_publish_date:ASC',
      'offset' => 2,
    ]);

    $this->assertSame(
      ['show_thumbnail' => 'show_thumbnail', 'show_categories' => 'show_categories'],
      $normalized['field_options'],
      'The shared options keep their meaning; show_category becomes show_categories.'
    );
    $this->assertSame(
      ['show_teaser_text' => 'show_teaser_text', 'show_journal_issue' => 'show_journal_issue'],
      $normalized['resource_field_options']
    );
    // Everything else is carried over untouched.
    $this->assertSame('portrait_grid', $normalized['view_mode']);
    $this->assertSame(['92' => '92'], $normalized['filters']['terms_include']);
    $this->assertSame('field_publish_date:ASC', $normalized['sort_by']);
    $this->assertSame(2, $normalized['offset']);
  }

  /**
   * The legacy show_publication flag expands to the four fields it covered.
   *
   * Each of the four only takes the legacy value where it was not stored
   * itself, exactly as ViewsContentResourcesManager::setupView() read it.
   *
   * @covers ::normalizeResourceParams
   */
  public function testNormalizeResourceParamsExpandsLegacyPublication() {
    $normalized = ViewsBasicManager::normalizeResourceParams([
      'field_options' => [
        'show_publication' => 'show_publication',
        'show_authors' => 0,
      ],
    ]);
    $this->assertSame([
      'show_journal_name' => 'show_journal_name',
      'show_journal_issue' => 'show_journal_issue',
      'show_publish_date' => 'show_publish_date',
    ], $normalized['resource_field_options']);

    $normalized = ViewsBasicManager::normalizeResourceParams([
      'field_options' => ['show_publication' => 0],
    ]);
    $this->assertSame([], $normalized['resource_field_options']);
  }

  /**
   * A block that never saved field options keeps rendering its teaser image.
   *
   * The image shows when field_options is absent altogether (see
   * setupView()), so the normaliser must not invent an empty set, which would
   * switch it off.
   *
   * @covers ::normalizeResourceParams
   */
  public function testNormalizeResourceParamsLeavesMissingFieldOptionsMissing() {
    $normalized = ViewsBasicManager::normalizeResourceParams(['view_mode' => 'card']);
    $this->assertArrayNotHasKey('field_options', $normalized);
    $this->assertArrayNotHasKey('resource_field_options', $normalized);
    $this->assertSame(['resource'], $normalized['filters']['types']);
  }

  /**
   * The legacy journal-name filter becomes a search across that field.
   *
   * @covers ::normalizeResourceParams
   */
  public function testNormalizeResourceParamsMapsLegacyJournalFilter() {
    $normalized = ViewsBasicManager::normalizeResourceParams([
      'exposed_filter_options' => [
        'show_search_filter' => 'show_search_filter',
        'show_journal_publication_name_filter' => 'show_journal_publication_name_filter',
      ],
    ]);
    $this->assertSame(['show_search_filter' => 'show_search_filter'], $normalized['exposed_filter_options']);
    $this->assertSame([
      'title' => 'title',
      'field_teaser_text' => 'field_teaser_text',
      'field_teaser_title' => 'field_teaser_title',
      'field_journal_publication_name' => 'field_journal_publication_name',
    ], $normalized['search_fields']);

    // Search fields the editor already chose win over the legacy mapping.
    $normalized = ViewsBasicManager::normalizeResourceParams([
      'exposed_filter_options' => ['show_journal_publication_name_filter' => 'show_journal_publication_name_filter'],
      'search_fields' => ['title' => 'title'],
    ]);
    $this->assertSame(['title' => 'title'], $normalized['search_fields']);
  }

  /**
   * Normalising twice changes nothing, so a re-run migration is a no-op.
   *
   * @covers ::normalizeResourceParams
   */
  public function testNormalizeResourceParamsIsIdempotent() {
    $once = ViewsBasicManager::normalizeResourceParams([
      'view_mode' => 'card',
      'filters' => ['types' => ['resource']],
      'field_options' => [
        'show_category' => 'show_category',
        'show_publication' => 'show_publication',
      ],
      'exposed_filter_options' => ['show_journal_publication_name_filter' => 'show_journal_publication_name_filter'],
    ]);
    $this->assertSame($once, ViewsBasicManager::normalizeResourceParams($once));
  }

}
