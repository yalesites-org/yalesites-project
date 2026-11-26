<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Duplicates a whole Layout Builder section, blocks included.
 *
 * Powers the "Clone" action on the Layout Builder section toolbar (issue
 * #1638). Per-block duplication is delegated to BlockCloner so section cloning
 * and the existing single-block clone stay in sync, including its deep copy of
 * paragraphs and nested content blocks.
 *
 * ::cloneSection() is the whole operation the toolbar action needs; the copy is
 * placed directly below the original. ::duplicateSection() is the pure half of
 * it, exposed separately because the copying rules are what carry the risk and
 * are worth asserting on their own.
 *
 * Why not quick_node_clone's cloneLayoutSection(): it regenerates component
 * UUIDs for inline blocks only, leaving non-inline components on the original's
 * UUID. That is correct when the whole node is being copied, but here the copy
 * lands in the *same* layout, where a reused component UUID collides with the
 * original once the layout is saved.
 *
 * @see \Drupal\ys_layouts\Controller\CloneSectionController
 * @see \Drupal\ys_layouts\Service\BlockCloner
 */
class SectionCloner {

  /**
   * Constructs a SectionCloner.
   *
   * @param \Drupal\ys_layouts\Service\BlockCloner $blockCloner
   *   The block cloner, which owns per-block duplication.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidGenerator
   *   The UUID generator, used to give re-referenced blocks a fresh identity.
   */
  public function __construct(
    protected BlockCloner $blockCloner,
    protected UuidInterface $uuidGenerator,
  ) {}

  /**
   * Clones the section at a delta and inserts the copy directly below it.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage to clone within. It is modified in place; persisting
   *   it (normally to the layout tempstore) is the caller's job.
   * @param int $delta
   *   The delta of the section to clone.
   *
   * @return \Drupal\layout_builder\Section
   *   The newly inserted copy.
   */
  public function cloneSection(SectionStorageInterface $section_storage, int $delta): Section {
    $clone = $this->duplicateSection($section_storage->getSection($delta));

    // Delta + 1 puts the copy directly below the section it came from.
    $section_storage->insertSection($delta + 1, $clone);

    return $clone;
  }

  /**
   * Builds a copy of a section, including every block inside it.
   *
   * The copy keeps the original's layout plugin, layout settings and
   * third-party settings — the section colour / component-theme dial is stored
   * as a third-party setting, so dropping those would silently reset the copy's
   * appearance.
   *
   * layout_builder_lock's settings are the one exception and are deliberately
   * left off the copy. They are access control rather than appearance, and
   * inheriting them would strand the editor: layout_builder_lock removes a
   * section's "Remove" link whenever it carries any lock setting and the user
   * lacks `remove sections with lock settings` — a permission no role on this
   * platform holds — so a copy inheriting its source's locks could not be
   * deleted by the person who had just created it. Dropping them makes the copy
   * an ordinary editor-owned section, which is how layout_builder_lock already
   * treats what an editor adds in an override: its preRender() skips lock
   * enforcement for blocks that are not part of the default layout.
   *
   * Only sections whose *contents* are unlocked can be cloned at all, so what
   * this drops in practice is the positional pair CloneSectionAccessCheck
   * permits.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to copy. It is not modified.
   *
   * @return \Drupal\layout_builder\Section
   *   A new section carrying copies of the original's components.
   *
   * @see \Drupal\ys_layouts\Access\CloneSectionAccessCheck
   * @see \Drupal\layout_builder_lock\LayoutBuilderLock::preRender()
   */
  public function duplicateSection(Section $section): Section {
    $components = [];
    foreach ($section->getComponents() as $component) {
      $copy = $this->duplicateComponent($component);
      if ($copy) {
        $components[] = $copy;
      }
    }

    $third_party_settings = [];
    foreach ($section->getThirdPartyProviders() as $provider) {
      // Inheriting these would leave the editor a section they cannot remove.
      if ($provider === 'layout_builder_lock') {
        continue;
      }
      $third_party_settings[$provider] = $section->getThirdPartySettings($provider);
    }

    return new Section(
      $section->getLayoutId(),
      $section->getLayoutSettings(),
      $components,
      $third_party_settings
    );
  }

  /**
   * Copies one component for placement in the cloned section.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to copy.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The copy, or NULL when the component cannot be copied safely and is
   *   therefore left out of the clone.
   */
  protected function duplicateComponent(SectionComponent $component): ?SectionComponent {
    if ($this->blockCloner->isInlineBlock($component)) {
      // NULL here means the block content could not be resolved; BlockCloner
      // logs the cause. Leave the block out rather than re-reference it, which
      // would leave two components sharing one block revision.
      return $this->blockCloner->duplicateComponent($component);
    }

    // Reusable and other non-inline blocks are shared across placements (issue
    // #190), so the copy points at the same block rather than becoming a
    // disconnected duplicate. Only the component UUID changes, which is what
    // keeps the two placements distinct within the layout.
    $component_array = $component->toArray();
    $component_array['uuid'] = $this->uuidGenerator->generate();

    return SectionComponent::fromArray($component_array);
  }

}
