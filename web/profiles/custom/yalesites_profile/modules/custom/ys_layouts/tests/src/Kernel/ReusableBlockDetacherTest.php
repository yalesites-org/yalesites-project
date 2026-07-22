<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\SectionComponent;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\ys_layouts\ReusableBlockDetacher;

/**
 * Tests detaching a placed reusable block into an independent inline block.
 *
 * Covers issue #1449: an editor can convert a reusable (shared) block placement
 * back into a normal, non-reusable block tied to just that page (instance-level
 * detach), without affecting the original reusable block or its other
 * placements.
 *
 * @group ys_layouts
 * @coversDefaultClass \Drupal\ys_layouts\ReusableBlockDetacher
 */
class ReusableBlockDetacherTest extends KernelTestBase {

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
  ];

  /**
   * The detacher under test.
   *
   * @var \Drupal\ys_layouts\ReusableBlockDetacher
   */
  protected ReusableBlockDetacher $detacher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');

    // A paragraph type used as a composed child of the block.
    ParagraphsType::create([
      'id' => 'gallery_item',
      'label' => 'Gallery Item',
    ])->save();

    // A block_content type that composes paragraphs.
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
      'field_name' => 'field_gallery_items',
      'entity_type' => 'block_content',
      'bundle' => 'gallery',
      'label' => 'Gallery Items',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['gallery_item' => 'gallery_item'],
        ],
      ],
    ])->save();

    // The detacher only needs the entity repository; instantiate it directly so
    // the test does not have to enable ys_layouts and its dependency chain
    // (ys_localist -> migrate, etc.). The class autoloads via the PHPUnit
    // bootstrap namespace registration.
    $this->detacher = new ReusableBlockDetacher($this->container->get('entity.repository'));
  }

  /**
   * Creates a saved reusable block with a paragraph and its placed component.
   *
   * @return array
   *   A [BlockContent, Paragraph, SectionComponent] tuple.
   */
  protected function createReusablePlacement(): array {
    $paragraph = Paragraph::create(['type' => 'gallery_item']);
    $paragraph->save();

    $block = BlockContent::create([
      'type' => 'gallery',
      'info' => 'Shared Gallery',
      'reusable' => TRUE,
      'field_gallery_items' => [
        [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ],
      ],
    ]);
    $block->save();

    // A placed reusable block references the block by UUID via the
    // block_content:<uuid> derivative plugin.
    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      [
        'id' => 'block_content:' . $block->uuid(),
        'label' => 'Shared Gallery',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'provider' => 'block_content',
        'context_mapping' => [],
      ]
    );

    return [$block, $paragraph, $component];
  }

  /**
   * Returns the independent copy serialized into a detached component.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   A component that has been through detach().
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The deserialized, non-reusable copy.
   */
  protected function getSerializedCopy(SectionComponent $component): BlockContent {
    // The serialized data originates from this test's own controlled code, so
    // unserializing it is safe.
    // @codingStandardsIgnoreStart
    return unserialize($component->get('configuration')['block_serialized']);
    // @codingStandardsIgnoreEnd
  }

  /**
   * A placed reusable block is converted to an inline block in place.
   *
   * @covers ::detach
   */
  public function testDetachSwapsToInlineBlock(): void {
    [, , $component] = $this->createReusablePlacement();

    $this->detacher->detach($component);

    $config = $component->get('configuration');
    $this->assertSame('inline_block:gallery', $config['id'], 'The component now uses the inline_block plugin for the block bundle.');
    $this->assertNull($config['block_revision_id'], 'block_revision_id is null so Layout Builder saves the serialized copy as a new block.');
    $this->assertNotEmpty($config['block_serialized'], 'The independent copy is serialized into the component.');
    // The visible label configuration is preserved.
    $this->assertSame('Shared Gallery', $config['label']);
    $this->assertSame('visible', $config['label_display']);
  }

  /**
   * The detached copy is non-reusable; the original stays reusable and intact.
   *
   * @covers ::detach
   */
  public function testDetachLeavesOriginalReusableUntouched(): void {
    [$block, , $component] = $this->createReusablePlacement();
    $original_uuid = $block->uuid();

    $this->detacher->detach($component);

    $copy = $this->getSerializedCopy($component);
    $this->assertFalse((bool) $copy->get('reusable')->value, 'The detached copy is non-reusable.');

    // The original reusable block is unchanged and still loadable.
    $reloaded = $this->container->get('entity.repository')->loadEntityByUuid('block_content', $original_uuid);
    $this->assertNotNull($reloaded, 'The original reusable block still exists.');
    $this->assertTrue((bool) $reloaded->get('reusable')->value, 'The original block is still reusable.');
  }

  /**
   * Composed paragraphs are deep-cloned so the copy owns independent children.
   *
   * @covers ::detach
   */
  public function testDetachDeepClonesParagraphs(): void {
    [, $paragraph, $component] = $this->createReusablePlacement();
    $original_paragraph_id = $paragraph->id();
    $this->assertNotNull($original_paragraph_id);

    $this->detacher->detach($component);

    $copy = $this->getSerializedCopy($component);
    $items = $copy->get('field_gallery_items');
    $this->assertFalse($items->isEmpty(), 'The copy still has its gallery items.');

    $copied_paragraph = $items->first()->entity;
    $this->assertNotNull($copied_paragraph, 'The copy references a paragraph entity.');
    $this->assertNull(
      $copied_paragraph->id(),
      'The copied paragraph must be a new unsaved entity (id === NULL); a non-null id means the original paragraph was shared, not cloned.'
    );

    // Persistence proof: saving the detached copy (as Layout Builder does on
    // layout save) writes a NEW paragraph row, independent of the original
    // reusable block's paragraph, rather than re-pointing at the shared one.
    $copy->save();
    $persisted_paragraph_id = $copy->get('field_gallery_items')->first()->entity->id();
    $this->assertNotNull($persisted_paragraph_id, 'The saved copy persisted its own paragraph.');
    $this->assertNotEquals(
      $original_paragraph_id,
      $persisted_paragraph_id,
      'The saved copy owns a different paragraph entity than the original reusable block.'
    );
  }

  /**
   * Detaching a non-reusable (inline) component is rejected.
   *
   * @covers ::detach
   * @covers ::isReusableBlockComponent
   */
  public function testDetachRejectsInlineComponent(): void {
    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      ['id' => 'inline_block:gallery', 'provider' => 'layout_builder']
    );

    $this->assertFalse($this->detacher->isReusableBlockComponent($component));
    $this->expectException(\InvalidArgumentException::class);
    $this->detacher->detach($component);
  }

}
