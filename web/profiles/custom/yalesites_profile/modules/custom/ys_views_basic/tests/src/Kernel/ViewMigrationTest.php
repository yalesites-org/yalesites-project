<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the legacy "view" block migration deploy hook (#1169).
 *
 * Covers the in-place bundle swap, the field-table bundle-column patch, the
 * skip of unmappable blocks, and idempotency. The Layout Builder plugin-id
 * rewrite needs nodes + layout_builder and is validated on staging (ADR DR-9).
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
    'ys_views_basic',
    // Provides the views_content_resources_params field type that the legacy
    // resource_view bundle stores its params in (#1723).
    'ys_views_content_resources',
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

    // The shared field_view_params storage and instances on the legacy "view"
    // bundle plus the two target bundles exercised by this test.
    FieldStorageConfig::create([
      'field_name' => 'field_view_params',
      'entity_type' => 'block_content',
      'type' => 'views_basic_params',
    ])->save();

    foreach (['view', 'post_card', 'post_list_item', 'profile_directory'] as $bundle) {
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

    $this->blockStorage = $this->container->get('entity_type.manager')->getStorage('block_content');
    require_once $this->container->get('extension.list.module')->getPath('ys_views_basic') . '/ys_views_basic.deploy.php';
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
    $this->assertSame('profile_directory', $this->blockStorage->load($directory->id())->bundle());

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

    ys_views_basic_deploy_10003();
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
    ys_views_basic_deploy_10003();
    $this->blockStorage->resetCache();
    $again = $this->blockStorage->load($block->id());
    $this->assertSame('resource_list_item', $again->bundle());
    $this->assertSame($params, $this->viewParams($again));
    $this->assertSame($old, $this->viewParams($this->blockStorage->loadRevision($first_revision)));
    $this->assertSame('resource_view', $this->blockStorage->load($unmappable->id())->bundle());
  }

}
