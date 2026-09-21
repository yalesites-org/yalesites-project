<?php

namespace Drupal\ys_layouts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\TempStoreIdentifierInterface;

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
 * Those settings are read from the section being cloned **and, on an override,
 * from the default layout's section at the same delta**; either one carrying a
 * blocking lock refuses the clone. Reading the override alone is not enough,
 * and the gap is not theoretical — it is how a Post's "Title and Metadata"
 * section came to clone into a page with two titles and two publish dates.
 *
 * Lock settings are curated on the default layout. Core copies them into an
 * override when the override is created and nothing propagates a later change,
 * so an override holds a point-in-time *snapshot* that can say "positional
 * only" while the default says the contents are frozen. On this platform the
 * two are known to diverge: LayoutUpdater::getLockConfigs() keys each default
 * section's lock set by layout_id, and Post and Event use layout_onecol for
 * both of their sections, so the meta section's lock set was overwritten by the
 * content section's and stamped onto existing nodes. Correcting that keying
 * needs a data-repair pass that re-locks live content, so it is tracked
 * separately rather than fixed here.
 *
 * Two limits of this approach, both deliberate:
 *
 * - It closes *this* route. layout_builder_lock reads only the override's own
 *   snapshot, so wherever that data is stale the module's own enforcement is
 *   equally affected — those sections' blocks stay editable until the data is
 *   repaired. Refusing the clone does not fix that, it only stops this feature
 *   compounding it.
 * - Taking the union of the two refuses more than layout_builder_lock would,
 *   never less, which is the right bias for an access check. The cost is that
 *   it cannot correct an override that is *over*-locked relative to the
 *   default: such a section stays refused. That fails closed, so it is the
 *   direction to err in.
 *
 * Pairing an override section with the default section at the same delta is
 * layout_builder_lock's own idiom — its preRender resolves an override's
 * default components exactly that way. Deltas can in principle drift apart if
 * the default layout gains or loses a section, and that also fails closed: an
 * inserted section makes existing overrides over-refuse, and a removed one
 * leaves no counterpart, which falls back to the override's own locks. Matching
 * on component UUIDs instead is not an option — the Content Section of Post and
 * Event ships with no components at all, so there would be nothing to match.
 *
 * That the pairing cannot be walked out of alignment by an *editor* is a
 * property of the shipped config rather than of this code: every locked section
 * also carries LOCKED_SECTION_BEFORE, so no section can be inserted above one
 * and shift it down. That is load-bearing, so it is asserted — see
 * CloneSectionAccessCheckTest::testLockedSectionsAreFencedAgainstBeingShifted().
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
   * Blocking decisions for each default layout consulted, keyed by storage.
   *
   * Maps a section storage's identity to the tuple defaultLayout() returns —
   * the default section storage and its per-delta blocking decisions. See there
   * for the key and for why this is worth keeping.
   *
   * @var array<string, array{\Drupal\layout_builder\SectionStorageInterface, bool[]}>
   */
  private array $defaultLayoutBlocking = [];

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

    $raw_delta = $route_match->getRawParameter('delta');
    if ($raw_delta === NULL) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }
    $delta = (int) $raw_delta;

    try {
      $section = $section_storage->getSection($delta);
    }
    catch (\OutOfBoundsException $e) {
      // A delta that does not resolve is the router's problem, not a lock
      // decision. Let the controller surface it.
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    $blocked = $this->hasBlockingLocks($section);
    $default_storage = NULL;

    if (!$blocked && $section_storage instanceof OverridesSectionStorageInterface) {
      [$default_storage, $blocking] = $this->defaultLayout($section_storage);
      $blocked = $blocking[$delta] ?? FALSE;
    }

    $result = $blocked
      ? AccessResult::forbidden("This section's contents are locked and it cannot be cloned.")
      : AccessResult::allowed();

    // The decision depends on the sections just read, so both storages it read
    // them from are declared, the way layout_builder_lock's own check and
    // core's OverridesSectionStorage::access() do. The default storage matters
    // separately: an override's cache metadata comes from the node, so without
    // it an admin locking the default layout could not invalidate a cached
    // decision. Today's callers discard all of this — Url::access() returns a
    // bare bool and the Layout Builder element is uncacheable — so this is
    // insurance against a future caller that bubbles it, not a live fix.
    $result->addCacheContexts(['user.permissions'])->addCacheableDependency($section_storage);

    return $default_storage ? $result->addCacheableDependency($default_storage) : $result;
  }

  /**
   * The default layout behind an override, and which of its sections it locks.
   *
   * Resolved once per storage and cached on this service, which is
   * request-scoped. Core does not cache getDefaultSectionStorage(): every call
   * runs an entity query, a display load, an alter hook and a fresh plugin
   * instantiation. The toolbar asks this question once per section on the page
   * via Url::access(), so resolving it per section turned one lookup into one
   * per section on the page — reading the whole default layout once and keeping
   * the answers avoids that. The storage itself is kept alongside them so the
   * access result can declare it without paying for a second resolution.
   *
   * @param \Drupal\layout_builder\OverridesSectionStorageInterface $section_storage
   *   The override storage whose default layout should be consulted.
   *
   * @return array
   *   A tuple of the default section storage and an array of section delta =>
   *   whether that section carries a blocking lock. A delta absent from that
   *   array was added in the override and has no default locks to inherit.
   */
  private function defaultLayout(OverridesSectionStorageInterface $section_storage): array {
    // Which default layout this is. getTempstoreKey() is the better identity
    // because it carries the view mode, which decides the display resolved
    // below, but it belongs to TempStoreIdentifierInterface rather than to the
    // storage interfaces — so fall back to what those do guarantee.
    $key = $section_storage instanceof TempStoreIdentifierInterface
      ? $section_storage->getTempstoreKey()
      : $section_storage->getStorageType() . ':' . $section_storage->getStorageId();

    if (!isset($this->defaultLayoutBlocking[$key])) {
      $default_storage = $section_storage->getDefaultSectionStorage();
      $this->defaultLayoutBlocking[$key] = [
        $default_storage,
        array_map(
          fn (Section $section): bool => $this->hasBlockingLocks($section),
          $default_storage->getSections()
        ),
      ];
    }

    return $this->defaultLayoutBlocking[$key];
  }

  /**
   * Whether a section's lock settings say its contents are not to be copied.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to read.
   *
   * @return bool
   *   TRUE if the section carries a content lock or a region lock.
   */
  private function hasBlockingLocks(Section $section): bool {
    // The lock form stores an unchecked box as 0, so the raw settings are only
    // meaningful after filtering — matching how layout_builder_lock reads them.
    $locks = array_filter($section->getThirdPartySetting('layout_builder_lock', 'lock', []));
    $region_locks = array_filter($section->getThirdPartySetting('layout_builder_lock', 'regions', []));

    return (bool) (array_diff($locks, self::POSITIONAL_LOCKS) || $region_locks);
  }

}
