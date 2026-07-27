<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\entity_reference_revisions\EntityNeedsSaveInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\SectionComponent;
use Psr\Log\LoggerInterface;

/**
 * Duplicates a single Layout Builder component and its inline block content.
 *
 * Layout Builder stores an unsaved inline block inside the component
 * configuration as `block_serialized`. Cloning a component therefore means
 * building a brand new, still unsaved block_content object and serializing it
 * into a fresh component. The block_content entity itself (and any paragraph
 * it references) is only written to the database when the editor presses
 * "Save layout", which is what InlineBlockEntityOperations::handlePreSave()
 * does for every component whose configuration carries `block_serialized`.
 *
 * Three details make this harder than calling createDuplicate():
 *
 * 1. createDuplicate() does not duplicate referenced paragraphs, so the copy
 *    would keep pointing at the original's paragraph revisions and editing one
 *    block would silently change the other. Every
 *    `entity_reference_revisions` item is therefore duplicated recursively.
 * 2. A duplicated paragraph inherits parent_type/parent_id, so it claims the
 *    ORIGINAL inline block as its parent — the wrong owner, and one whose
 *    access it also inherits, because
 *    ParagraphAccessControlHandler::checkAccess() ANDs in the parent entity's
 *    access. The pointers are therefore cleared here and rebuilt by
 *    EntityReferenceRevisionsItem::postSave() when the layout is saved.
 *    (This is ownership hygiene, not the fix for the "media missing from the
 *    Gallery preview" bug: that one is in atomic's
 *    templates/paragraphs/_gallery-item.twig, which rendered the modal by
 *    paragraph ID and so rendered nothing for an unsaved copy.)
 * 3. The duplicate has to survive the layout tempstore, which re-serializes
 *    the component configuration on every AJAX step.
 *    ContentEntityBase::__sleep() reduces each field to getValue(), and the
 *    in-memory `entity` property is only re-attached by
 *    EntityReferenceItem::getValue() while hasNewEntity() holds (target_id
 *    NULL plus an unsaved entity) or by
 *    EntityReferenceRevisionsItem::getValue() when the paragraph needsSave().
 *    Both target properties are therefore nulled and setNeedsSave(TRUE) is
 *    applied — the same flag the paragraphs widgets set for newly added
 *    items, and the one that makes ERR save the paragraph with its host.
 *
 * Plain `entity_reference` fields (media, taxonomy, …) are intentionally left
 * alone: they point at independently saved entities that serialize losslessly,
 * and duplicating the referenced media would be wrong.
 */
