<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that ys_core's top-level admin menu weights order correctly.
 *
 * Two independent things set these weights, on two different numeric scales:
 *
 * - `ys_core.links.menu.yml`, the module default, competes with Drupal core's
 *   own defaults for the same menu. Those run -10 (Content) to 10
 *   (announcements_feed, disabled here; help.main at 9 is the highest in
 *   play).
 * - `core.menu.static_menu_link_overrides`, exported in the profile, renumbers
 *   the whole top level onto a -49..-38 scale and wins wherever it is applied.
 *
 * Copying a number from the second into the first inverts its meaning: -38 is
 * *last* among the overrides and *first* among the core defaults. That is a
 * silent failure, because on a site carrying the override config the rendered
 * menu still looks right, and only an environment that does not have it -
 * a fresh install, a rebuilt environment, or one where the override has
 * drifted - shows the wrong order.
 *
 * These tests pin the module defaults on their own terms so the menu is
 * ordered sensibly with or without the override. The weight floor and the
 * ordering assertion are load-bearing as a pair: the floor alone would accept
 * a weight that still sorts Platform Admin ahead of the Dashboard.
 *
 * @group ys_core
 */
class AdminMenuLinkWeightTest extends UnitTestCase {

  /**
   * The highest weight Drupal core gives a top-level admin menu link.
   *
   * `announcements_feed.announcement` at 10, ahead of `help.main` at 9.
   * announcements_feed is not enabled on YaleSites, so 9 is the highest
   * weight actually in play today - but the constant tracks what core can
   * declare, not what happens to be installed, so that enabling a core module
   * cannot silently reorder the menu. Derived by parsing every
   * `*.links.menu.yml` under web/core, web/modules/contrib and the custom
   * trees for `parent: system.admin`, not from memory.
   */
  private const CORE_MAX_TOP_LEVEL_WEIGHT = 10;

  /**
   * Module-defined links, keyed by plugin ID.
   */
  private function moduleLinks(): array {
    return Yaml::parseFile(__DIR__ . '/../../../ys_core.links.menu.yml');
  }

  /**
   * Static menu link override definitions from the profile's exported config.
   */
  private function overrideDefinitions(): array {
    $path = __DIR__ . '/../../../../../../config/sync/core.menu.static_menu_link_overrides.yml';
    $this->assertFileExists($path, 'The profile export that renumbers the admin menu must be findable from here.');
    return Yaml::parseFile($path)['definitions'] ?? [];
  }

  /**
   * The ys_core links parented directly to system.admin, with their weights.
   */
  private function topLevelModuleLinks(): array {
    $links = [];
    foreach ($this->moduleLinks() as $id => $definition) {
      if (is_array($definition) && ($definition['parent'] ?? NULL) === 'system.admin') {
        $links[$id] = $definition['weight'] ?? 0;
      }
    }
    $this->assertNotEmpty($links, 'ys_core should define at least one top-level admin link.');
    return $links;
  }

  /**
   * Platform Admin must not be able to lead the admin menu.
   *
   * This is the specific regression: a weight copied from the override config
   * put a -38 on the core scale, which sorts it ahead of Content (-10) and so
   * renders it as the first item in the toolbar.
   */
  public function testPlatformAdminSortsAfterCoreLinks(): void {
    $weights = $this->topLevelModuleLinks();
    $this->assertArrayHasKey('ys_core.admin_platform_admin', $weights);
    $this->assertGreaterThan(
      self::CORE_MAX_TOP_LEVEL_WEIGHT,
      $weights['ys_core.admin_platform_admin'],
      'Platform Admin is meant to be the last top-level admin item, so its module-default weight must exceed the highest weight core declares for one (announcements_feed.announcement at 10). A weight borrowed from core.menu.static_menu_link_overrides is on a different scale and will sort it first.'
    );
  }

  /**
   * The dashboard must also sort after core's own links.
   */
  public function testDashboardSortsAfterCoreLinks(): void {
    $weights = $this->topLevelModuleLinks();
    $this->assertArrayHasKey('ys_core.admin_dashboard', $weights);
    $this->assertGreaterThan(
      self::CORE_MAX_TOP_LEVEL_WEIGHT,
      $weights['ys_core.admin_dashboard'],
      'The Dashboard is meant to sit near the end of the admin menu, after core\'s own links.'
    );
  }

  /**
   * Module defaults order ys_core's links the same way the override does.
   *
   * The override config is the statement of intended order. The module
   * defaults are a different scale, but they must agree on the *sequence*,
   * otherwise the menu rearranges itself depending on whether the override
   * happens to be applied.
   */
  public function testModuleDefaultsAgreeWithOverrideOrdering(): void {
    $module_weights = $this->topLevelModuleLinks();
    $overrides = $this->overrideDefinitions();

    $override_weights = [];
    foreach (array_keys($module_weights) as $id) {
      // Override keys replace only the first dot: ys_core.foo -> ys_core__foo.
      $override_key = preg_replace('/\./', '__', $id, 1);
      if (isset($overrides[$override_key]['weight'])) {
        $override_weights[$id] = $overrides[$override_key]['weight'];
      }
    }
    $this->assertNotEmpty($override_weights, 'Expected the export to pin at least one ys_core top-level link.');

    $by_module = $this->sortedIds(array_intersect_key($module_weights, $override_weights));
    $by_override = $this->sortedIds($override_weights);

    $this->assertSame(
      $by_override,
      $by_module,
      'The module-default weights must place ys_core\'s top-level admin links in the same order as the exported override config, so the menu does not rearrange depending on whether that config is applied.'
    );
  }

  /**
   * Plugin IDs sorted by weight, ascending.
   */
  private function sortedIds(array $weights): array {
    asort($weights);
    return array_keys($weights);
  }

}
