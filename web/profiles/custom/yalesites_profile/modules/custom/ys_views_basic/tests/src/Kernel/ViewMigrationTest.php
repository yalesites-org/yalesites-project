<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\NodeType;
use Drupal\ys_views_basic\Service\ListingMigrator;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the listing block migrations (#1169, #1170, #1682).
 *
 * Covers the in-place bundle swap, the field-table bundle-column patch, the
 * skip of unmappable blocks, idempotency, and the retirement of the
 * profile_directory bundle onto small profile cards. The Layout Builder
 * plugin-id rewrite is exercised against rows written straight into the
 * layout tables, which is all the rewrite reads.
 *
 * @group yalesites
 */
class ViewMigrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block_content',
    'path_alias',
    'views',
    'node',
    'layout_discovery',
    'layout_builder',
    'ys_views_basic',
    // Provides the views_content_resources_params field type that the legacy
    // resource_view bundle stores its params in (#1723).
    'ys_views_content_resources',
  ];

  /**
   * The params a retired profile_directory block was saved with.
   */
  const DIRECTORY_PARAMS = [
    'view_mode' => 'directory',
    'filters' => ['types' => ['profile'], 'terms_include' => [7], 'terms_exclude' => NULL],
    'sort_by' => 'field_last_name:DESC',
    'display' => 'limit',
    'limit' => 8,
    'field_options' => [],
    'exposed_filter_options' => ['show_search_filter' => 'show_search_filter'],
    'pinned_to_top' => TRUE,
  ];

  /**
   * The profile fields the retired directory card always showed.
   */
  const DIRECTORY_PROFILE_FIELDS = [
    'show_department' => 'show_department',
    'show_email' => 'show_email',
    'show_phone' => 'show_phone',
  ];

  /**
   * The block content storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $blockStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('node');

    // The shared field_view_params storage and instances on the legacy "view"
    // and "profile_directory" bundles plus the target bundles exercised here.
    FieldStorageConfig::create([
      'field_name' => 'field_view_params',
      'entity_type' => 'block_content',
      'type' => 'views_basic_params',
    ])->save();

    foreach (['view', 'post_card', 'post_list_item', 'event_list_item', 'profile_card', 'profile_directory'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_view_params',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'View params',
      ])->save();
    }

    // The predecessor "post_list" bundle has no field_view_params (its query
    // lives in an embedded View); the migration pre-fills the field after the
    // swap to post_list_item.
    BlockContentType::create(['id' => 'post_list', 'label' => 'Post Feed'])->save();
    BlockContentType::create(['id' => 'directory', 'label' => 'Directory'])->save();
    BlockContentType::create(['id' => 'event_list', 'label' => 'Calendar List'])->save();

    // A node layout field, so the placement rewrite has tables to walk.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'node',
      'type' => 'layout_section',
    ])->save();
    FieldConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Layout',
    ])->save();

    $this->blockStorage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $module_handler = $this->container->get('module_handler');
    $module_handler->loadInclude('ys_views_basic', 'php', 'ys_views_basic.deploy');
    $module_handler->loadInclude('ys_views_basic', 'php', 'ys_views_basic.post_update');
  }

  /**
   * Runs a batched deploy or post-update hook to completion.
   *
   * @param string $hook
   *   The hook function name.
   * @param int|null $passes
   *   (optional) Set to the number of passes the hook took.
   *
   * @return string
   *   The message the final pass returned.
   */
  private function runHook(string $hook, ?int &$passes = NULL): string {
    $sandbox = [];
    $passes = 0;
    do {
      $result = $hook($sandbox);
      $passes++;
      $this->assertLessThan(1000, $passes, "$hook finishes");
    } while (isset($sandbox['#finished']) && $sandbox['#finished'] < 1);
    return (string) $result;
  }

  /**
   * Creates a profile_directory block with the given stored params.
   */
  private function createDirectoryBlock(array $params): BlockContent {
    $block = BlockContent::create([
      'type' => 'profile_directory',
      'info' => 'People directory',
      'field_view_params' => ['params' => json_encode($params)],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Returns a block's decoded field_view_params.
   */
  private function storedParams(int|string $id): array {
    $block = $this->blockStorage->load($id);
    return json_decode($block->get('field_view_params')->first()->getValue()['params'], TRUE);
  }

  /**
   * Asserts the card params a directory block converts to (#1682).
   */
  private function assertDirectoryConvertedParams(array $params): void {
    $this->assertSame('card', $params['view_mode']);
    $this->assertSame('small', $params['card_size']);
    $this->assertSame(['show_thumbnail' => 'show_thumbnail'], $params['field_options']);
    $this->assertSame(self::DIRECTORY_PROFILE_FIELDS, $params['profile_field_options']);
  }

  /**
   * Creates a "view" block with the given stored params.
   */
  private function createViewBlock(string $info, array $params): BlockContent {
    $block = BlockContent::create([
      'type' => 'view',
      'info' => $info,
      'field_view_params' => ['params' => json_encode($params)],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Each "view" block is swapped in place to its {type}_{view_mode} bundle.
   */
  public function testBundleSwap() {
    $post = $this->createViewBlock('post card', [
      'view_mode' => 'card',
      'filters' => ['types' => ['post']],
    ]);
    $directory = $this->createViewBlock('profile directory', [
      'view_mode' => 'directory',
      'filters' => ['types' => ['profile']],
    ]);

    $this->runHook('ys_views_basic_deploy_10001');
    $this->blockStorage->resetCache();

    $this->assertSame('post_card', $this->blockStorage->load($post->id())->bundle());
    // The retired directory design option lands on small profile cards.
    $this->assertSame('profile_card', $this->blockStorage->load($directory->id())->bundle());
    $params = $this->storedParams($directory->id());
    $this->assertDirectoryConvertedParams($params);
    $this->assertSame(['profile'], $params['filters']['types']);

    // The field-table bundle column is patched on the data table.
    $bundle = $this->container->get('database')
      ->select('block_content__field_view_params', 't')
      ->fields('t', ['bundle'])
      ->condition('entity_id', $post->id())
      ->execute()
      ->fetchField();
    $this->assertSame('post_card', $bundle, 'The field-table bundle column is patched.');
  }

  /**
   * Unmappable blocks (e.g. a stray calendar view_mode) are left as "view".
   */
  public function testUnmappableBlockIsSkipped() {
    $calendar = $this->createViewBlock('stray calendar', [
      'view_mode' => 'calendar',
      'filters' => ['types' => ['event']],
    ]);
    $malformed = $this->createViewBlock('malformed', ['filters' => ['types' => ['post']]]);

    $this->runHook('ys_views_basic_deploy_10001');
    $this->blockStorage->resetCache();

    $this->assertSame('view', $this->blockStorage->load($calendar->id())->bundle());
    $this->assertSame('view', $this->blockStorage->load($malformed->id())->bundle());
  }

  /**
   * The hook is idempotent: a second run changes nothing and does not error.
   */
  public function testIdempotency() {
    $post = $this->createViewBlock('post list', [
      'view_mode' => 'list_item',
      'filters' => ['types' => ['post']],
    ]);

    $this->runHook('ys_views_basic_deploy_10001');
    $this->blockStorage->resetCache();
    $first = $this->blockStorage->load($post->id());
    $this->assertSame('post_list_item', $first->bundle());

    // Second run: no "view" blocks remain, so nothing is migrated.
    $this->runHook('ys_views_basic_deploy_10001');
    $this->blockStorage->resetCache();
    $second = $this->blockStorage->load($post->id());
    $this->assertSame('post_list_item', $second->bundle());

    $remaining = $this->blockStorage->getQuery()
      ->condition('type', 'view')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, (int) $remaining);
  }

  /**
   * The predecessor migration swaps bundle and pre-fills params (#1170).
   */
  public function testPredecessorMigration() {
    // A predecessor "post_list" block carries no field_view_params.
    $block = BlockContent::create(['type' => 'post_list', 'info' => 'Post feed']);
    $block->save();

    $this->runHook('ys_views_basic_deploy_10002');
    $this->blockStorage->resetCache();

    $migrated = $this->blockStorage->load($block->id());
    $this->assertSame('post_list_item', $migrated->bundle(), 'post_list is superseded by post_list_item.');

    $params = json_decode($migrated->get('field_view_params')->first()->getValue()['params'], TRUE);
    $this->assertSame('list_item', $params['view_mode']);
    $this->assertSame(['post'], $params['filters']['types']);
    $this->assertSame('field_publish_date:DESC', $params['sort_by']);
    $this->assertTrue($params['pinned_to_top'], 'Post feed pins sticky items.');
  }

  /**
   * Each predecessor block keeps the filter visitors had on it (#1967).
   */
  public function testPredecessorMigrationTurnsOnTheLiveFilter() {
    $expected = [
      'post_list' => ['post_list_item', ['show_category_filter' => 'show_category_filter'], 'Post Category'],
      'event_list' => [
        'event_list_item',
        ['show_category_filter' => 'show_category_filter', 'show_event_type_filter' => 'show_event_type_filter'],
        'Event Category',
      ],
      'directory' => ['profile_card', ['show_category_filter' => 'show_category_filter'], 'Affiliation'],
    ];
    $ids = [];
    foreach (array_keys($expected) as $legacy) {
      $block = BlockContent::create(['type' => $legacy, 'info' => $legacy]);
      $block->save();
      $ids[$legacy] = $block->id();
    }

    $this->runHook('ys_views_basic_deploy_10002');
    $this->blockStorage->resetCache();

    foreach ($expected as $legacy => [$target, $filters, $label]) {
      $this->assertSame($target, $this->blockStorage->load($ids[$legacy])->bundle());
      $params = $this->storedParams($ids[$legacy]);
      $this->assertSame($filters, $params['exposed_filter_options'], "$legacy keeps its exposed filter.");
      $this->assertSame($label, $params['category_filter_label'], "$legacy keeps its filter label.");
      $this->assertTrue($params['used_terms_only'], "$legacy lists only terms in use, as live did.");
    }
  }

  /**
   * Blocks 10002 already migrated without the filter get it (#1967).
   *
   * Only blocks whose params still equal the old filterless preset change;
   * a block an editor has since changed is left alone. A second run is a
   * no-op.
   */
  public function testAlreadyMigratedPredecessorsGetTheirFilter() {
    $old = ViewsBasicManager::filterlessPredecessorParams('post_list');
    $this->assertSame([], $old['exposed_filter_options']);
    $this->assertArrayNotHasKey('used_terms_only', $old);

    $untouched = BlockContent::create([
      'type' => 'post_list_item',
      'info' => 'migrated by the old 10002',
      'field_view_params' => ['params' => json_encode($old)],
    ]);
    $untouched->save();
    $edited = BlockContent::create([
      'type' => 'post_list_item',
      'info' => 'changed by an editor since',
      'field_view_params' => ['params' => json_encode(['limit' => 5] + $old)],
    ]);
    $edited->save();

    $this->runHook('ys_views_basic_deploy_10004');
    $this->blockStorage->resetCache();

    $this->assertEquals(ViewsBasicManager::predecessorPreset('post_list')['params'], $this->storedParams($untouched->id()));
    $this->assertSame(['limit' => 5] + $old, $this->storedParams($edited->id()));

    $this->runHook('ys_views_basic_deploy_10004');
    $this->blockStorage->resetCache();
    $this->assertEquals(ViewsBasicManager::predecessorPreset('post_list')['params'], $this->storedParams($untouched->id()));
  }

  /**
   * Creates the legacy resource_view bundle and the resource listing bundles.
   */
  private function createResourceBundles(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_view_resource_params',
      'entity_type' => 'block_content',
      'type' => 'views_content_resources_params',
    ])->save();
    BlockContentType::create(['id' => 'resource_view', 'label' => 'Resource View'])->save();
    FieldConfig::create([
      'field_name' => 'field_view_resource_params',
      'entity_type' => 'block_content',
      'bundle' => 'resource_view',
      'label' => 'View Resource Params',
    ])->save();
    foreach (['resource_card', 'resource_list_item'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_view_params',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'View params',
      ])->save();
    }
  }

  /**
   * Reads a block revision's field_view_params back as decoded params.
   */
  private function viewParams(BlockContent $block): ?array {
    if ($block->get('field_view_params')->isEmpty()) {
      return NULL;
    }
    return json_decode($block->get('field_view_params')->first()->getValue()['params'], TRUE);
  }

  /**
   * Resource listings move onto the resource bundles, every revision (#1723).
   *
   * The params live in a different field on the old bundle, so unlike the
   * "view" migration this one has to copy them. It copies them on every
   * revision, not just the current one, because Layout Builder renders an
   * inline block by revision id: a published page with a newer draft points
   * at an older block revision than the latest one.
   */
  public function testResourceViewMigration() {
    $this->createResourceBundles();

    $first_params = [
      'view_mode' => 'card',
      'filters' => ['types' => ['resource']],
      'field_options' => [
        'show_category' => 'show_category',
        'show_publication' => 'show_publication',
      ],
      'sort_by' => 'field_publish_date:DESC',
    ];
    $block = BlockContent::create([
      'type' => 'resource_view',
      'info' => 'Resources',
      'field_view_resource_params' => ['params' => json_encode($first_params)],
    ]);
    $block->save();
    $first_revision = $block->getRevisionId();

    $block->setNewRevision(TRUE);
    $second_params = [
      'view_mode' => 'list_item',
      'filters' => ['types' => ['resource']],
      'field_options' => ['show_tags' => 'show_tags', 'show_authors' => 'show_authors'],
      'sort_by' => 'field_publish_date:ASC',
      'offset' => 2,
    ];
    $block->set('field_view_resource_params', ['params' => json_encode($second_params)]);
    $block->save();

    $unmappable = BlockContent::create([
      'type' => 'resource_view',
      'info' => 'No params',
      'field_view_resource_params' => ['params' => json_encode(['filters' => ['types' => ['resource']]])],
    ]);
    $unmappable->save();

    $this->runHook('ys_views_basic_deploy_10003');
    $this->blockStorage->resetCache();

    // The target comes from the current revision's design option.
    $migrated = $this->blockStorage->load($block->id());
    $this->assertSame('resource_list_item', $migrated->bundle());
    $params = $this->viewParams($migrated);
    $this->assertSame('list_item', $params['view_mode']);
    $this->assertSame(['show_tags' => 'show_tags'], $params['field_options']);
    $this->assertSame(['show_authors' => 'show_authors'], $params['resource_field_options']);
    $this->assertSame('field_publish_date:ASC', $params['sort_by']);
    $this->assertSame(2, $params['offset']);

    // The older revision carries its own params, normalised the same way.
    $old = $this->viewParams($this->blockStorage->loadRevision($first_revision));
    $this->assertSame('card', $old['view_mode']);
    $this->assertSame(['show_categories' => 'show_categories'], $old['field_options']);
    $this->assertSame([
      'show_journal_name' => 'show_journal_name',
      'show_journal_issue' => 'show_journal_issue',
      'show_authors' => 'show_authors',
      'show_publish_date' => 'show_publish_date',
    ], $old['resource_field_options']);

    // The field-table bundle column is patched.
    $bundle = $this->container->get('database')
      ->select('block_content__field_view_params', 't')
      ->fields('t', ['bundle'])
      ->condition('entity_id', $block->id())
      ->execute()
      ->fetchField();
    $this->assertSame('resource_list_item', $bundle);

    // A block with no design option to map is left for manual follow-up.
    $this->assertSame('resource_view', $this->blockStorage->load($unmappable->id())->bundle());

    // A second run changes nothing.
    $this->runHook('ys_views_basic_deploy_10003');
    $this->blockStorage->resetCache();
    $again = $this->blockStorage->load($block->id());
    $this->assertSame('resource_list_item', $again->bundle());
    $this->assertSame($params, $this->viewParams($again));
    $this->assertSame($old, $this->viewParams($this->blockStorage->loadRevision($first_revision)));
    $this->assertSame('resource_view', $this->blockStorage->load($unmappable->id())->bundle());
  }

  /**
   * A legacy directory block lands on small profile cards (#1682).
   */
  public function testPredecessorDirectoryLandsOnProfileCard() {
    $block = BlockContent::create(['type' => 'directory', 'info' => 'Directory']);
    $block->save();

    $this->runHook('ys_views_basic_deploy_10002');
    $this->blockStorage->resetCache();

    $this->assertSame('profile_card', $this->blockStorage->load($block->id())->bundle());
    $params = $this->storedParams($block->id());
    $this->assertDirectoryConvertedParams($params);
    $this->assertSame('field_last_name:ASC', $params['sort_by']);
  }

  /**
   * The post-update converts profile_directory blocks in place (#1682).
   */
  public function testRetireProfileDirectory() {
    $block = $this->createDirectoryBlock(self::DIRECTORY_PARAMS);
    $other = $this->createViewBlock('post card', [
      'view_mode' => 'card',
      'filters' => ['types' => ['post']],
    ]);

    $this->runHook('ys_views_basic_post_update_retire_profile_directory');
    $this->blockStorage->resetCache();

    $this->assertSame('profile_card', $this->blockStorage->load($block->id())->bundle());
    $params = $this->storedParams($block->id());
    $this->assertDirectoryConvertedParams($params);
    // Every other stored setting is kept.
    foreach (['filters', 'sort_by', 'display', 'limit', 'exposed_filter_options', 'pinned_to_top'] as $key) {
      $this->assertSame(self::DIRECTORY_PARAMS[$key], $params[$key], "$key is preserved");
    }

    // Field-table rows no longer carry the retired bundle.
    $database = $this->container->get('database');
    foreach (['block_content__field_view_params', 'block_content_revision__field_view_params'] as $table) {
      $stale = $database->select($table, 't')
        ->condition('bundle', 'profile_directory')
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertSame(0, (int) $stale, "$table has no profile_directory rows");
    }

    // Unrelated blocks are untouched.
    $this->assertSame('view', $this->blockStorage->load($other->id())->bundle());
  }

  /**
   * A second run changes nothing (#1682).
   */
  public function testRetireProfileDirectoryIsIdempotent() {
    $block = $this->createDirectoryBlock(self::DIRECTORY_PARAMS);

    $this->runHook('ys_views_basic_post_update_retire_profile_directory');
    $this->blockStorage->resetCache();
    $first = $this->storedParams($block->id());
    $revision = $this->blockStorage->load($block->id())->getRevisionId();

    $this->runHook('ys_views_basic_post_update_retire_profile_directory');
    $this->blockStorage->resetCache();

    $this->assertSame($first, $this->storedParams($block->id()));
    $this->assertSame($revision, $this->blockStorage->load($block->id())->getRevisionId());
  }

  /**
   * With no profile_directory blocks the post-update runs clean (#1682).
   */
  public function testRetireProfileDirectoryWithNoInstances() {
    $message = $this->runHook('ys_views_basic_post_update_retire_profile_directory');
    $this->assertStringContainsString('converted 0', $message);
  }

  /**
   * Layout placements of a retired block are rewritten (#1682).
   */
  public function testRetireProfileDirectoryRewritesPlacements() {
    $block = $this->createDirectoryBlock(self::DIRECTORY_PARAMS);
    $section = new Section('layout_onecol', [], [
      new SectionComponent('uuid-directory', 'content', [
        'id' => 'inline_block:profile_directory',
        'label' => 'People directory',
        'block_revision_id' => $block->getRevisionId(),
      ]),
    ]);
    $database = $this->container->get('database');
    foreach (['node__layout_builder__layout', 'node_revision__layout_builder__layout'] as $table) {
      $database->insert($table)->fields([
        'bundle' => 'page',
        'deleted' => 0,
        'entity_id' => 1,
        'revision_id' => 1,
        'langcode' => 'en',
        'delta' => 0,
        'layout_builder__layout_section' => serialize($section),
      ])->execute();
    }

    $this->runHook('ys_views_basic_post_update_retire_profile_directory');

    foreach (['node__layout_builder__layout', 'node_revision__layout_builder__layout'] as $table) {
      $stored = unserialize($database->select($table, 't')
        ->fields('t', ['layout_builder__layout_section'])
        ->execute()
        ->fetchField(), ['allowed_classes' => [Section::class, SectionComponent::class]]);
      $this->assertSame(
        'inline_block:profile_card',
        $stored->getComponent('uuid-directory')->get('configuration')['id'],
        "$table placement is rewritten"
      );
    }
  }

  /**
   * Writes a layout row placing one inline block, as Layout Builder stores it.
   *
   * @param int $entity_id
   *   The node id.
   * @param int $revision_id
   *   The node revision id.
   * @param string $plugin_id
   *   The inline block plugin id.
   * @param int|string $block_revision_id
   *   The block content revision id the placement references.
   * @param bool $data_table
   *   (optional) Also write the row to the data table. Defaults to TRUE.
   */
  private function placeInlineBlock(int $entity_id, int $revision_id, string $plugin_id, int|string $block_revision_id, bool $data_table = TRUE): void {
    $section = new Section('layout_onecol', [], [
      new SectionComponent('uuid-' . $revision_id, 'content', [
        'id' => $plugin_id,
        'label' => 'Listing',
        'block_revision_id' => $block_revision_id,
      ]),
    ]);
    $tables = ['node_revision__layout_builder__layout'];
    if ($data_table) {
      $tables[] = 'node__layout_builder__layout';
    }
    foreach ($tables as $table) {
      $this->container->get('database')->insert($table)->fields([
        'bundle' => 'page',
        'deleted' => 0,
        'entity_id' => $entity_id,
        'revision_id' => $revision_id,
        'langcode' => 'en',
        'delta' => 0,
        'layout_builder__layout_section' => serialize($section),
      ])->execute();
    }
  }

  /**
   * Returns the plugin id of the single placement in a layout row.
   */
  private function placedPluginId(string $table, int $revision_id): string {
    $stored = unserialize($this->container->get('database')->select($table, 't')
      ->fields('t', ['layout_builder__layout_section'])
      ->condition('revision_id', $revision_id)
      ->execute()
      ->fetchField(), ['allowed_classes' => [Section::class, SectionComponent::class]]);
    return $stored->getComponent('uuid-' . $revision_id)->get('configuration')['id'];
  }

  /**
   * Deploy 10001 patches the bundle on every field table, old revisions too.
   *
   * The save only rewrites the current revision's field rows, so without the
   * patch an older revision's heading row would keep bundle "view" and be
   * purged with the retired bundle's field instances.
   */
  public function testViewMigrationPatchesEveryFieldTable() {
    FieldStorageConfig::create([
      'field_name' => 'field_heading',
      'entity_type' => 'block_content',
      'type' => 'string',
    ])->save();
    foreach (['view', 'post_card'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_heading',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'Heading',
      ])->save();
    }
    $block = BlockContent::create([
      'type' => 'view',
      'info' => 'post card',
      'field_heading' => 'First heading',
      'field_view_params' => ['params' => json_encode(['view_mode' => 'card', 'filters' => ['types' => ['post']]])],
    ]);
    $block->save();
    $block->setNewRevision(TRUE);
    $block->set('field_heading', 'Second heading');
    $block->save();

    $this->runHook('ys_views_basic_deploy_10001');

    $database = $this->container->get('database');
    $tables = [
      'block_content__field_view_params',
      'block_content_revision__field_view_params',
      'block_content__field_heading',
      'block_content_revision__field_heading',
    ];
    foreach ($tables as $table) {
      $bundles = $database->select($table, 't')
        ->fields('t', ['bundle'])
        ->condition('entity_id', $block->id())
        ->execute()
        ->fetchCol();
      $this->assertNotEmpty($bundles, "$table has rows");
      $this->assertSame(['post_card'], array_values(array_unique($bundles)), "$table rows are all post_card");
    }
    $this->assertCount(2, $database->select('block_content_revision__field_heading', 't')
      ->fields('t', ['revision_id'])
      ->condition('entity_id', $block->id())
      ->execute()
      ->fetchCol());
  }

  /**
   * A run with nothing to migrate leaves every pending layout draft alone.
   */
  public function testZeroWorkRunsLeaveTempstoreAlone() {
    $overrides = $this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides');
    $overrides->set('node.1.full.en', 'pending override edit');

    foreach ([
      'ys_views_basic_deploy_10001',
      'ys_views_basic_deploy_10002',
      'ys_views_basic_deploy_10003',
      'ys_views_basic_deploy_10004',
      'ys_views_basic_post_update_retire_profile_directory',
    ] as $hook) {
      $this->runHook($hook);
      $this->assertSame('pending override edit', $overrides->get('node.1.full.en'), "$hook leaves the draft alone");
    }
  }

  /**
   * A rewrite discards only the drafts of the entities it rewrote.
   */
  public function testRewriteClearsOnlyAffectedDrafts() {
    $block = $this->createViewBlock('post card', [
      'view_mode' => 'card',
      'filters' => ['types' => ['post']],
    ]);
    $this->placeInlineBlock(1, 1, 'inline_block:view', $block->getRevisionId());

    $overrides = $this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides');
    $overrides->set('node.1.full.en', 'stale draft of the rewritten page');
    $overrides->set('node.2.full.en', 'draft of an untouched page');

    $this->runHook('ys_views_basic_deploy_10001');

    $this->assertSame('inline_block:post_card', $this->placedPluginId('node__layout_builder__layout', 1));
    $this->assertNull($overrides->get('node.1.full.en'));
    $this->assertSame('draft of an untouched page', $overrides->get('node.2.full.en'));
  }

  /**
   * Blocks and placements are migrated in chunks across several passes.
   */
  public function testMigrationIsBatched() {
    $count = ListingMigrator::BATCH_SIZE + 1;
    $blocks = [];
    for ($i = 1; $i <= $count; $i++) {
      $blocks[$i] = $this->createViewBlock("post card $i", [
        'view_mode' => 'card',
        'filters' => ['types' => ['post']],
      ]);
      // One page revision per block, so the placement walk spans two chunks.
      $this->placeInlineBlock(1, $i, 'inline_block:view', $blocks[$i]->getRevisionId(), FALSE);
    }

    $message = $this->runHook('ys_views_basic_deploy_10001', $passes);

    // Two block chunks, two placement chunks, then the final pass.
    $this->assertGreaterThanOrEqual(4, $passes);
    $this->assertStringContainsString("Migrated $count view blocks; rewrote $count layout placements; 0 view blocks", $message);
    $this->blockStorage->resetCache();
    foreach ($blocks as $i => $block) {
      $this->assertSame('post_card', $this->blockStorage->load($block->id())->bundle());
      $this->assertSame('inline_block:post_card', $this->placedPluginId('node_revision__layout_builder__layout', $i));
    }
  }

}