class BlockCloner {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new BlockCloner object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator, used for the cloned component UUID.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    UuidInterface $uuid,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->uuid = $uuid;
    $this->logger = $logger;
  }

  /**
   * Determines whether a component may be cloned.
   *
   * Only inline blocks are clonable. Reusable content blocks are shared by
   * design, so copying one would create a redundant, disconnected duplicate.
   * Everything else (views blocks, plugin blocks, system blocks) has no
   * per-instance content to copy.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to inspect.
   *
   * @return bool
   *   TRUE if the component wraps an inline block, FALSE otherwise.
   */
  public function isClonable(SectionComponent $component): bool {
    // A component with no plugin id, or with a missing or broken plugin, is
    // never clonable.
    try {
      // Cheap check first: the inline block deriver ids are prefixed with
      // "inline_block:", while reusable blocks use "block_content:<uuid>".
      $plugin_id = (string) $component->getPluginId();
      if (!str_starts_with($plugin_id, 'inline_block:')) {
        return FALSE;
      }

      // Authoritative check.
      return $component->getPlugin() instanceof InlineBlock;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Clones a component, including a deep copy of its inline block content.
   *
   * The returned component keeps every piece of the original's configuration
   * (label, view mode, styling, context mapping) plus its region and any
   * additional/third-party settings. Only the component UUID and the block
   * content identifiers differ.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component being cloned.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The new, not yet inserted component.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the component is not an inline block, or when its block
   *   content cannot be loaded. Cloning without the block content would leave
   *   both components sharing a single block_content revision.
   */
  public function cloneComponent(SectionComponent $component): SectionComponent {
    if (!$this->isClonable($component)) {
      throw new \InvalidArgumentException(
        sprintf(
          'Component "%s" is not an inline block and cannot be cloned.',
          $component->getUuid()
        )
      );
    }

    $component_array = $component->toArray();
    $configuration = $component_array['configuration'];

    $block = $this->loadBlockContent($configuration);
    if (!$block) {
      throw new \InvalidArgumentException(
        sprintf(
          'The inline block content for component "%s" could not be loaded.',
          $component->getUuid()
        )
      );
    }

    $duplicate = $this->duplicateBlockContent($block);

    // The clone owns a brand new block_content: drop every pointer to the
    // original's entity and revision so Layout Builder creates (and tracks
    // usage for) a separate entity when the layout is saved. Serializing
    // happens last, once the deep copy is complete.
    $configuration['block_id'] = NULL;
    $configuration['block_revision_id'] = NULL;
    $configuration['block_serialized'] = serialize($duplicate);

    return new SectionComponent(
      $this->uuid->generate(),
      $component->getRegion(),
      $configuration,
      $component_array['additional']
    );
  }

  /**
   * Creates a deep, unsaved duplicate of an inline block content entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block content entity to duplicate.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The duplicate, with every referenced paragraph replaced by its own
   *   unsaved duplicate.
   */
  public function duplicateBlockContent(
    FieldableEntityInterface $block,
  ): FieldableEntityInterface {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $duplicate */
    $duplicate = $block->createDuplicate();
    $this->duplicateReferences($duplicate);
    $this->warnIfLossy($duplicate);

    return $duplicate;
  }

  /**
   * Loads the block content referenced by a component configuration.
   *
   * Mirrors InlineBlock::getEntity(): an unsaved edit lives in
   * `block_serialized` and takes precedence over the saved revision.
   *
   * @param array $configuration
   *   The component configuration.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The block content entity, or NULL if it cannot be found.
   */
  protected function loadBlockContent(
    array $configuration,
  ): ?FieldableEntityInterface {
    if (!empty($configuration['block_serialized'])) {
      // The payload is Layout Builder's own tempstore data for a layout the
      // current user is already permitted to edit, exactly as core's
      // InlineBlock::getEntity() reads it.
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
      $block = unserialize($configuration['block_serialized']);
      return $block instanceof FieldableEntityInterface ? $block : NULL;
    }

    if (!empty($configuration['block_revision_id'])) {
      $block = $this->entityTypeManager->getStorage('block_content')
        ->loadRevision($configuration['block_revision_id']);
      return $block instanceof FieldableEntityInterface ? $block : NULL;
    }

    return NULL;
  }

  /**
   * Recursively replaces revisioned references with unsaved duplicates.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose reference fields are rewritten in place.
   */
  protected function duplicateReferences(
    FieldableEntityInterface $entity,
  ): void {
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      $type = $definition->getFieldStorageDefinition()->getType();

      // Paragraph (and any other revisioned composite) references. Every
      // YaleSites block bundle stores its repeatable content this way.
      if ($type === 'entity_reference_revisions') {
        $this->duplicateFieldItems($entity, $field_name);
      }
    }
  }

  /**
   * Duplicates every referenced entity of a single field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity holding the field.
   * @param string $field_name
   *   The name of the field to process.
   */
  protected function duplicateFieldItems(
    FieldableEntityInterface $entity,
    string $field_name,
  ): void {
    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return;
    }

    foreach ($field as $item) {
      $referenced = $item->entity;
      if (!$referenced instanceof FieldableEntityInterface) {
        continue;
      }

      $duplicate = $referenced->createDuplicate();
      $this->resetParentPointers($duplicate, $field_name);

      // Recurse before flagging so grandchildren are duplicated too.
      $this->duplicateReferences($duplicate);

      // Keeps the unsaved duplicate attached to the field across the layout
      // tempstore's serialize/unserialize cycles, and tells ERR to save it
      // together with its host. See the class docblock.
      if ($duplicate instanceof EntityNeedsSaveInterface) {
        $duplicate->setNeedsSave(TRUE);
      }

      $item->entity = $duplicate;

      // Defensive: assigning `entity` already nulls the target properties,
      // but a stale target_revision_id would make
      // EntityReferenceRevisionsFieldItemList::referencedEntities() resolve
      // the ORIGINAL paragraph and quietly render the wrong content.
      $item->target_id = NULL;
      $item->target_revision_id = NULL;
    }
  }

  /**
   * Clears a duplicate's pointer back to the entity it was copied from.
   *
   * Duplicating an entity copies parent_type/parent_id, so a duplicated
   * paragraph would claim the ORIGINAL block content as its parent. Besides
   * being the wrong owner, that also makes the copy's view access ride on the
   * original block, because ParagraphAccessControlHandler::checkAccess() ANDs
   * in the parent entity's access. Clearing the pointers keeps the copy
   * self-contained until EntityReferenceRevisionsItem::postSave() rewrites all
   * three properties when the layout is saved.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $duplicate
   *   The duplicated entity.
   * @param string $field_name
   *   The name of the field the duplicate is referenced from.
   */
  protected function resetParentPointers(
    FieldableEntityInterface $duplicate,
    string $field_name,
  ): void {
    if ($duplicate->hasField('parent_type')) {
      $duplicate->set('parent_type', NULL);
    }
    if ($duplicate->hasField('parent_id')) {
      $duplicate->set('parent_id', NULL);
    }
    if ($duplicate->hasField('parent_field_name')) {
      $duplicate->set('parent_field_name', $field_name);
    }
  }

  /**
   * Logs a warning if a duplicate loses references when serialized.
   *
   * Cheap guard against a future field type re-introducing the silent data
   * loss described in the class docblock: the layout tempstore serializes the
   * component configuration on every AJAX step, so anything that does not
   * survive a round trip never reaches the preview.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $duplicate
   *   The duplicate about to be serialized into the component configuration.
   */
  protected function warnIfLossy(FieldableEntityInterface $duplicate): void {
    // The value comes from the object built moments ago in this request.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
    $round_tripped = unserialize(serialize($duplicate));
    if (!$round_tripped instanceof FieldableEntityInterface) {
      $this->logger->warning(
        'A cloned block of type %bundle did not survive serialization.',
        ['%bundle' => $duplicate->bundle()]
      );
      return;
    }

    foreach ($duplicate->getFieldDefinitions() as $field_name => $definition) {
      $type = $definition->getFieldStorageDefinition()->getType();
      if ($type !== 'entity_reference_revisions') {
        continue;
      }
      if ($duplicate->get($field_name)->isEmpty()) {
        continue;
      }
      if ($round_tripped->get($field_name)->isEmpty()) {
        $this->logger->warning(
          'Cloned block field %field lost its references when serialized.',
          ['%field' => $field_name]
        );
      }
    }
  }

}
