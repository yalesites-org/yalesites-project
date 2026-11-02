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
    if (!$this->isInlineBlock($component)) {
      return NULL;
    }

    $new_component = $this->duplicateComponent($component);
    if (!$new_component) {
      return NULL;
    }

    // insertAfterComponent() re-weights the component it inserts, so the weight
    // duplicateComponent() carried over from the original is overwritten here.
    $section->insertAfterComponent($uuid, $new_component);

    return $new_component;
  }

  /**
   * Determines whether a component's plugin is an inline (non-reusable) block.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to inspect.
   *
   * @return bool
   *   TRUE when the component is an inline block. FALSE for reusable and other
   *   non-inline placements, and for a component whose plugin cannot be
   *   resolved at all — a missing plugin already renders as a broken block, and
   *   treating it as non-inline keeps one bad component from aborting a clone.
   */
  public function isInlineBlock(SectionComponent $component): bool {
    try {
      return $component->getPlugin() instanceof InlineBlock;
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not resolve the block plugin for component %uuid: @message', [
        '%uuid' => $component->getUuid(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Deep-copies an inline block into a new, detached component.
   *
   * The copy is not attached to any section: callers decide where it goes.
   * Cloning a single block inserts it after the original (::cloneComponent),
   * while cloning a whole section collects the copies into a new section.
   *
   * Callers must establish that the component is an inline block first, with
   * ::isInlineBlock(). Keeping the predicate out of here means the block plugin
   * is resolved once per component rather than twice — SectionComponent's
   * ::getPlugin() is not memoised, so each call builds a fresh plugin instance
   * — and it leaves a NULL return meaning exactly one thing: the block content
   * could not be resolved.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The inline-block component to duplicate.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   A new component with a fresh UUID carrying a deep copy of the original's
   *   block content, or NULL when that block content cannot be resolved.
   */
  public function duplicateComponent(SectionComponent $component): ?SectionComponent {
    $component_array = $component->toArray();
    $configuration = $component_array['configuration'];

    $block_content = $this->loadBlockContent($configuration);
    if (!$block_content) {
      // An inline block whose content cannot be resolved is a data anomaly.
      // Refuse to clone rather than produce a component that would silently
      // share the original's block revision instead of a deep copy.
      $this->logger->error('Cannot clone inline block %uuid: its block content could not be loaded.', ['%uuid' => $component->getUuid()]);
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

    // fromArray() keeps the region, weight and additional data verbatim; only
    // the identity and the block content differ from the original.
    $component_array['uuid'] = $this->uuidGenerator->generate();
    $component_array['configuration'] = $configuration;

    return SectionComponent::fromArray($component_array);
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
