<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\ys_layouts\EventSubscriber\PinnedInlineBlockDependency;

/**
 * Tests inline block child access when the layout pins an older revision.
 *
 * Covers issue #2181: after a page revert, an inline Callout's items were
 * denied ("Non-reusable blocks must set an access dependency") and the block
 * rendered empty.
 *
 * @group ys_layouts
 * @coversDefaultClass \Drupal\ys_layouts\EventSubscriber\PinnedInlineBlockDependency
 */
class PinnedInlineBlockDependencyTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'block_content',
    'layout_builder',
    'layout_discovery',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);

    // Register the subscriber as the module's services file would.
    $this->container->get('event_dispatcher')->addSubscriber(
      new PinnedInlineBlockDependency(
        $this->container->get('entity_type.manager'),
        $this->container->get('database'),
        $this->container->get('inline_block.usage'),
        $this->container->get('plugin.manager.layout_builder.section_storage'),
      )
    );

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    ParagraphsType::create(['id' => 'callout_item', 'label' => 'Callout item'])->save();
    BlockContentType::create(['id' => 'callout', 'label' => 'Callout'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'block_content',
      'bundle' => 'callout',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['callout_item' => 'callout_item']],
      ],
    ])->save();
    // Paragraph status field defaults published.
    Role::create(['id' => 'viewer', 'label' => 'Viewer', 'permissions' => ['access content']])->save();
  }

  /**
   * Builds a page whose layout pins block revision $pinned_revision_id.
   */
  protected function createPageWithPinnedBlock(BlockContent $block, int $revision_id): Node {
    $component = new SectionComponent('c1', 'content', [
      'id' => 'inline_block:callout',
      'label' => 'Callout',
      'provider' => 'layout_builder',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_revision_id' => $revision_id,
      'block_serialized' => NULL,
      'context_mapping' => [],
    ]);
    $section = new Section('layout_onecol', [], [$component]);
    $node = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'status' => 1,
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $node->save();
    $this->container->get('inline_block.usage')->addUsage($block->id(), $node);
    return $node;
  }

  /**
   * An item of a block pinned to an older revision is viewable.
   */
  public function testChildOfPinnedOlderRevisionIsAccessible(): void {
    $item = Paragraph::create(['type' => 'callout_item']);
    $item->save();
    $block = BlockContent::create([
      'type' => 'callout',
      'info' => 'Callout',
      'reusable' => FALSE,
      'field_items' => [
        ['target_id' => $item->id(), 'target_revision_id' => $item->getRevisionId()],
      ],
    ]);
    $block->save();
    $oldRevisionId = (int) $block->getRevisionId();
    $item->set('parent_type', 'block_content')->set('parent_id', $block->id())
      ->set('parent_field_name', 'field_items')->save();

    $page = $this->createPageWithPinnedBlock($block, $oldRevisionId);

    // A newer default revision, as left behind by a revert.
    $block->setNewRevision(TRUE);
    $block->setInfo('Callout v2');
    $block->save();
    $this->assertNotEquals($oldRevisionId, (int) $block->getRevisionId());

    $viewer = User::create(['name' => 'viewer', 'roles' => ['viewer']]);
    $viewer->save();

    $loaded = Paragraph::load($item->id());
    $this->assertTrue($loaded->access('view', $viewer), 'Callout item is viewable on a reverted page.');
    $this->assertTrue($page->access('view', $viewer));
  }

  /**
   * A block no longer present in the layout still denies its children.
   */
  public function testChildOfBlockNotInLayoutIsDenied(): void {
    $item = Paragraph::create(['type' => 'callout_item']);
    $item->save();
    $block = BlockContent::create([
      'type' => 'callout',
      'info' => 'Callout',
      'reusable' => FALSE,
      'field_items' => [
        ['target_id' => $item->id(), 'target_revision_id' => $item->getRevisionId()],
      ],
    ]);
    $block->save();
    $item->set('parent_type', 'block_content')->set('parent_id', $block->id())
      ->set('parent_field_name', 'field_items')->save();

    $page = $this->createPageWithPinnedBlock($block, (int) $block->getRevisionId());
    $page->set(OverridesSectionStorage::FIELD_NAME, [new Section('layout_onecol')]);
    $page->save();

    $viewer = User::create(['name' => 'viewer2', 'roles' => ['viewer']]);
    $viewer->save();
    $this->assertFalse(Paragraph::load($item->id())->access('view', $viewer));
  }

}
