<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\layout_builder\Traits\EnableLayoutBuilderTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the migration that opts existing 70/30 sections into their divider.
 *
 * The Two column (70/30) separator is now gated on the section's Divider
 * setting instead of being drawn unconditionally (yalesites-project#1514).
 * Every 70/30 section that exists has that setting stored as off, because the
 * control had no effect on this layout, so without this migration the change
 * silently removes a separator those sections render today.
 *
 * This is a Kernel rather than a Unit test on purpose:
 * `Section::getLayoutSettings()` instantiates the layout plugin through the
 * container to merge in its defaults, so a mocked Section proves nothing about
 * what actually gets written -- and what gets written to production content is
 * the whole point of a migration.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\LayoutUpdater
 *
 * @group yalesites
 * @group ys_layouts
 */
class SeventyThirtyDividerMigrationTest extends KernelTestBase {

  use EnableLayoutBuilderTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
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
    // layout_builder's inline-block handling queries block_content on every
    // entity save, so its schema has to exist even though no test uses one.
    $this->installEntitySchema('block_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Overridable Layout Builder is what puts `layout_builder__layout` on the
    // node, which is the field the migration walks.
    $this->enableLayoutBuilder(LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ]));
  }

  /**
   * Creates a published page whose layout is overridden with $sections.
   *
   * @param string $title
   *   The node title.
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to store on the node.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createNodeWithSections(string $title, array $sections) {
    $node = Node::create(['type' => 'page', 'title' => $title, 'status' => 1]);
    $node->set('layout_builder__layout', $sections);
    $node->save();

    return $node;
  }

  /**
   * Reads a node back from storage rather than from the static cache.
   *
   * @param int $nid
   *   The node id.
   *
   * @return \Drupal\node\NodeInterface
   *   The node as persisted.
   */
  protected function reloadNode(int $nid) {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);

    return $storage->load($nid);
  }

  /**
   * Reads a node's stored sections back from storage, not from memory.
   *
   * @param int $nid
   *   The node id.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections as persisted.
   */
  protected function reloadSections(int $nid): array {
    return $this->reloadNode($nid)
      ->get('layout_builder__layout')
      ->getSections();
  }

  /**
   * The service under test.
   *
   * @return \Drupal\ys_layouts\Service\LayoutUpdater
   *   The layout updater.
   */
  protected function updater() {
    return $this->container->get('ys_layouts.updater');
  }

  /**
   * An existing 70/30 section is opted in; its other settings are preserved.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testExistingSeventyThirtySectionGetsTheDivider(): void {
    $node = $this->createNodeWithSections('70/30 with a theme', [
      new Section('ys_layout_two_column', [
        'label' => 'Content Section',
        'theme' => 'five',
      ]),
    ]);

    // Precondition: the separator is currently off, which is why it needs the
    // migration at all.
    $before = $this->reloadSections((int) $node->id())[0]->getLayoutSettings();
    $this->assertEmpty($before['divider']);

    $this->assertSame(1, $this->updater()->enableSeventyThirtyDividers());

    $after = $this->reloadSections((int) $node->id())[0]->getLayoutSettings();
    $this->assertSame(1, $after['divider']);
    $this->assertSame('five', $after['theme'], 'The section theme was lost.');
    $this->assertSame('Content Section', $after['label'], 'The label was lost.');
  }

  /**
   * The other multi-column layouts are left exactly as they were.
   *
   * They have always respected the toggle, so turning it on would draw a
   * separator the editor deliberately left off.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testOtherLayoutsAreNotTouched(): void {
    $node = $this->createNodeWithSections('Every layout', [
      new Section('ys_layout_two_column_50_50'),
      new Section('ys_layout_three_column_33_33_33'),
      new Section('layout_onecol'),
    ]);

    $this->assertSame(0, $this->updater()->enableSeventyThirtyDividers());

    foreach ($this->reloadSections((int) $node->id()) as $section) {
      $this->assertEmpty(
        $section->getLayoutSettings()['divider'],
        $section->getLayoutId() . ' had its divider turned on.'
      );
    }
  }

  /**
   * Every 70/30 section on a node is migrated, not just the first.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testEverySeventyThirtySectionOnTheNodeIsMigrated(): void {
    $node = $this->createNodeWithSections('Two 70/30s and a 50/50', [
      new Section('ys_layout_two_column'),
      new Section('ys_layout_two_column_50_50'),
      new Section('ys_layout_two_column'),
    ]);

    // One node saved, even though two sections changed.
    $this->assertSame(1, $this->updater()->enableSeventyThirtyDividers());

    $sections = $this->reloadSections((int) $node->id());
    $this->assertSame(1, $sections[0]->getLayoutSettings()['divider']);
    $this->assertEmpty($sections[1]->getLayoutSettings()['divider']);
    $this->assertSame(1, $sections[2]->getLayoutSettings()['divider']);
  }

  /**
   * Re-running the migration saves nothing.
   *
   * A deploy hook can be re-run, and this one walks every node on the site,
   * so a second pass must not churn revisions or changed timestamps.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testTheMigrationIsIdempotent(): void {
    $node = $this->createNodeWithSections('Runs twice', [
      new Section('ys_layout_two_column'),
    ]);

    $this->assertSame(1, $this->updater()->enableSeventyThirtyDividers());
    $changedAfterFirstRun = $this->reloadNode((int) $node->id())->getChangedTime();

    $this->assertSame(
      0,
      $this->updater()->enableSeventyThirtyDividers(),
      'The second run re-saved a node it had already migrated.'
    );
    $this->assertSame(
      $changedAfterFirstRun,
      $this->reloadNode((int) $node->id())->getChangedTime()
    );
  }

  /**
   * The deploy hook itself does the migration, not just the service.
   *
   * A migration written on the service but never reached from
   * `ys_layouts.deploy.php` runs nowhere, and a hook naming the wrong service
   * or method fatals mid-deploy. Invoking the real hook is the only assertion
   * that covers the wiring; asserting on the file's source text would pass or
   * fail on formatting instead.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testTheDeployHookMigratesAndReportsTheCount(): void {
    $node = $this->createNodeWithSections('Migrated by the hook', [
      new Section('ys_layout_two_column'),
    ]);

    $this->container->get('module_handler')
      ->loadInclude('ys_layouts', 'php', 'ys_layouts.deploy');
    $this->assertTrue(function_exists('ys_layouts_deploy_9005'));

    $message = (string) ys_layouts_deploy_9005();

    // The whole string, not just the digit: `assertStringContainsString('1')`
    // would also pass for 10, 21 or 1613, so it would not pin the count at all.
    $this->assertSame(
      'Enabled the 70/30 divider on 1 node revision.',
      $message
    );
    $this->assertSame(
      1,
      $this->reloadSections((int) $node->id())[0]->getLayoutSettings()['divider']
    );
  }

  /**
   * A pending draft is migrated too, and stays a draft.
   *
   * These content types are under content moderation, so a node can have a
   * published revision plus an unpublished draft carrying its own copy of the
   * sections. Migrating only the published one looks correct until the editor
   * publishes that draft and the separator vanishes -- the regression this
   * migration exists to prevent. Saving the draft must not promote it to the
   * default revision or spawn a new one.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testPendingDraftIsMigratedWithoutBeingPublished(): void {
    $node = $this->createNodeWithSections('Published plus a draft', [
      new Section('ys_layout_two_column'),
    ]);
    $publishedVid = (int) $node->getRevisionId();

    // A forward revision: newer than the default, but not the default.
    $node->set('layout_builder__layout', [new Section('ys_layout_two_column')]);
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $draftVid = (int) $storage->getLatestRevisionId($node->id());
    $this->assertNotSame($publishedVid, $draftVid, 'No pending revision was created.');

    // Both revisions, one save each.
    $this->assertSame(2, $this->updater()->enableSeventyThirtyDividers());

    $storage->resetCache([$node->id()]);
    foreach ([$publishedVid, $draftVid] as $vid) {
      $sections = $storage->loadRevision($vid)
        ->get('layout_builder__layout')
        ->getSections();
      $this->assertSame(
        1,
        $sections[0]->getLayoutSettings()['divider'],
        "Revision {$vid} was not migrated."
      );
    }

    // The draft is still a draft, and no extra revision was spawned.
    $this->assertSame(
      $publishedVid,
      (int) $this->reloadNode((int) $node->id())->getRevisionId(),
      'The migration promoted the draft to the default revision.'
    );
    $this->assertSame(
      $draftVid,
      (int) $storage->getLatestRevisionId($node->id()),
      'The migration spawned a new revision instead of writing in place.'
    );
  }

  /**
   * A 50/50-only node is a pre-filter candidate but is left untouched.
   *
   * `getSeventyThirtyNodeIds()` matches the layout id as a substring, and
   * `ys_layout_two_column` is a prefix of `ys_layout_two_column_50_50`, so
   * such a node is deliberately a false positive of the query. The per-section
   * layout-id check is what stops it being modified -- without this test that
   * safeguard could be dropped and only a 50/50 site would notice.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testTheFiftyFiftyPrefixCandidateIsNotModified(): void {
    $node = $this->createNodeWithSections('Only a 50/50', [
      new Section('ys_layout_two_column_50_50'),
    ]);

    $this->assertSame(0, $this->updater()->enableSeventyThirtyDividers());
    $this->assertEmpty(
      $this->reloadSections((int) $node->id())[0]->getLayoutSettings()['divider'],
      'A 50/50 section was migrated because the layout id matched as a prefix.'
    );
  }

  /**
   * A node whose layout was never overridden is skipped.
   *
   * Those sections live on the content type's default display in config, not
   * on the node, so there is nothing here for the migration to write -- which
   * is exactly why the shipped default profile display carries `divider: 1`
   * itself. Guarded by SeventyThirtyDividerDefaultsTest.
   *
   * @covers ::enableSeventyThirtyDividers
   */
  public function testNodesWithNoOverrideAreSkipped(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Default layout',
      'status' => 1,
    ]);
    $node->save();

    $this->assertTrue($node->get('layout_builder__layout')->isEmpty());
    $this->assertSame(0, $this->updater()->enableSeventyThirtyDividers());
  }

}
