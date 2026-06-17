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

    foreach (['view', 'post_card', 'profile_directory'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_view_params',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'View params',
      ])->save();
    }

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

}
