<?php

namespace Drupal\Tests\ys_themes\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Empty required settings are filled on every block revision (#2151).
 *
 * After a Revert, Layout Builder pins an older block revision. Any required
 * option list left empty there blocks Update with "field is required".
 * ys_themes_deploy_10307 fills them from each field's own default.
 *
 * @group ys_themes
 * @group yalesites
 */
class RequiredSettingsBackfillTest extends YsKernelTestBase {

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

    BlockContentType::create(['id' => 'text', 'label' => 'text'])->save();
    // Name => [required, default].
    $fields = [
      'field_padding_options' => [TRUE, 'no_padding'],
      'field_style_variation' => [TRUE, 'plain'],
      'field_no_default' => [TRUE, NULL],
      'field_optional' => [FALSE, 'x'],
    ];
    foreach ($fields as $name => [$required, $default]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => [
            'no_padding' => 'None',
            'plain' => 'Plain',
            'x' => 'X',
            'y' => 'Y',
          ],
        ],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
        'bundle' => 'text',
        'label' => $name,
        'required' => $required,
        'default_value' => $default === NULL ? [] : [['value' => $default]],
      ])->save();
    }

    require_once dirname(__DIR__, 3) . '/ys_themes.deploy.php';
  }

  /**
   * Empty required settings are filled on old and new revisions alike.
   */
  public function testFillsEmptySettingsOnEveryRevision(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $block = BlockContent::create(['type' => 'text', 'info' => 'old']);
    $block->set('field_padding_options', NULL);
    $block->set('field_style_variation', NULL);
    $block->set('field_optional', NULL);
    $block->save();
    $old_revision_id = (int) $block->getRevisionId();

    // A newer revision where the editor has already chosen a padding.
    $newer = $storage->createRevision($block);
    $newer->set('field_padding_options', 'y');
    $newer->save();

    // Age everything so a re-stamp to "now" would be visible.
    $database = \Drupal::database();
    $database->update('block_content_field_revision')->fields(['changed' => 1000000000])->execute();
    $database->update('block_content_field_data')->fields(['changed' => 1000000000])->execute();

    $sandbox = [];
    do {
      ys_themes_deploy_10307($sandbox);
    } while ($sandbox['#finished'] < 1);

    $storage->resetCache();
    $old = $storage->loadRevision($old_revision_id);
    $this->assertSame('no_padding', $old->get('field_padding_options')->value);
    $this->assertSame('plain', $old->get('field_style_variation')->value);
    // Optional settings and required ones with no default stay empty.
    $this->assertTrue($old->get('field_optional')->isEmpty());
    $this->assertTrue($old->get('field_no_default')->isEmpty());
    // The revision id and "last updated" date survive.
    $this->assertSame(1000000000, (int) $old->getChangedTime());
    $this->assertSame(1000000000, (int) $storage->load($block->id())->getChangedTime());
    // A chosen value is never overwritten.
    $kept = $storage->loadRevision($newer->getRevisionId());
    $this->assertSame('y', $kept->get('field_padding_options')->value);
    $this->assertSame('plain', $kept->get('field_style_variation')->value);
    $this->assertSame(2, $sandbox['backfilled']);
  }

  /**
   * The hook finishes in one pass when there are no blocks.
   */
  public function testNoBlocks(): void {
    $sandbox = [];
    ys_themes_deploy_10307($sandbox);
    $this->assertEquals(1, $sandbox['#finished']);
  }

  /**
   * A revision deleted mid-run is skipped; the rest are still filled.
   */
  public function testSkipsRevisionDeletedMidRun(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $kept = BlockContent::create(['type' => 'text', 'info' => 'kept']);
    $kept->set('field_padding_options', NULL);
    $kept->save();

    // The id list a first batch built includes a revision that is now gone.
    $sandbox = ['processed' => 0, 'backfilled' => 0, 'total' => 1];
    $database = \Drupal::database();
    $database->delete('block_content_revision')
      ->condition('revision_id', $kept->getRevisionId())
      ->execute();
    $storage->resetCache();

    ys_themes_deploy_10307($sandbox);
    $this->assertEquals(1, $sandbox['#finished']);
    $this->assertSame(0, $sandbox['backfilled']);
  }

}
