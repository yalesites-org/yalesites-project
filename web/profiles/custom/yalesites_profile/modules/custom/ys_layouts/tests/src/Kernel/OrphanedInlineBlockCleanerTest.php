<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;

/**
 * Tests detection and deletion of orphaned Layout Builder inline blocks.
 *
 * Removing a non-reusable block from a layout leaves its block_content behind,
 * and core cannot collect it for a node that still exists. See
 * OrphanedInlineBlockCleaner and the module README for why.
 *
 * These tests pin the safety bar: the cases the cleaner must REFUSE to collect
 * are a block still in a current layout, a block referenced only by an older
 * revision, and a block held by a non-revisionable entity. Getting any of them
 * wrong deletes content an editor can still reach.
 *
 * @group ys_layouts
 */
class OrphanedInlineBlockCleanerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block_content',
    // BlockContent::preDelete() deletes each placement via
    // getInstances(), which loads the 'block' entity type, so deleting a
    // block_content is impossible without the block module.
    'block',
    'layout_discovery',
    'layout_builder',
    // ys_layouts.services.yml injects quick_node_clone's form builder into
    // ys_layouts.block_cloner, so the container cannot compile without it.
    'quick_node_clone',
    // Stands in for a non-revisionable layout-bearing entity type.
    'entity_test',
    // enableLayoutBuilder() below instantiates the default layout_onecol
    // section, which ys_layouts_layout_alter() points at YSLayoutOneColumn
    // (extends YSLayoutOptions) -- that class resolves
    // ys_themes.color_token_resolver from the container.
    'formdazzle',
    'ys_themes',
    'ys_layouts',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('entity_test');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['system', 'field', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic'])->save();

    // Enabling Layout Builder overrides is what puts the
    // layout_builder__layout field on nodes of this bundle.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Gets the service under test.
   */
  protected function cleaner(): OrphanedInlineBlockCleanerInterface {
    return $this->container->get('ys_layouts.orphaned_inline_block_cleaner');
  }

  /**
   * Creates a block_content entity.
   *
   * @param string $label
   *   The block description.
   * @param bool $reusable
   *   Whether the block belongs to the reusable Custom Block Library.
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The saved block.
   */
  protected function createBlock(string $label, bool $reusable = FALSE): BlockContent {
    $block = BlockContent::create([
      'type' => 'basic',
      'info' => $label,
      'reusable' => $reusable,
    ]);
    $block->save();
    return $block;
  }

  /**
   * Builds a section whose components reference the given block revisions.
   *
   * Only block_revision_id is set (no block_serialized), so core's
   * InlineBlock::saveBlockContent() leaves these components untouched on save.
   *
   * @param int[] $revision_ids
   *   Block content revision IDs to reference.
   *
   * @return \Drupal\layout_builder\Section
   *   The section.
   */
  protected function sectionReferencing(array $revision_ids): Section {
    $components = [];
    foreach ($revision_ids as $revision_id) {
      $components[] = new SectionComponent(
        $this->container->get('uuid')->generate(),
        'content',
        [
          'id' => 'inline_block:basic',
          'block_revision_id' => $revision_id,
        ]
      );
    }
    return new Section('layout_onecol', [], $components);
  }

  /**
   * Creates a node whose current layout references the given block revisions.
   *
   * @param int[] $revision_ids
   *   Block content revision IDs to reference.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createNodeWithLayout(array $revision_ids): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => 'Layout test']);
    $node->set('layout_builder__layout', [['section' => $this->sectionReferencing($revision_ids)]]);
    $node->save();
    return $node;
  }

  /**
   * Empties a node's layout, optionally keeping the prior revision.
   */
  protected function clearLayout(NodeInterface $node, bool $new_revision): void {
    $node->setNewRevision($new_revision);
    $node->set('layout_builder__layout', []);
    $node->save();
  }

  /**
   * Gets the orphan IDs the cleaner would collect.
   */
  protected function orphans(): array {
    return $this->cleaner()->analyze()['orphans'];
  }

  /**
   * A block in a node's current layout must never be collected.
   *
   * This is the case the #1503 ticket mis-classified: block_content 341/342 are
   * referenced by node 95's current revision and render on a published page.
   */
  public function testBlockInCurrentLayoutIsNotAnOrphan(): void {
    $block = $this->createBlock('Live block');
    $this->createNodeWithLayout([$block->getRevisionId()]);

    $this->assertNotContains((int) $block->id(), $this->orphans());
  }

  /**
   * A block no revision references is an orphan.
   *
   * Saving without a new revision leaves nothing pointing at the block, which
   * is the genuine orphan case core cannot clean up for a surviving node.
   */
  public function testBlockUnreferencedByAnyRevisionIsAnOrphan(): void {
    $block = $this->createBlock('Removed block');
    $node = $this->createNodeWithLayout([$block->getRevisionId()]);
    $this->clearLayout($node, FALSE);

    $this->assertContains((int) $block->id(), $this->orphans());
  }

  /**
   * A block referenced only by an older revision must not be collected.
   *
   * It is reported separately instead, so the revision-history trade-off stays
   * a visible decision rather than a silent deletion.
   */
  public function testBlockReferencedOnlyByOldRevisionIsReportedNotCollected(): void {
    $block = $this->createBlock('Old revision only');
    $node = $this->createNodeWithLayout([$block->getRevisionId()]);
    $this->clearLayout($node, TRUE);

    $report = $this->cleaner()->analyze();
    $this->assertNotContains((int) $block->id(), $report['orphans']);
    $this->assertContains((int) $block->id(), $report['revision_only']);
  }

  /**
   * A block used by a Layout Builder default layout must not be collected.
   *
   * Defaults live in config, not on any node, so a node-only sweep would report
   * these as orphans and delete live content.
   */
  public function testBlockInLayoutBuilderDefaultIsNotAnOrphan(): void {
    $block = $this->createBlock('Default layout block');

    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $display->appendSection($this->sectionReferencing([$block->getRevisionId()]));
    $display->save();

    $this->assertNotContains((int) $block->id(), $this->orphans());
  }

  /**
   * A block on a non-revisionable layout-bearing entity is not an orphan.
   *
   * Section Library's section_library_template holds layouts but has no
   * revision table, so an allRevisions() query against it throws. entity_test
   * stands in for that shape here.
   */
  public function testBlockOnNonRevisionableEntityIsNotAnOrphan(): void {
    FieldStorageConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'entity_test',
      'type' => 'layout_section',
    ])->save();
    FieldConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();

    $block = $this->createBlock('Held by a non-revisionable entity');
    $entity = EntityTest::create(['name' => 'Layout holder']);
    $entity->set('layout_builder__layout', [
      ['section' => $this->sectionReferencing([$block->getRevisionId()])],
    ]);
    $entity->save();

    $this->assertNotContains((int) $block->id(), $this->orphans());
  }

  /**
   * Reusable library blocks are not inline blocks and are never collected.
   */
  public function testReusableBlockIsNeverAnOrphan(): void {
    $block = $this->createBlock('Library block', TRUE);

    $this->assertNotContains((int) $block->id(), $this->orphans());
  }

  /**
   * Deleting collects orphans, clears their usage rows, and spares live blocks.
   *
   * Because deleteOrphans() takes no ID list, deleting something still on a
   * page is structurally impossible; this pins both halves in one sweep.
   */
  public function testDeleteRemovesOnlyOrphans(): void {
    $live = $this->createBlock('Live block');
    $this->createNodeWithLayout([$live->getRevisionId()]);

    $orphan = $this->createBlock('Doomed block');
    $orphan_id = (int) $orphan->id();
    $node = $this->createNodeWithLayout([$orphan->getRevisionId()]);
    $this->container->get('inline_block.usage')->addUsage($orphan_id, $node);
    $this->clearLayout($node, FALSE);

    $this->assertSame(1, $this->cleaner()->deleteOrphans());

    $storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $this->assertNull($storage->load($orphan_id));
    $this->assertNotNull($storage->load((int) $live->id()));
    $this->assertEmpty($this->usageRows($orphan_id));
  }

  /**
   * Gets inline_block_usage rows for a block.
   */
  protected function usageRows(int $block_id): array {
    return $this->container->get('database')
      ->select('inline_block_usage', 'u')
      ->fields('u', ['block_content_id'])
      ->condition('block_content_id', $block_id)
      ->execute()
      ->fetchCol();
  }

}
