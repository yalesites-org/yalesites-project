<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Existing Wrapped Image blocks are backfilled to Large (#1954).
 *
 * The Image Size dial is required, so a block saved before it existed could
 * not be re-saved until an editor picked a size. ys_themes_deploy_10304 sets
 * those to 'large', which renders the same as an empty value.
 *
 * @group ys_themes
 * @group yalesites
 */
class WrappedImageSizeBackfillTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'block_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');

    // Plain allowed values stand in for ys_themes_allowed_values_function so
    // the test does not need the module's full dependency tree.
    FieldStorageConfig::create([
      'field_name' => 'field_style_width',
      'entity_type' => 'block_content',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => ['large' => 'Large', 'small' => 'Small'],
      ],
    ])->save();

    foreach (['wrapped_image', 'content_spotlight'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_style_width',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'Image Size',
      ])->save();
    }

    // ys_themes_deploy_10303 shares the backfill helper; one of its dials
    // guards that extracting the helper left it working.
    FieldStorageConfig::create([
      'field_name' => 'field_style_thickness',
      'entity_type' => 'block_content',
      'type' => 'list_string',
      'settings' => ['allowed_values' => ['1' => '1px', '2' => '2px']],
    ])->save();
    BlockContentType::create(['id' => 'divider', 'label' => 'divider'])->save();
    FieldConfig::create([
      'field_name' => 'field_style_thickness',
      'entity_type' => 'block_content',
      'bundle' => 'divider',
      'label' => 'Divider Thickness',
    ])->save();

    // Listing blocks carry the required Padding Options dial.
    FieldStorageConfig::create([
      'field_name' => 'field_padding_options',
      'entity_type' => 'block_content',
      'type' => 'list_string',
      'settings' => ['allowed_values' => ['default' => 'Default', 'no_top' => 'No top']],
    ])->save();
    foreach (['view', 'resource_view'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_padding_options',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'Padding Options',
      ])->save();
    }

    require_once dirname(__DIR__, 3) . '/ys_themes.deploy.php';
  }

  /**
   * Empty sizes become Large; chosen sizes and other bundles are untouched.
   */
  public function testBackfillsOnlyEmptyWrappedImages(): void {
    $empty = $this->createBlock('wrapped_image');
    $small = $this->createBlock('wrapped_image', 'small');
    $other = $this->createBlock('content_spotlight');

    $sandbox = [];
    do {
      ys_themes_deploy_10304($sandbox);
    } while ($sandbox['#finished'] < 1);

    $this->assertSame('large', $this->reload($empty)->get('field_style_width')->value);
    $this->assertSame('small', $this->reload($small)->get('field_style_width')->value);
    $this->assertTrue($this->reload($other)->get('field_style_width')->isEmpty());
    $this->assertSame(1, $sandbox['updated']);
  }

  /**
   * The wave 1 backfill still fills its dials through the shared helper.
   */
  public function testWaveOneBackfillStillWorks(): void {
    $divider = BlockContent::create(['type' => 'divider', 'info' => 'divider']);
    $divider->save();

    $sandbox = [];
    do {
      ys_themes_deploy_10303($sandbox);
    } while ($sandbox['#finished'] < 1);

    $this->assertSame('1', $this->reload((int) $divider->id())->get('field_style_thickness')->value);
  }

  /**
   * Older revisions, as pinned after a page Revert, are backfilled too (#2117).
   */
  public function testBackfillsOlderRevisions(): void {
    $block = BlockContent::create(['type' => 'wrapped_image', 'info' => 'old']);
    $block->save();
    $old_revision_id = (int) $block->getRevisionId();

    $newer = \Drupal::entityTypeManager()->getStorage('block_content')
      ->createRevision($block);
    $newer->set('field_style_width', 'small');
    $newer->save();

    // Age both revisions so a re-stamp to "now" is visible.
    $database = \Drupal::database();
    $database->update('block_content_field_revision')
      ->fields(['changed' => 1000000000])
      ->execute();
    $database->update('block_content_field_data')
      ->fields(['changed' => 1000000000])
      ->execute();

    $sandbox = [];
    do {
      ys_themes_deploy_10306($sandbox);
    } while ($sandbox['#finished'] < 1);

    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $storage->resetCache();
    // "Last updated" is kept on the backfilled revision and the default one.
    $this->assertSame(1000000000, (int) $storage->loadRevision($old_revision_id)->getChangedTime());
    $this->assertSame(1000000000, (int) $storage->load($block->id())->getChangedTime());
    $this->assertSame('large', $storage->loadRevision($old_revision_id)->get('field_style_width')->value);
    $this->assertSame('small', $storage->loadRevision($newer->getRevisionId())->get('field_style_width')->value);
  }

  /**
   * Older listing revisions get Padding Options; chosen values stay (#2117).
   */
  public function testBackfillsListingPaddingOnOlderRevisions(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $old_ids = [];
    $newer_ids = [];
    foreach (['view', 'resource_view'] as $bundle) {
      $block = BlockContent::create(['type' => $bundle, 'info' => $bundle]);
      $block->save();
      $old_ids[$bundle] = (int) $block->getRevisionId();
      $newer = $storage->createRevision($block);
      $newer->set('field_padding_options', 'no_top');
      $newer->save();
      $newer_ids[$bundle] = (int) $newer->getRevisionId();
    }

    $sandbox = [];
    do {
      ys_themes_deploy_10306($sandbox);
    } while ($sandbox['#finished'] < 1);

    $storage->resetCache();
    foreach (['view', 'resource_view'] as $bundle) {
      $this->assertSame('default', $storage->loadRevision($old_ids[$bundle])->get('field_padding_options')->value);
      $this->assertSame('no_top', $storage->loadRevision($newer_ids[$bundle])->get('field_padding_options')->value);
    }
  }

  /**
   * The hook finishes in one pass when there are no Wrapped Image blocks.
   */
  public function testNoBlocks(): void {
    $sandbox = [];
    ys_themes_deploy_10304($sandbox);
    $this->assertEquals(1, $sandbox['#finished']);
  }

  /**
   * Creates a block of the given bundle, optionally with a size.
   */
  protected function createBlock(string $bundle, ?string $size = NULL): int {
    $block = BlockContent::create([
      'type' => $bundle,
      'info' => $bundle,
      'field_style_width' => $size,
    ]);
    $block->save();
    return (int) $block->id();
  }

  /**
   * Loads a block fresh from storage.
   */
  protected function reload(int $id): BlockContent {
    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

}
