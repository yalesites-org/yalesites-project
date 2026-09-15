<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the retroactive repair of double-encoded link URIs in field tables.
 *
 * Issue #1494: links stored before #683 kept their percent-encoding and render
 * double-encoded. The repair writes the field tables directly, so this covers
 * what an entity save would have got wrong: the revision row Layout Builder
 * renders from, the absence of new revisions, and the "changed" timestamp
 * staying put.
 *
 * @group ys_core
 * @group yalesites
 */
class DoubleEncodedLinkUriRepairTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'text',
    'block_content',
  ];

  /**
   * A URI stored before #683, still holding its percent-encoding.
   */
  const BROKEN_URI = 'internal:/sites/default/files/2025-11/YaleSites%20Profile%20Import%20Template.xlsx';

  /**
   * The same URI as the Linkit widget stores it since #683.
   */
  const FIXED_URI = 'internal:/sites/default/files/2025-11/YaleSites Profile Import Template.xlsx';

  /**
   * An external URI with an escape that must never be rewritten.
   */
  const EXTERNAL_URI = 'https://example.com/a%20b.pdf';

  /**
   * The field's dedicated data table.
   */
  protected string $dataTable;

  /**
   * The field's dedicated revision table.
   */
  protected string $revisionTable;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');

    BlockContentType::create(['id' => 'button_link', 'label' => 'Button link'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_button_link',
      'entity_type' => 'block_content',
      'type' => 'link',
      'cardinality' => 2,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_button_link',
      'entity_type' => 'block_content',
      'bundle' => 'button_link',
      'label' => 'Button link',
    ])->save();

    $storage = \Drupal::entityTypeManager()->getStorage('block_content');
    $definition = \Drupal::service('entity_field.manager')
      ->getFieldStorageDefinitions('block_content')['field_button_link'];
    $this->dataTable = $storage->getTableMapping()->getDedicatedDataTableName($definition);
    $this->revisionTable = $storage->getTableMapping()->getDedicatedRevisionTableName($definition);

    // The helper lives in ys_core.install; load it without enabling ys_core,
    // which would pull in cas/role_delegation and a much heavier container.
    // Resolved from this file rather than from \Drupal::root(), so the test
    // always covers the copy it ships beside instead of whichever checkout
    // happens to be bootstrapped.
    require_once __DIR__ . '/../../../ys_core.install';
  }

  /**
   * Returns the stored URI for one row of a field table.
   */
  protected function storedUri(string $table, int $revision_id, int $delta): ?string {
    return \Drupal::database()->select($table, 'f')
      ->fields('f', ['field_button_link_uri'])
      ->condition('revision_id', $revision_id)
      ->condition('delta', $delta)
      ->execute()
      ->fetchField() ?: NULL;
  }

  /**
   * The repair fixes old revisions and never creates a revision of its own.
   */
  public function testRepairsBothDataAndRevisionRows(): void {
    // A block an editor already repaired by re-saving it: its default revision
    // is correct, but the revision Layout Builder points at is still broken.
    $resaved = BlockContent::create([
      'type' => 'button_link',
      'info' => 'Re-saved block',
      'reusable' => FALSE,
      'field_button_link' => [
        ['uri' => self::BROKEN_URI, 'title' => 'Template'],
        ['uri' => self::EXTERNAL_URI, 'title' => 'External'],
      ],
    ]);
    $resaved->save();
    $old_revision_id = (int) $resaved->getRevisionId();

    $resaved->setNewRevision(TRUE);
    $resaved->get('field_button_link')->set(0, ['uri' => self::FIXED_URI, 'title' => 'Template']);
    $resaved->save();
    $current_revision_id = (int) $resaved->getRevisionId();
    $this->assertNotSame($old_revision_id, $current_revision_id, 'The fixture really has two revisions.');

    // A block nobody has touched: both its tables still hold the broken value.
    $untouched = BlockContent::create([
      'type' => 'button_link',
      'info' => 'Untouched block',
      'reusable' => FALSE,
      'field_button_link' => [['uri' => self::BROKEN_URI, 'title' => 'Template']],
    ]);
    $untouched->save();
    $untouched_revision_id = (int) $untouched->getRevisionId();

    $changed_before = [
      $resaved->id() => $resaved->getChangedTime(),
      $untouched->id() => $untouched->getChangedTime(),
    ];
    $revision_count_before = (int) \Drupal::database()
      ->select('block_content_revision', 'r')->countQuery()->execute()->fetchField();

    $report = ys_core_repair_double_encoded_link_uris();

    // Every broken row is corrected, in both tables.
    $this->assertSame(self::FIXED_URI, $this->storedUri($this->revisionTable, $old_revision_id, 0), 'The old revision Layout Builder renders from is corrected.');
    $this->assertSame(self::FIXED_URI, $this->storedUri($this->dataTable, $untouched_revision_id, 0), 'The default field table is corrected.');
    $this->assertSame(self::FIXED_URI, $this->storedUri($this->revisionTable, $untouched_revision_id, 0), 'The matching revision row is corrected.');
    $this->assertSame(3, $report['repaired'], 'Exactly the three broken rows are rewritten.');

    // The already-correct row and the external links are not rewritten.
    $this->assertSame(self::FIXED_URI, $this->storedUri($this->revisionTable, $current_revision_id, 0), 'An already-correct row is unchanged.');
    $this->assertSame(self::EXTERNAL_URI, $this->storedUri($this->revisionTable, $old_revision_id, 1), 'An external URI keeps its escapes.');
    $this->assertSame(self::EXTERNAL_URI, $this->storedUri($this->dataTable, $current_revision_id, 1), 'An external URI keeps its escapes in the data table too.');
    $this->assertContains(self::EXTERNAL_URI, $report['left_alone_uris'], 'Values left alone are reported for a manual check.');

    // Nothing that an entity save would have disturbed has moved.
    $revision_count_after = (int) \Drupal::database()
      ->select('block_content_revision', 'r')->countQuery()->execute()->fetchField();
    $this->assertSame($revision_count_before, $revision_count_after, 'The repair creates no new revisions.');

    \Drupal::entityTypeManager()->getStorage('block_content')->resetCache();
    foreach ($changed_before as $id => $changed) {
      $reloaded = BlockContent::load($id);
      $this->assertSame($changed, $reloaded->getChangedTime(), 'The changed timestamp does not move.');
    }

    // The corrected value is what the entity now returns.
    $this->assertSame(self::FIXED_URI, BlockContent::load($untouched->id())->get('field_button_link')->first()->uri, 'The corrected URI is visible through the entity API.');
  }

  /**
   * Running the repair a second time changes nothing.
   */
  public function testRepairIsIdempotent(): void {
    $block = BlockContent::create([
      'type' => 'button_link',
      'info' => 'Untouched block',
      'reusable' => FALSE,
      'field_button_link' => [['uri' => self::BROKEN_URI, 'title' => 'Template']],
    ]);
    $block->save();

    $first = ys_core_repair_double_encoded_link_uris();
    $this->assertSame(2, $first['repaired'], 'The first run corrects the data and revision rows.');

    $second = ys_core_repair_double_encoded_link_uris();
    $this->assertSame(0, $second['repaired'], 'A second run has nothing left to correct.');
    $this->assertSame(self::FIXED_URI, $this->storedUri($this->revisionTable, (int) $block->getRevisionId(), 0), 'The value is not decoded twice.');
  }

  /**
   * A dry run reports what it would change without writing anything.
   */
  public function testDryRunWritesNothing(): void {
    $block = BlockContent::create([
      'type' => 'button_link',
      'info' => 'Untouched block',
      'reusable' => FALSE,
      'field_button_link' => [['uri' => self::BROKEN_URI, 'title' => 'Template']],
    ]);
    $block->save();

    $report = ys_core_repair_double_encoded_link_uris(TRUE);

    $this->assertSame(2, $report['repaired'], 'The dry run counts the rows it would correct.');
    $this->assertSame(self::BROKEN_URI, $this->storedUri($this->dataTable, (int) $block->getRevisionId(), 0), 'The dry run leaves the data untouched.');
    $this->assertNotEmpty($report['changes'], 'The dry run lists the changes it would make.');
  }

}
