<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\ReusableBlockDetacher;

/**
 * Restricts the detach route to placed reusable content blocks.
 *
 * Layout Builder filters contextual links by route access, so gating the detach
 * route here is what keeps the "Make non-reusable" link from appearing on
 * inline blocks (which have nothing to detach).
 */
class DetachReusableBlockAccessCheck implements AccessInterface {

  public function __construct(
    protected ReusableBlockDetacher $detacher,
  ) {}

  /**
   * Checks that the targeted component is a placed reusable content block.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage, upcast from the route.
   * @param mixed $delta
   *   The section delta.
   * @param string $uuid
   *   The uuid of the component being acted on.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed only when the component places a reusable block.
   */
  public function access(SectionStorageInterface $section_storage, $delta, string $uuid): AccessResultInterface {
    try {
      $component = $section_storage->getSection((int) $delta)->getComponent($uuid);
    }
    catch (\Exception) {
      // An unresolvable delta/uuid means there is nothing to detach here.
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    // The result depends on live, per-request layout state (which block is
    // placed where), so it must not be cached.
    return AccessResult::allowedIf($this->detacher->isReusableBlockComponent($component))
      ->setCacheMaxAge(0);
  }

}
