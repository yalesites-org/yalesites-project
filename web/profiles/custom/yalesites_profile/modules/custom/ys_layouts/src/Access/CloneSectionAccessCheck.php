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
 * Refuses to clone a section that carries Layout Builder Lock settings.
 *
 * The layout_builder_lock module enforces its locks at the route level: its
 * RouteSubscriber stamps `_layout_builder_lock_access` onto core's section
 * routes, so a section the site owner locked cannot be added, configured or
 * removed even by URL. A new clone route has no such requirement, which would
 * leave two holes: an editor could duplicate a locked section by visiting the
 * route directly, and because the copy inherits the lock third-party settings
 * they would then be unable to remove it — a section they created and cannot
 * delete without discarding every unsaved layout change.
 *
 * The module's own check cannot simply be reused here. It reads `delta` from
 * the route as the *insertion point*, whereas this route's delta identifies the
 * section being copied and the insert lands at delta + 1, so a declarative
 * `_layout_builder_lock_access: 'section_add'` would evaluate the wrong
 * position.
 *
 * The rule applied instead is deliberately conservative: a section carrying any
 * lock at all is not cloneable. Locked sections on this platform are the
 * curated page skeleton (the Banner and Title and Metadata sections of the
 * default layouts) rather than repeatable content, so refusing outright is both
 * safe and easy to explain. The bypass permissions are the same escape hatches
 * layout_builder_lock itself honours.
 *
 * With layout_builder_lock uninstalled there are no lock settings to read, so
 * this check allows everything and needs no module_handler guard.
 *
 * @see \Drupal\layout_builder_lock\Access\LayoutBuilderLockAccessCheck
 * @see \Drupal\layout_builder_lock\Routing\RouteSubscriber
 */
class CloneSectionAccessCheck implements AccessInterface {

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
   *   Allowed unless the section carries lock settings the account cannot
   *   bypass.
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
      $locks = array_filter($section_storage->getSection((int) $delta)
        ->getThirdPartySetting('layout_builder_lock', 'lock', []));
    }
    catch (\OutOfBoundsException $e) {
      // A delta that does not resolve is the router's problem, not a lock
      // decision. Let the controller surface it.
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if ($locks) {
      return AccessResult::forbidden('This section is locked and cannot be cloned.')
        ->addCacheContexts(['user.permissions']);
    }

    return AccessResult::allowed()->addCacheContexts(['user.permissions']);
  }

}
