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

    $this->createParagraphReferenceField('block_content', 'gallery', 'field_gallery_items', ['gallery_item']);

    // Accordion mirrors the platform's only nested-paragraph shape:
    // block_content(accordion).field_accordion_items[] ->
    // paragraph(accordion_item).field_content[] -> paragraph(text).field_text.
    // The tabs block has the identical shape (tab -> field_content -> text),
    // so covering accordion covers both. This is the case a shallow one-level
    // clone would get wrong, leaving the inner text paragraph shared between
    // the original block and its copy.
    ParagraphsType::create([
      'id' => 'text',
      'label' => 'Text',
    ])->save();

    ParagraphsType::create([
      'id' => 'accordion_item',
      'label' => 'Accordion Item',
    ])->save();

    BlockContentType::create([
      'id' => 'accordion',
      'label' => 'Accordion',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'paragraph',
      'type' => 'text_long',
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('paragraph', 'field_text'),
      'bundle' => 'text',
      'label' => 'Text',
    ])->save();

    $this->createParagraphReferenceField('paragraph', 'accordion_item', 'field_content', ['text']);
    $this->createParagraphReferenceField('block_content', 'accordion', 'field_accordion_items', ['accordion_item']);

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
   * Creates an entity_reference_revisions field targeting paragraphs.
   *
   * @param string $entity_type
   *   The entity type the field is attached to.
   * @param string $bundle
   *   The bundle the field is attached to.
   * @param string $field_name
   *   The machine name of the field to create.
   * @param string[] $target_bundles
   *   The paragraph bundles the field is allowed to reference.
   */
  protected function createParagraphReferenceField(string $entity_type, string $bundle, string $field_name, array $target_bundles): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();

    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName($entity_type, $field_name),
      'bundle' => $bundle,
      'label' => $field_name,
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => array_combine($target_bundles, $target_bundles),
        ],
      ],
    ])->save();
  }

  /**
   * Wraps a saved block content entity in an inline-block section component.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block_content
   *   The saved block content the component should reference by revision.
   * @param string $label
   *   The component label.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   An inline_block component keyed by block_revision_id.
   */
  protected function createInlineBlockComponent(BlockContent $block_content, string $label): SectionComponent {
    return new SectionComponent(
      $this->container->get('uuid')->generate(),
      'content',
      [
        'id' => 'inline_block:' . $block_content->bundle(),
        'block_revision_id' => $block_content->getRevisionId(),
        'block_serialized' => NULL,
        'label' => $label,
        'label_display' => 'visible',
        'view_mode' => 'full',
        'provider' => 'layout_builder',
        'context_mapping' => [],
      ]
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

    return $this->createInlineBlockComponent($block_content, 'Gallery');
  }

  /**
   * Builds a saved accordion inline-block component with nested paragraphs.
   *
   * @param string $text
   *   The body text stored on the innermost text paragraph.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   An inline_block:accordion component keyed by block_revision_id.
   */
  protected function createAccordionComponent(string $text): SectionComponent {
    $nested = Paragraph::create([
      'type' => 'text',
      'field_text' => $text,
    ]);
    $nested->save();

    $accordion_item = Paragraph::create([
      'type' => 'accordion_item',
      'field_content' => [
        [
          'target_id' => $nested->id(),
          'target_revision_id' => $nested->getRevisionId(),
        ],
      ],
    ]);
    $accordion_item->save();

    $block_content = BlockContent::create([
      'type' => 'accordion',
      'info' => 'Test Accordion Block',
      'field_accordion_items' => [
        [
          'target_id' => $accordion_item->id(),
          'target_revision_id' => $accordion_item->getRevisionId(),
        ],
      ],
    ]);
    $block_content->save();

    return $this->createInlineBlockComponent($block_content, 'Accordion');
  }

  /**
   * Returns the block content a component references by revision.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to resolve.
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The referenced block content revision.
   */
  protected function loadComponentBlockContent(SectionComponent $component): BlockContent {
    return $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->loadRevision($component->toArray()['configuration']['block_revision_id']);
  }

  /**
   * Unserializes the block content a cloned component carries.
   *
   * @param \Drupal\layout_builder\SectionComponent $clone
   *   The component returned by the cloner.
   *
   * @return \Drupal\block_content\Entity\BlockContent
   *   The not-yet-saved cloned block content.
   */
  protected function unserializeClonedBlockContent(SectionComponent $clone): BlockContent {
    // @codingStandardsIgnoreStart
    return unserialize($clone->toArray()['configuration']['block_serialized']);
    // @codingStandardsIgnoreEnd
  }

  /**
   * Walks an accordion block down to its innermost text paragraph.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block_content
   *   An accordion block content entity.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The nested text paragraph two levels down.
   */
  protected function getNestedTextParagraph(BlockContent $block_content): Paragraph {
    $accordion_item = $block_content->get('field_accordion_items')->first()->entity;
    return $accordion_item->get('field_content')->first()->entity;
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

  /**
   * Paragraphs nested inside a cloned block's paragraphs are also duplicated.
   *
   * Regression guard for the shared-paragraph class of bug: if only the
   * top-level paragraphs were duplicated, the accordion item's inner text
   * paragraph would still point at the original's entity, and editing one copy
   * would silently rewrite the other.
   *
   * @covers ::cloneComponent
   */
  public function testNestedParagraphsAreUnsharedInTheClone(): void {
    $original = $this->createAccordionComponent('Original text');
    $section = new Section('layout_onecol', [], [$original]);
    $original_nested = $this->getNestedTextParagraph($this->loadComponentBlockContent($original));

    $clone = $this->blockCloner->cloneComponent($section, $original->getUuid());
    $cloned_block = $this->unserializeClonedBlockContent($clone);

    $cloned_item = $cloned_block->get('field_accordion_items')->first()->entity;
    $this->assertNull(
      $cloned_item->id(),
      'The top-level accordion item in the clone is a new unsaved paragraph.'
    );

    $cloned_nested = $this->getNestedTextParagraph($cloned_block);
    $this->assertNotNull($original_nested->id(), 'The original nested paragraph is saved.');
    $this->assertNull(
      $cloned_nested->id(),
      'The nested text paragraph in the clone is a new unsaved paragraph, not the shared original.'
    );
    $this->assertSame(
      'Original text',
      $cloned_nested->get('field_text')->value,
      'The nested paragraph keeps the original content.'
    );
  }

  /**
   * Saving a cloned block persists its paragraphs as separate entities.
   *
   * Pre-save the copies are unsaved (covered above); this asserts the un-share
   * survives the save, at both nesting levels, which is when the platform's
   * historic shared-paragraph bugs surfaced.
   *
   * @covers ::cloneComponent
   */
  public function testClonedParagraphsPersistAsDistinctEntities(): void {
    $original = $this->createAccordionComponent('Original text');
    $section = new Section('layout_onecol', [], [$original]);
    $original_block = $this->loadComponentBlockContent($original);
    $original_item = $original_block->get('field_accordion_items')->first()->entity;
    $original_nested = $this->getNestedTextParagraph($original_block);

    $clone = $this->blockCloner->cloneComponent($section, $original->getUuid());
    $cloned_block = $this->unserializeClonedBlockContent($clone);
    $cloned_block->save();

    $cloned_item = $cloned_block->get('field_accordion_items')->first()->entity;
    $cloned_nested = $this->getNestedTextParagraph($cloned_block);

    // Assert the copies were actually persisted first: without this, the
    // "different id" assertions below would pass on a NULL id, i.e. precisely
    // when the save silently failed.
    $this->assertNotNull($cloned_block->id(), 'The cloned block was saved.');
    $this->assertNotNull($cloned_item->id(), 'The cloned accordion item was saved.');
    $this->assertNotNull($cloned_nested->id(), 'The cloned nested text paragraph was saved.');
    $this->assertNotNull($cloned_nested->getRevisionId(), 'The cloned nested paragraph has a revision.');

    $this->assertNotEquals(
      $original_block->id(),
      $cloned_block->id(),
      'The saved clone is a distinct block content entity.'
    );
    $this->assertNotEquals(
      $original_item->id(),
      $cloned_item->id(),
      'The saved clone owns a distinct accordion item paragraph.'
    );
    $this->assertNotEquals(
      $original_nested->id(),
      $cloned_nested->id(),
      'The saved clone owns a distinct nested text paragraph.'
    );
    $this->assertNotEquals(
      $original_nested->getRevisionId(),
      $cloned_nested->getRevisionId(),
      'The saved clone owns a distinct nested paragraph revision.'
    );
  }

  /**
   * Editing either copy's paragraphs leaves the other copy untouched.
   *
   * This is the user-visible symptom of shared paragraphs: an editor changes
   * the duplicate and the original changes with it (or vice versa). Asserted in
   * both directions against freshly loaded entities so the check cannot pass on
   * a stale in-memory object.
   *
   * @covers ::cloneComponent
   */
  public function testEditingOneCopyDoesNotAffectTheOther(): void {
    $original = $this->createAccordionComponent('Original text');
    $section = new Section('layout_onecol', [], [$original]);
    $original_block_id = $this->loadComponentBlockContent($original)->id();

    $clone = $this->blockCloner->cloneComponent($section, $original->getUuid());
    $cloned_block = $this->unserializeClonedBlockContent($clone);
    $cloned_block->save();

    $block_storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $paragraph_storage = $this->container->get('entity_type.manager')->getStorage('paragraph');

    // Resolve each copy's innermost paragraph through its own block, so the
    // identities under test are the ones the two blocks actually reference.
    $block_storage->resetCache();
    $paragraph_storage->resetCache();
    $original_text_id = $this->getNestedTextParagraph($block_storage->load($original_block_id))->id();
    $clone_text_id = $this->getNestedTextParagraph($block_storage->load($cloned_block->id()))->id();

    $this->assertNotEquals($original_text_id, $clone_text_id, 'The two blocks reference different text paragraphs.');

    // Edit the clone's paragraph; the original must not move. Each direction
    // asserts the write landed before asserting the other copy is untouched --
    // without that, a write that silently no-ops would pass the test.
    $this->setParagraphText($clone_text_id, 'Edited clone text');
    $this->assertSame('Edited clone text', $this->getParagraphText($clone_text_id), 'The edit persisted on the clone.');
    $this->assertSame('Original text', $this->getParagraphText($original_text_id), 'Editing the clone left the original untouched.');

    // Edit the original's paragraph; the clone must not move.
    $this->setParagraphText($original_text_id, 'Edited original text');
    $this->assertSame('Edited original text', $this->getParagraphText($original_text_id), 'The edit persisted on the original.');
    $this->assertSame('Edited clone text', $this->getParagraphText($clone_text_id), 'Editing the original left the clone untouched.');

    // The blocks still resolve to their own paragraphs after both edits.
    $block_storage->resetCache();
    $paragraph_storage->resetCache();
    $this->assertSame(
      'Edited original text',
      $this->getNestedTextParagraph($block_storage->load($original_block_id))->get('field_text')->value,
      'The original block still renders its own text.'
    );
    $this->assertSame(
      'Edited clone text',
      $this->getNestedTextParagraph($block_storage->load($cloned_block->id()))->get('field_text')->value,
      'The cloned block still renders its own text.'
    );
  }

  /**
   * Writes new text to a stored paragraph, in place on its current revision.
   *
   * @param int|string $paragraph_id
   *   The paragraph to edit.
   * @param string $text
   *   The text to store.
   */
  protected function setParagraphText($paragraph_id, string $text): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $paragraph = $storage->load($paragraph_id);
    $paragraph->set('field_text', $text);
    // Edit the existing revision so the referencing block keeps pointing at it;
    // a new revision would make the read below ambiguous.
    $paragraph->setNewRevision(FALSE);
    $paragraph->save();
    $storage->resetCache();
  }

  /**
   * Reads a stored paragraph's text from a freshly loaded entity.
   *
   * @param int|string $paragraph_id
   *   The paragraph to read.
   *
   * @return string
   *   The stored text.
   */
  protected function getParagraphText($paragraph_id): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $storage->resetCache();
    return $storage->load($paragraph_id)->get('field_text')->value;
  }

}
