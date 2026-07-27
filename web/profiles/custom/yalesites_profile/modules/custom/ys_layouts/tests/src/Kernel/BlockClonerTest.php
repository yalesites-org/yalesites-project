<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\MediaInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use Drupal\ys_layouts\Service\BlockCloner;

/**
 * Tests cloning a Layout Builder component and its inline block content.
 *
 * Covers the things that break when a block is cloned naively: the cloned
 * block sharing paragraph entities with the original, the duplicated paragraph
 * (and therefore its media reference) not surviving the layout tempstore's
 * serialize cycles, the copy still claiming the original block as its parent,
 * and reusable blocks being offered a Clone operation they must not have.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Service\BlockCloner
 *
 * @group ys_layouts
 * @group yalesites
 */
class BlockClonerTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'text',
    'media',
    'block',
    'block_content',
    'contextual',
    'entity_reference_revisions',
    'paragraphs',
    'layout_discovery',
    'layout_builder',
  ];

  /**
   * The cloner under test.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner
   */
  protected BlockCloner $cloner;

  /**
   * The media entity referenced by the gallery item paragraph.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('block_content');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['system', 'field', 'file', 'media']);

    $this->createMediaType('image', ['id' => 'image']);

    ParagraphsType::create([
      'id' => 'gallery_item',
      'label' => 'Gallery Item',
    ])->save();

    BlockContentType::create([
      'id' => 'gallery',
      'label' => 'Gallery',
    ])->save();

    // The gallery block holds repeatable gallery items.
    $this->createField(
      'block_content',
      'gallery',
      'field_gallery_items',
      'entity_reference_revisions',
      'paragraph'
    );
    // Each gallery item points at an image media entity.
    $this->createField(
      'paragraph',
      'gallery_item',
      'field_media',
      'entity_reference',
      'media'
    );

    $file = File::create([
      'uri' => 'public://kroon-hall.jpg',
      'filename' => 'kroon-hall.jpg',
    ]);
    $file->save();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'image',
        'name' => 'kroon-hall.jpg',
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => 'Kroon Hall in autumn',
        ],
      ]);
    $media->save();
    $this->media = $media;

    $this->cloner = new BlockCloner(
      $this->container->get('entity_type.manager'),
      $this->container->get('uuid'),
      $this->container->get('logger.factory')->get('ys_layouts')
    );
  }

  /**
   * Creates a reference field on a bundle.
   *
   * @param string $entity_type
   *   The entity type to attach the field to.
   * @param string $bundle
   *   The bundle to attach the field to.
   * @param string $field_name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param string $target_type
   *   The referenced entity type.
   */
  protected function createField(
    string $entity_type,
    string $bundle,
    string $field_name,
    string $type,
    string $target_type,
  ): void {
    $storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'cardinality' => -1,
      'settings' => ['target_type' => $target_type],
    ]);
    $storage->save();

    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $field_name,
    ])->save();
  }

  /**
   * Creates a saved gallery block referencing a saved gallery item paragraph.
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The saved block content entity.
   */
  protected function createGalleryBlock(): BlockContent {
    $paragraph = Paragraph::create([
      'type' => 'gallery_item',
      'field_media' => ['target_id' => $this->media->id()],
    ]);
    $paragraph->save();

    $block = BlockContent::create([
      'type' => 'gallery',
      'info' => 'Campus gallery',
      // Inline Layout Builder blocks are never reusable.
      'reusable' => FALSE,
      'field_gallery_items' => [
        [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ],
      ],
    ]);
    $block->save();

    return $block;
  }

  /**
   * Builds an inline block component for a saved block content entity.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block
   *   The block content the component renders.
   * @param string $region
   *   The region the component lives in.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The component.
   */
  protected function createInlineComponent(
    BlockContent $block,
    string $region = 'content',
  ): SectionComponent {
    return new SectionComponent(
      $this->container->get('uuid')->generate(),
      $region,
      [
        'id' => 'inline_block:gallery',
        'block_revision_id' => $block->getRevisionId(),
        'block_serialized' => NULL,
        'label' => 'Campus gallery',
        'label_display' => 'visible',
        'view_mode' => 'full',
        'provider' => 'layout_builder',
        'context_mapping' => [],
      ],
      ['third_party_settings' => ['ys_layouts' => ['padding' => 'none']]]
    );
  }

  /**
   * Unserializes the block content held by a component configuration.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to read.
   * @param int $passes
   *   How many additional serialize/unserialize round trips to apply. The
   *   layout tempstore re-serializes the configuration on every AJAX step.
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The block content object.
   */
  protected function readSerializedBlock(
    SectionComponent $component,
    int $passes = 0,
  ): BlockContent {
    $configuration = $component->get('configuration');
    $this->assertNotEmpty($configuration['block_serialized']);

    // Test-owned payload built by the code under test.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
    $block = unserialize($configuration['block_serialized']);
    for ($i = 0; $i < $passes; $i++) {
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
      $block = unserialize(serialize($block));
    }

    $this->assertInstanceOf(BlockContent::class, $block);
    return $block;
  }

  /**
   * The clone keeps every piece of the original's configuration.
   *
   * @covers ::cloneComponent
   */
  public function testCloneKeepsComponentConfiguration(): void {
    $component = $this->createInlineComponent($this->createGalleryBlock());
    $clone = $this->cloner->cloneComponent($component);

    $original = $component->get('configuration');
    $cloned = $clone->get('configuration');

    // Everything except the identity of the block content is preserved.
    foreach (['id', 'label', 'label_display', 'view_mode', 'provider'] as $key) {
      $this->assertSame($original[$key], $cloned[$key], $key);
    }
    $this->assertSame(
      $original['context_mapping'],
      $cloned['context_mapping']
    );

    // Region, third party settings and a fresh UUID.
    $this->assertSame($component->getRegion(), $clone->getRegion());
    $this->assertSame(
      $component->toArray()['additional'],
      $clone->toArray()['additional']
    );
    $this->assertNotSame($component->getUuid(), $clone->getUuid());
    $this->assertNotEmpty($clone->getUuid());

    // The clone must not point at the original's block content, or Layout
    // Builder would reuse (and later garbage collect) a shared revision.
    $this->assertNull($cloned['block_revision_id']);
    $this->assertNull($cloned['block_id']);
    $this->assertNotEmpty($cloned['block_serialized']);
  }

  /**
   * The clone is inserted directly after the original component.
   *
   * @covers ::cloneComponent
   */
  public function testCloneLandsDirectlyAfterTheOriginal(): void {
    $first = $this->createInlineComponent($this->createGalleryBlock());
    $second = $this->createInlineComponent($this->createGalleryBlock());
    $third = $this->createInlineComponent($this->createGalleryBlock());
    $first->setWeight(0);
    $second->setWeight(1);
    $third->setWeight(2);

    $section = new Section(
      'layout_onecol',
      [],
      [$first, $second, $third]
    );

    $clone = $this->cloner->cloneComponent($first);
    $section->insertAfterComponent($first->getUuid(), $clone);

    $order = array_keys($section->getComponentsByRegion('content'));
    $this->assertSame(
      [
        $first->getUuid(),
        $clone->getUuid(),
        $second->getUuid(),
        $third->getUuid(),
      ],
      $order
    );
  }

  /**
   * The cloned paragraph is a new object and survives the tempstore.
   *
   * This is the media-in-preview regression: without setNeedsSave(TRUE) the
   * duplicated paragraph is dropped by serialize(), the cloned block renders an
   * empty gallery in the Layout Builder preview, and the media never appears.
   *
   * @covers ::cloneComponent
   * @covers ::duplicateBlockContent
   */
  public function testClonedParagraphSurvivesSerializationWithMedia(): void {
    $block = $this->createGalleryBlock();
    $original_paragraph = $block->get('field_gallery_items')->first()->entity;

    $clone = $this->cloner->cloneComponent(
      $this->createInlineComponent($block)
    );

    // Two round trips: the tempstore re-serializes on every AJAX step.
    foreach ([0, 1] as $passes) {
      $cloned_block = $this->readSerializedBlock($clone, $passes);
      $items = $cloned_block->get('field_gallery_items');
      $this->assertFalse(
        $items->isEmpty(),
        'The cloned gallery kept its items after serialization.'
      );

      // The in-memory duplicate must win: a leftover target revision would
      // silently resolve back to the original paragraph.
      $this->assertNull($items->first()->target_id);
      $this->assertNull($items->first()->target_revision_id);

      $paragraph = $items->first()->entity;
      $this->assertInstanceOf(Paragraph::class, $paragraph);
      $this->assertNull($paragraph->id());
      $this->assertTrue($paragraph->needsSave());
      $this->assertNotSame($original_paragraph->uuid(), $paragraph->uuid());

      // The media reference is what the preview renders.
      $this->assertSame(
        $this->media->id(),
        $paragraph->get('field_media')->first()->target_id
      );

      // The stale pointer at the original block is cleared so the preview is
      // not blanked by a parent access check.
      $this->assertTrue($paragraph->get('parent_type')->isEmpty());
      $this->assertTrue($paragraph->get('parent_id')->isEmpty());
      $this->assertSame(
        'field_gallery_items',
        $paragraph->get('parent_field_name')->value
      );
    }

    // The original is untouched.
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->loadUnchanged($block->id());
    $this->assertSame(
      $original_paragraph->id(),
      $reloaded->get('field_gallery_items')->first()->target_id
    );
  }

  /**
   * The copied paragraph does not claim the original block as its parent.
   *
   * A plain createDuplicate() inherits parent_type/parent_id, so the copy
   * claims the ORIGINAL inline block as its owner, and
   * ParagraphAccessControlHandler::checkAccess() then ANDs in that block's
   * access instead of the copy's own host. The pointers are cleared until the
   * layout is saved, at which point ERR rewrites them (see
   * testSavingTheCloneCreatesItsOwnParagraph()).
   *
   * Note this is ownership hygiene, not the "media missing from the Gallery
   * preview" bug: in production the original block has an inline_block_usage
   * row, so its access resolves to the host the editor is already viewing and
   * even a naive copy would be viewable. That preview bug lives in atomic's
   * templates/paragraphs/_gallery-item.twig, which rendered the modal by
   * paragraph ID and therefore rendered nothing for an unsaved copy.
   *
   * @covers ::duplicateBlockContent
   */
  public function testClonedParagraphDropsTheOriginalsParentPointer(): void {
    $this->installConfig(['paragraphs']);
    $account = User::create(['name' => 'editor', 'status' => 1]);
    $account->save();

    $block = $this->createGalleryBlock();
    $original = $block->get('field_gallery_items')->first()->entity;

    // What a naive clone produces: the original block as its parent.
    $naive = $original->createDuplicate();
    $this->assertSame('block_content', $naive->get('parent_type')->value);
    $this->assertSame(
      (string) $block->id(),
      (string) $naive->get('parent_id')->value
    );

    // What this service produces: no parent until the layout is saved, so the
    // copy's access no longer depends on the block it was copied from.
    $cloned_block = $this->cloner->duplicateBlockContent($block);
    $cloned = $cloned_block->get('field_gallery_items')->first()->entity;
    $this->assertNull($cloned->get('parent_type')->value);
    $this->assertNull($cloned->get('parent_id')->value);
    $this->assertSame(
      'field_gallery_items',
      $cloned->get('parent_field_name')->value
    );
    $this->assertNull($cloned->getParentEntity());
    $this->assertTrue($cloned->access('view', $account));
  }

  /**
   * Saving the clone creates its own paragraph, leaving the original alone.
   *
   * @covers ::duplicateBlockContent
   */
  public function testSavingTheCloneCreatesItsOwnParagraph(): void {
    $block = $this->createGalleryBlock();
    $original_paragraph = $block->get('field_gallery_items')->first()->entity;

    $clone = $this->cloner->cloneComponent(
      $this->createInlineComponent($block)
    );
    $cloned_block = $this->readSerializedBlock($clone);
    $cloned_block->save();

    $paragraph = $cloned_block->get('field_gallery_items')->first()->entity;
    $this->assertNotNull($paragraph->id());
    $this->assertNotSame($original_paragraph->id(), $paragraph->id());
    $this->assertSame(
      'block_content',
      $paragraph->get('parent_type')->value
    );
    $this->assertSame(
      (string) $cloned_block->id(),
      (string) $paragraph->get('parent_id')->value
    );
    $this->assertSame(
      $this->media->id(),
      $paragraph->get('field_media')->first()->target_id
    );

    // The original block still owns its own paragraph.
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->loadUnchanged($block->id());
    $this->assertSame(
      $original_paragraph->id(),
      $reloaded->get('field_gallery_items')->first()->target_id
    );
  }

  /**
   * Reusable content blocks are refused.
   *
   * @covers ::isClonable
   * @covers ::cloneComponent
   */
  public function testReusableBlockIsNotClonable(): void {
    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      [
        'id' => 'block_content:0e0f0a1a-0000-4000-8000-000000000000',
        'label' => 'Shared call to action',
        'provider' => 'block_content',
      ]
    );

    $this->assertFalse($this->cloner->isClonable($component));
    $this->expectException(\InvalidArgumentException::class);
    $this->cloner->cloneComponent($component);
  }

  /**
   * Plugin blocks that are not inline blocks are refused.
   *
   * @covers ::isClonable
   */
  public function testNonInlineBlocksAreNotClonable(): void {
    foreach (['views_block:events-block_1', 'page_meta_block', ''] as $id) {
      $component = new SectionComponent(
        $this->container->get('uuid')->generate(),
        'content',
        ['id' => $id]
      );
      $this->assertFalse(
        $this->cloner->isClonable($component),
        sprintf('Plugin "%s" is not clonable.', $id)
      );
    }
  }

  /**
   * An inline block whose content cannot be loaded is refused.
   *
   * Cloning it anyway would leave both components sharing one revision.
   *
   * @covers ::cloneComponent
   */
  public function testMissingBlockContentIsRefused(): void {
    $component = new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      [
        'id' => 'inline_block:gallery',
        'block_revision_id' => NULL,
        'block_serialized' => NULL,
      ]
    );

    $this->assertTrue($this->cloner->isClonable($component));
    $this->expectException(\InvalidArgumentException::class);
    $this->cloner->cloneComponent($component);
  }

}
