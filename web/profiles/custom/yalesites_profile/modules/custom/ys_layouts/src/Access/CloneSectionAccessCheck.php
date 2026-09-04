<?php

namespace Drupal\ys_layouts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Refuses to clone a section whose contents the site owner froze.
 *
 * The layout_builder_lock module enforces its locks at the route level: its
 * RouteSubscriber stamps `_layout_builder_lock_access` onto core's section
 * routes, so a section the site owner locked cannot be added, configured or
 * removed even by URL. A new clone route has no such requirement, which would
 * let an editor duplicate a frozen section by visiting the route directly.
 *
 * The module's own check cannot simply be reused here. It reads `delta` from
 * the route as the *insertion point*, whereas this route's delta identifies the
 * section being copied and the insert lands at delta + 1, so a declarative
 * `_layout_builder_lock_access: 'section_add'` would evaluate the wrong
 * position.
 *
 * What counts as a blocking lock is the whole substance of this check, and it
 * turns on a distinction in layout_builder_lock's own settings:
 *
 * - The *content* locks — block add/update/delete/move, section configure, and
 *   move-blocks-into-section — say this section's contents are curated and not
 *   an editor's to change. Copying such a section hands the editor a section
 *   they cannot populate or correct, so those still refuse.
 * - The *positional* locks — `LOCKED_SECTION_BEFORE` and `LOCKED_SECTION_AFTER`
 *   — only remove the "Add section" links either side of this section. They say
 *   nothing about what it contains, so they do not make its contents unsafe to
 *   copy and no longer refuse. This is what makes the Content Section of Event
 *   and Post cloneable: those carry the positional pair and nothing else, while
 *   every Title and Metadata / Banner skeleton section carries content locks
 *   and stays refused.
 * - Region locks (`regions`) freeze the blocks of individual regions, so they
 *   are content locks in every meaningful sense and are read here too. The
 *   check did not read them before, which would have made a section locked
 *   only per-region cloneable the moment the positional locks stopped blocking.
 *
 * The bypass permissions are the same escape hatches layout_builder_lock itself
 * honours.
 *
 * With layout_builder_lock uninstalled there are no lock settings to read, so
 * this check allows everything and needs no module_handler guard.
 *
 * @see \Drupal\layout_builder_lock\Access\LayoutBuilderLockAccessCheck
 * @see \Drupal\layout_builder_lock\Routing\RouteSubscriber
 * @see \Drupal\ys_layouts\Service\SectionCloner::duplicateSection()
 */
class CloneSectionAccessCheck implements AccessInterface {

  /**
   * The layout_builder_lock settings for "no section before/after this one".
   *
   * Mirrored rather than referenced as LayoutBuilderLock::LOCKED_SECTION_BEFORE
   * / ::LOCKED_SECTION_AFTER because ys_layouts does not declare
   * layout_builder_lock in its dependencies: an uninstalled module's namespace
   * is not registered, so a class reference would fatal, and lock settings
   * survive in an entity view display after uninstall, which makes that branch
   * reachable rather than theoretical.
   *
   * The module is in fact enabled and pinned platform-wide, and ys_layouts
   * already writes its settings in LayoutUpdater, so declaring the dependency
   * and deleting this mirror is a reasonable follow-up — it is left out here
   * because it changes module install ordering, which is out of scope for a
   * review fix. Meanwhile the unit tests drive these branches through the real
   * LayoutBuilderLock constants, so a renumbering upstream fails a test rather
   * than silently inverting the policy.
   *
   * @see \Drupal\layout_builder_lock\LayoutBuilderLock
   * @see \Drupal\ys_layouts\Service\LayoutUpdater
   */
  private const POSITIONAL_LOCKS = [6, 7];

  /**
   * Checks whether the section at the route's delta may be cloned.
   *
   * The delta is read from the route match being checked rather than from
   * `current_route_match`. Access for this route is evaluated in two very
   * different places: on a real request to it, where the two would agree, and
   * at render time via Url::access() when the toolbar link is built, where the
   * current route is the Layout Builder page and carries no delta at all.
   * Using the injected current route match there silently yielded NULL and
   * allowed every section, so the link appeared on locked sections and only
   * failed when clicked.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage the clone would happen in.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match for the route being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed unless the section's contents are locked in a way the account
   *   cannot bypass.
   */
  public function access(SectionStorageInterface $section_storage, AccountInterface $account, RouteMatchInterface $route_match): AccessResultInterface {
    // Mirror layout_builder_lock's escape hatches: lock settings are managed on
    // the default layout and bypassed on overrides.
    $permission = $section_storage instanceof DefaultsSectionStorageInterface
      ? 'manage lock settings on sections'
      : 'bypass lock settings on layout overrides';

    if ($account->hasPermission($permission)) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    $delta = $route_match->getRawParameter('delta');
    if ($delta === NULL) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    try {
      $section = $section_storage->getSection((int) $delta);
    }
    catch (\OutOfBoundsException $e) {
      // A delta that does not resolve is the router's problem, not a lock
      // decision. Let the controller surface it.
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    // The lock form stores an unchecked box as 0, so the raw settings are only
    // meaningful after filtering — matching how layout_builder_lock reads them.
    $locks = array_filter($section->getThirdPartySetting('layout_builder_lock', 'lock', []));
    $content_locks = array_diff($locks, self::POSITIONAL_LOCKS);
    $region_locks = array_filter($section->getThirdPartySetting('layout_builder_lock', 'regions', []));

    if ($content_locks || $region_locks) {
      return AccessResult::forbidden("This section's contents are locked and it cannot be cloned.")
        ->addCacheContexts(['user.permissions']);
    }

    return AccessResult::allowed()->addCacheContexts(['user.permissions']);
  }

}
