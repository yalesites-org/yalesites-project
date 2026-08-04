<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_layouts\ReusableBlockDetacher;
use Psr\Log\LoggerInterface;

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
    protected LoggerInterface $logger,
    protected LayoutTempstoreRepositoryInterface $layoutTempstore,
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
      // Judge the layout actually being edited. Layout Builder swaps in the
      // tempstore copy from a route enhancer (LayoutTempstoreRouteEnhancer),
      // which runs only while routing a real request - not from
      // AccessManager::checkNamedRoute(), which is how the contextual link
      // system evaluates this route. Without resolving it here the check would
      // judge the saved layout and keep offering the action on a placement
      // already detached in this editing session. Returns the argument
      // unchanged when the layout has no unsaved changes. Inside the try so a
      // tempstore failure hides the link rather than breaking the whole page.
      $section_storage = $this->layoutTempstore->get($section_storage);
      $component = $section_storage->getSection((int) $delta)->getComponent($uuid);
    }
    catch (\Exception $e) {
      // An unresolvable delta/uuid means there is nothing to detach here.
      // Layout Builder hides a contextual link whose route access is denied
      // without surfacing any error, so log the reason: otherwise a missing
      // "Make non-reusable" action cannot be diagnosed from the outside.
      $this->logger->debug('Cannot resolve component @uuid at delta @delta for the detach action, so it is not offered: @message', [
        '@uuid' => $uuid,
        '@delta' => $delta,
        '@message' => $e->getMessage(),
      ]);
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    // The result depends on live, per-request layout state (which block is
    // placed where), so it must not be cached.
    return AccessResult::allowedIf($this->detacher->isReusableBlockComponent($component))
      ->setCacheMaxAge(0);
  }

}
