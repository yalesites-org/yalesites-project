<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Existing Custom Card Collection blocks are backfilled to Small (#1842).
 *
 * The Card Size dial is required, so a block saved before it existed could
 * not be re-saved until an editor picked a size. ys_themes_deploy_10308 sets
 * those to 'small', which renders the same as an empty value.
 *
 * @group ys_themes
 * @group yalesites
 */
class CustomCardsSizeBackfillTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'block',
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
        'allowed_values' => ['small' => 'Small', 'large' => 'Large'],
      ],
    ])->save();

    foreach (['custom_cards', 'wrapped_image'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldConfig::create([
        'field_name' => 'field_style_width',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'label' => 'Card Size',
      ])->save();
    }

    require_once dirname(__DIR__, 3) . '/ys_themes.deploy.php';
  }

  /**
   * Empty sizes become Small; chosen sizes and other bundles are untouched.
   */
  public function testBackfillsOnlyEmptyCustomCards(): void {
    $empty = $this->createBlock('custom_cards');
    $large = $this->createBlock('custom_cards', 'large');
    $other = $this->createBlock('wrapped_image');

    $sandbox = [];
    do {
      ys_themes_deploy_10308($sandbox);
    } while ($sandbox['#finished'] < 1);

    $this->assertSame('small', $this->reload($empty)->get('field_style_width')->value);
    $this->assertSame('large', $this->reload($large)->get('field_style_width')->value);
    $this->assertTrue($this->reload($other)->get('field_style_width')->isEmpty());
    $this->assertSame(1, $sandbox['updated']);
  }

  /**
   * The hook finishes in one pass when there are no Custom Card blocks.
   */
  public function testNoBlocks(): void {
    $sandbox = [];
    ys_themes_deploy_10308($sandbox);
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
