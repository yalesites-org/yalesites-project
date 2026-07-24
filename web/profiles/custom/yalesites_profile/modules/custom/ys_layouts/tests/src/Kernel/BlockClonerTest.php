<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\ys_layouts\Service\BlockCloner;

/**
 * Tests the BlockCloner service used by the Layout Builder "Clone" operation.
 *
 * Covers issue #190 acceptance criteria that are unit-testable without a
 * browser: a cloned inline block keeps all configuration (its paragraphs are
 * deep-duplicated, not shared), the clone is positioned directly after the
 * original, and reusable / non-inline blocks are excluded from cloning.
 *
 * @group ys_layouts
 * @group yalesites
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\BlockCloner
 */
class BlockClonerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'entity_reference_revisions',
    'paragraphs',
    'block_content',
    'layout_builder',
    'layout_discovery',
    'quick_node_clone',
  ];

  /**
   * The block cloner service under test.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner
   */
  protected BlockCloner $blockCloner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('system', ['sequences']);

    // Gallery paragraph + block bundle mirroring the real content model:
    // block_content(gallery).field_gallery_items[] -> paragraph(gallery_item).
    ParagraphsType::create([
      'id' => 'gallery_item',
      'label' => 'Gallery Item',
    ])->save();

    BlockContentType::create([
      'id' => 'gallery',
      'label' => 'Gallery',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_gallery_items',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('block_content', 'field_gallery_items'),
      'bundle' => 'gallery',
      'label' => 'Gallery Items',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['gallery_item' => 'gallery_item'],
        ],
      ],
    ])->save();

    // BlockCloner is instantiated directly rather than via the container so the
    // test does not have to enable the ys_layouts module (which pulls in
    // calendar_link / ys_localist). The class is autoloadable via PSR-4.
    $this->blockCloner = new BlockCloner(
      $this->container->get('quick_node_clone.entity.form_builder'),
      $this->container->get('uuid'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory')->get('ys_layouts'),
    );
  }

  /**
   * Builds a saved gallery inline-block component referencing one paragraph.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   An inline_block:gallery component keyed by block_revision_id.
   */
  protected function createGalleryComponent(): SectionComponent {
    $paragraph = Paragraph::create(['type' => 'gallery_item']);
    $paragraph->save();

    $block_content = BlockContent::create([
      'type' => 'gallery',
      'info' => 'Test Gallery Block',
      'field_gallery_items' => [
        [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ],
      ],
    ]);
    $block_content->save();

    return new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      [
        'id' => 'inline_block:gallery',
        'block_revision_id' => $block_content->getRevisionId(),
        'block_serialized' => NULL,
        'label' => 'Gallery',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'provider' => 'layout_builder',
        'context_mapping' => [],
      ]
    );
  }

  /**
   * A cloned inline block is inserted directly after the original.
   *
   * @covers ::cloneComponent
   */
  public function testCloneInsertsDuplicateDirectlyAfterOriginal(): void {
    $original = $this->createGalleryComponent();
    $trailing = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      ['id' => 'system_powered_by_block']
    );
    $section = new Section('layout_onecol', [], [$original, $trailing]);

    $clone = $this->blockCloner->cloneComponent($section, $original->getUuid());

    $this->assertNotNull($clone, 'Cloning an inline block returns the new component.');

    // Rendered order is weight-based (getComponentsByRegion), not insertion
    // order. The clone must sit directly after the original: [original, clone,
    // trailing].
    $uuids = array_keys($section->getComponentsByRegion('content'));
    $this->assertSame(
      [$original->getUuid(), $clone->getUuid(), $trailing->getUuid()],
      $uuids,
      'The clone is positioned directly after the original component.'
    );
  }

  /**
   * A cloned inline block retains its configuration and deep-clones paragraphs.
   *
   * @covers ::cloneComponent
   */
  public function testCloneRetainsConfigAndDuplicatesParagraphs(): void {
    $original = $this->createGalleryComponent();
    $section = new Section('layout_onecol', [], [$original]);

    $clone = $this->blockCloner->cloneComponent($section, $original->getUuid());
    $config = $clone->toArray()['configuration'];

    // The clone must carry its own serialized block, not a revision reference.
    $this->assertNotEmpty($config['block_serialized'], 'Clone holds a serialized block.');
    $this->assertNull($config['block_revision_id'], 'Clone drops the original revision id.');
    $this->assertSame('inline_block:gallery', $config['id'], 'Clone keeps the block plugin id.');
    $this->assertSame('Gallery', $config['label'], 'Clone keeps the block label.');

    // The paragraph inside the clone must be a NEW unsaved entity
    // (id === NULL), proving it was deep-duplicated, not shared with the
    // original.
    // @codingStandardsIgnoreStart
    $cloned_block = unserialize($config['block_serialized']);
    // @codingStandardsIgnoreEnd
    $cloned_paragraph = $cloned_block->get('field_gallery_items')->first()->entity;
    $this->assertNotNull($cloned_paragraph, 'Clone still references a paragraph.');
    $this->assertNull(
      $cloned_paragraph->id(),
      'The paragraph in the clone is a new unsaved entity, not the shared original.'
    );
  }

  /**
   * Reusable / non-inline blocks are excluded from cloning.
   *
   * The clone guard is `instanceof InlineBlock`; a reusable block uses the
   * block_content plugin and any other placement uses a non-inline plugin, so
   * both hit the same exclusion path. A core (non-inline) block stands in here
   * because it resolves without extra content fixtures.
   *
   * @covers ::cloneComponent
   */
  public function testNonInlineBlockIsNotCloned(): void {
    $reusable = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      ['id' => 'system_powered_by_block']
    );
    $section = new Section('layout_onecol', [], [$reusable]);

    $clone = $this->blockCloner->cloneComponent($section, $reusable->getUuid());

    $this->assertNull($clone, 'A non-inline block is not cloned.');
    $this->assertCount(1, $section->getComponents(), 'The section is left unchanged.');
  }

}
