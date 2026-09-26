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

    foreach (['view', 'post_card', 'post_list_item', 'profile_card', 'profile_directory'] as $bundle) {
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
    $path = $this->container->get('extension.list.module')->getPath('ys_views_basic');
    require_once $path . '/ys_views_basic.deploy.php';
    require_once $path . '/ys_views_basic.post_update.php';
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

    ys_views_basic_deploy_10001();
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

    ys_views_basic_deploy_10001();
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

    ys_views_basic_deploy_10001();
    $this->blockStorage->resetCache();
    $first = $this->blockStorage->load($post->id());
    $this->assertSame('post_list_item', $first->bundle());

    // Second run: no "view" blocks remain, so nothing is migrated.
    ys_views_basic_deploy_10001();
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

    ys_views_basic_deploy_10002();
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
   * A legacy directory block lands on small profile cards (#1682).
   */
  public function testPredecessorDirectoryLandsOnProfileCard() {
    $block = BlockContent::create(['type' => 'directory', 'info' => 'Directory']);
    $block->save();

    ys_views_basic_deploy_10002();
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

    ys_views_basic_post_update_retire_profile_directory();
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

    ys_views_basic_post_update_retire_profile_directory();
    $this->blockStorage->resetCache();
    $first = $this->storedParams($block->id());
    $revision = $this->blockStorage->load($block->id())->getRevisionId();

    ys_views_basic_post_update_retire_profile_directory();
    $this->blockStorage->resetCache();

    $this->assertSame($first, $this->storedParams($block->id()));
    $this->assertSame($revision, $this->blockStorage->load($block->id())->getRevisionId());
  }

  /**
   * With no profile_directory blocks the post-update runs clean (#1682).
   */
  public function testRetireProfileDirectoryWithNoInstances() {
    $message = (string) ys_views_basic_post_update_retire_profile_directory();
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

    ys_views_basic_post_update_retire_profile_directory();

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

}
