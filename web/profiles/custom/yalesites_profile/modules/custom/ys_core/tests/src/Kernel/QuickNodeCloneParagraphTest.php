<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Tests that cloneLayoutSection() duplicates paragraphs inside inline blocks.
 *
 * This is a regression test for the bug where cloning a node via
 * quick_node_clone fails to duplicate paragraph entities that live inside
 * inline Layout Builder blocks. Before the fix, both the original and cloned
 * node share the same paragraph entities, causing gallery modals to show
 * images from the wrong page.
 *
 * @group ys_core
 */
class QuickNodeCloneParagraphTest extends KernelTestBase {

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
    'ys_core',
  ];

  /**
   * The QuickNodeClone entity form builder service.
   *
   * @var \Drupal\quick_node_clone\Entity\QuickNodeCloneEntityFormBuilder
   */
  protected $cloneFormBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('system', ['sequences']);

    // Create a gallery_item paragraph type.
    $paragraph_type = ParagraphsType::create([
      'id' => 'gallery_item',
      'label' => 'Gallery Item',
    ]);
    $paragraph_type->save();

    // Create a gallery block_content type.
    $block_content_type = BlockContentType::create([
      'id' => 'gallery',
      'label' => 'Gallery',
    ]);
    $block_content_type->save();

    // Create an entity_reference_revisions field storage on block_content
    // targeting paragraphs.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_gallery_items',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => [
        'target_type' => 'paragraph',
      ],
    ]);
    $field_storage->save();

    // Create the field instance on the gallery bundle.
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'gallery',
      'label' => 'Gallery Items',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => ['gallery_item' => 'gallery_item'],
        ],
      ],
    ]);
    $field->save();

    $this->cloneFormBuilder = $this->container->get('quick_node_clone.entity.form_builder');
  }

  /**
   * Tests that paragraphs inside an inline block are cloned, not reused.
   *
   * The cloneLayoutSection() method must call cloneParagraphs() on the inline
   * block content entity. Without the fix, the cloned block still holds a
   * reference to the original paragraph entity (same ID), causing the two
   * nodes to share paragraph data.
   */
  public function testCloneLayoutSectionDuplicatesParagraphs() {
    // Create and save a paragraph so it has a real ID.
    $paragraph = Paragraph::create([
      'type' => 'gallery_item',
    ]);
    $paragraph->save();
    $original_paragraph_id = $paragraph->id();
    $this->assertNotNull($original_paragraph_id, 'The original paragraph was saved and has an ID.');

    // Create and save a block_content entity that references the paragraph.
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
    $this->assertNotNull($block_content->getRevisionId(), 'Block content was saved and has a revision ID.');

    // Build a Layout Builder Section containing the block as an inline block
    // component, referencing it by block_revision_id.
    $component_uuid = $this->container->get('uuid')->generate();
    $component = new SectionComponent(
      $component_uuid,
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

    $section = new Section('layout_onecol', [], [$component]);

    // Call the method under test.
    $cloned_section = $this->cloneFormBuilder->cloneLayoutSection($section);

    // Extract the cloned component from the returned section.
    $cloned_components = array_values($cloned_section->getComponents());

    $cloned_component_array = $cloned_components[0]->toArray();
    $cloned_configuration = $cloned_component_array['configuration'];

    $this->assertNotEmpty($cloned_configuration['block_serialized'], 'The cloned block was serialized into block_serialized configuration.');

    // Deserialize the cloned block and inspect its paragraph field. The data
    // originates from our own controlled test code so all classes are safe.
    // @codingStandardsIgnoreStart
    $cloned_block_content = unserialize($cloned_configuration['block_serialized']);
    // @codingStandardsIgnoreEnd

    $gallery_items = $cloned_block_content->get('field_gallery_items');
    $this->assertFalse($gallery_items->isEmpty(), 'The cloned block still has gallery items.');

    $cloned_paragraph = $gallery_items->first()->entity;
    $this->assertNotNull($cloned_paragraph, 'The cloned block has a paragraph entity in field_gallery_items.');

    // The critical assertion: the cloned paragraph must be a NEW (unsaved)
    // entity with no ID. Before the fix, cloneLayoutSection() does not call
    // cloneParagraphs() on the cloned block, so the paragraph retains its
    // original ID. After the fix, createDuplicate() will have been called and
    // the paragraph will be an unsaved entity (id() === NULL).
    $this->assertNull(
      $cloned_paragraph->id(),
      sprintf(
        'The paragraph in the cloned block must be a new unsaved entity (id === NULL). ' .
        'Got id=%s, which means the original paragraph was reused instead of cloned.',
        var_export($cloned_paragraph->id(), TRUE)
      )
    );
  }

}
