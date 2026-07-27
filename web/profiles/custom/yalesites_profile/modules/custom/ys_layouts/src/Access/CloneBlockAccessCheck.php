<?php

namespace Drupal\ys_layouts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\Service\BlockCloner;

/**
 * Allows the clone route only for inline blocks.
 *
 * This is the server-side half of the reusable-block exclusion; the contextual
 * link itself is removed in ys_layouts_contextual_links_view_alter() so the
 * option never appears on a block that cannot be cloned.
 *
 * The storage passed in has to be re-read from the layout tempstore: route
 * enhancers do not run inside AccessManager::checkNamedRoute(), which is how
 * the contextual link manager evaluates access, so without this the check
 * would look at the last saved layout and could not see a block the editor
 * added moments ago.
 *
 * @see \Drupal\Core\Access\AccessManager::checkNamedRoute()
 * @see \Drupal\layout_builder\Routing\LayoutTempstoreRouteEnhancer
 */
class CloneBlockAccessCheck implements AccessInterface {

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The block cloner service.
   *
   * @var \Drupal\ys_layouts\Service\BlockCloner
   */
  protected $blockCloner;

  /**
   * Constructs a new CloneBlockAccessCheck object.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   *   The layout tempstore repository.
   * @param \Drupal\ys_layouts\Service\BlockCloner $block_cloner
   *   The block cloner service.
   */
  public function __construct(
    LayoutTempstoreRepositoryInterface $layout_tempstore_repository,
    BlockCloner $block_cloner,
  ) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->blockCloner = $block_cloner;
  }

  /**
   * Checks that the requested component is a clonable inline block.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param mixed $delta
   *   The delta of the section holding the block.
   * @param mixed $uuid
   *   The UUID of the component being cloned.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(
    SectionStorageInterface $section_storage,
    $delta = NULL,
    $uuid = NULL,
  ): AccessResultInterface {
    // Unsaved layouts only exist in the tempstore.
    $section_storage = $this->layoutTempstoreRepository->get($section_storage);

    try {
      $component = $section_storage->getSection((int) $delta)
        ->getComponent((string) $uuid);
    }
    catch (\OutOfBoundsException | \InvalidArgumentException $e) {
      // The component is not in this layout (any more).
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }

    $clonable = $this->blockCloner->isClonable($component);

    // Not cacheable: the answer depends on the current user's unsaved layout.
    return AccessResult::allowedIf($clonable)->setCacheMaxAge(0);
  }

}
