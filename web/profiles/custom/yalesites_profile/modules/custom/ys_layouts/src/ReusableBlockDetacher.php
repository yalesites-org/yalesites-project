<?php

declare(strict_types=1);

namespace Drupal\ys_layouts;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Detaches a placed reusable block, converting it into an inline block.
 *
 * Instance-level detach: the placed component stops referencing the shared
 * reusable block_content entity and instead carries its own independent,
 * non-reusable copy. The original reusable block is left untouched and keeps
 * working on every other page where it is placed.
 */
class ReusableBlockDetacher {

  /**
   * Plugin id prefix identifying a placed reusable content block.
   */
  protected const REUSABLE_PLUGIN_PREFIX = 'block_content:';

  public function __construct(
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Determines whether a component is a placed reusable content block.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component to inspect.
   *
   * @return bool
   *   TRUE if the component places a reusable block that can be detached.
   */
  public function isReusableBlockComponent(SectionComponent $component): bool {
    return str_starts_with($component->getPluginId(), self::REUSABLE_PLUGIN_PREFIX);
  }

  /**
   * Converts a placed reusable block component into an inline block, in place.
   *
   * The component keeps its position (uuid, region, weight) and its visible
   * label; only the underlying block reference changes from the shared reusable
   * block to an independent, non-reusable copy serialized into the component.
   * Layout Builder persists that copy as a new block_content entity when the
   * layout is saved.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The placed reusable block component to detach.
   *
   * @throws \InvalidArgumentException
   *   If the component is not a placed reusable content block, or its
   *   referenced block_content entity cannot be loaded.
   */
  public function detach(SectionComponent $component): void {
    if (!$this->isReusableBlockComponent($component)) {
      throw new \InvalidArgumentException('Only a placed reusable content block can be detached.');
    }

    $uuid = substr($component->getPluginId(), strlen(self::REUSABLE_PLUGIN_PREFIX));
    $block = $this->entityRepository->loadEntityByUuid('block_content', $uuid);
    if ($block === NULL) {
      throw new \InvalidArgumentException(sprintf('The reusable block "%s" could not be loaded.', $uuid));
    }

    // An independent, non-reusable copy. Composed children (paragraphs) are
    // deep-cloned so the inline copy shares no owned entities with the
    // original; plain references (media, nodes) are shared library assets and
    // are intentionally left pointing at the same targets.
    $copy = $block->createDuplicate();
    $copy->set('reusable', FALSE);
    $this->duplicateComposedChildren($copy);

    // Keep the placement's existing configuration (label, view mode, context
    // mapping, and any other keys it carries) and change only what turns the
    // shared reference into an owned inline copy.
    $configuration = $component->get('configuration');
    $configuration['id'] = 'inline_block:' . $block->bundle();
    $configuration['provider'] = 'layout_builder';
    $configuration['block_revision_id'] = NULL;
    $configuration['block_serialized'] = serialize($copy);
    $component->setConfiguration($configuration);
  }

  /**
   * Recursively replaces composed (paragraph) children with fresh duplicates.
   *
   * A shallow createDuplicate() copies entity_reference_revisions field values
   * as-is, leaving the copy pointing at the SAME paragraph revisions as the
   * original. Replacing each referenced paragraph with its own duplicate - and
   * recursing so nested paragraphs are duplicated too - makes the detached copy
   * fully independent. The duplicates are unsaved (no id) so they become new
   * entities when the inline block is saved.
   *
   * Deliberately not reusing quick_node_clone's cloneParagraphs(): that method
   * honors node-clone "exclude field" config (which would silently drop block
   * fields), only clones one level deep, and is bound to a contrib form-builder
   * service. This full-recursion, type-based clone keeps the copy faithful.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose composed children should be duplicated.
   */
  protected function duplicateComposedChildren(FieldableEntityInterface $entity): void {
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }
      foreach ($field as $item) {
        if ($item->entity instanceof FieldableEntityInterface) {
          $child_copy = $item->entity->createDuplicate();
          $this->duplicateComposedChildren($child_copy);
          $item->entity = $child_copy;
        }
      }
    }
  }

}
