<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\quick_node_clone\Entity\QuickNodeCloneEntityFormBuilder;
use Psr\Log\LoggerInterface;

/**
 * Clones a single inline block within a Layout Builder section.
 *
 * Powers the "Clone" contextual operation on Layout Builder blocks (issue
 * #190). The heavy lifting — deep-duplicating the block's paragraphs and any
 * nested content blocks — is delegated to quick_node_clone's form builder,
 * which already owns that logic (and its tracked core-clone patch) for node
 * duplication. This service reuses those public methods for the per-block case
 * so the two clone paths stay in sync.
 *
 * @see \Drupal\quick_node_clone\Entity\QuickNodeCloneEntityFormBuilder::cloneLayoutSection()
 */
class BlockCloner {

  /**
   * Constructs a BlockCloner.
   *
   * @param \Drupal\quick_node_clone\Entity\QuickNodeCloneEntityFormBuilder $cloneFormBuilder
   *   The quick_node_clone form builder providing the deep-clone helpers.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidGenerator
   *   The UUID generator for the new component.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load a block by revision.
   * @param \Psr\Log\LoggerInterface $logger
   *   The ys_layouts logger channel.
   */
  public function __construct(
    protected QuickNodeCloneEntityFormBuilder $cloneFormBuilder,
    protected UuidInterface $uuidGenerator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Clones an inline block and inserts the copy directly after the original.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section containing the component to clone.
   * @param string $uuid
   *   The UUID of the component to clone.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The newly inserted component, or NULL when the target is not an inline
   *   block (reusable and other non-inline blocks are intentionally excluded).
   */
  public function cloneComponent(Section $section, string $uuid): ?SectionComponent {
    $component = $section->getComponent($uuid);

    // Only inline blocks are cloneable. Reusable blocks use the block_content
    // plugin and are excluded by design: they are already shared across
    // placements, so cloning them would create a disconnected copy.
    if (!$component->getPlugin() instanceof InlineBlock) {
      return NULL;
    }

    $component_array = $component->toArray();
    $configuration = $component_array['configuration'];

    $block_content = $this->loadBlockContent($configuration);
    if (!$block_content) {
      // An inline block whose content cannot be resolved is a data anomaly.
      // Refuse to clone rather than insert a component that would silently
      // share the original's block revision instead of a deep copy.
      $this->logger->error('Cannot clone inline block %uuid: its block content could not be loaded.', ['%uuid' => $uuid]);
      return NULL;
    }

    $cloned_block_content = $block_content->createDuplicate();
    // Deep-duplicate paragraph fields and any nested content blocks so the
    // clone owns its own content rather than sharing the original's.
    $this->cloneFormBuilder->cloneParagraphs($cloned_block_content);
    $this->cloneFormBuilder->cloneNestedBlocks($cloned_block_content);

    // Carry the clone as a serialized, not-yet-saved block so it persists as a
    // new revision when the layout is saved.
    $configuration['block_revision_id'] = NULL;
    $configuration['block_serialized'] = serialize($cloned_block_content);

    $new_component = new SectionComponent(
      $this->uuidGenerator->generate(),
      $component_array['region'],
      $configuration,
      $component_array['additional']
    );
    $section->insertAfterComponent($uuid, $new_component);

    return $new_component;
  }

  /**
   * Loads the block content entity backing an inline block component.
   *
   * @param array $configuration
   *   The component's block plugin configuration.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The block content entity, or NULL if it cannot be resolved.
   */
  protected function loadBlockContent(array $configuration) {
    if (!empty($configuration['block_serialized'])) {
      // The serialized block originates from Layout Builder's own tempstore.
      // @codingStandardsIgnoreStart
      return unserialize($configuration['block_serialized']);
      // @codingStandardsIgnoreEnd
    }
    if (!empty($configuration['block_revision_id'])) {
      return $this->entityTypeManager->getStorage('block_content')
        ->loadRevision($configuration['block_revision_id']);
    }
    return NULL;
  }

}
