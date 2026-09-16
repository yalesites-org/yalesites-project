<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests which metatag groups the shipped config exposes per bundle.
 *
 * The metatag.settings:entity_type_groups map gates which groups a bundle's
 * edit form can render, so a bundle listed here without ys_beacon can never
 * show the AI Metadata fields however the platform toggle is set. A bundle
 * dropped from the map entirely is not safe either: MetatagFirehose falls back
 * to rendering every group when a bundle has no entry, and the settings form
 * drops a bundle whose groups are all unchecked. So the expected bundle set is
 * pinned as well as its contents. That file belongs to the profile rather than
 * to this module, but the invariant it has to hold is this module's: every
 * bundle gets the ys_beacon group, and none reference the superseded ai_engine
 * group.
 *
 * @group ys_beacon
 */
class BeaconMetatagGroupsTest extends UnitTestCase {

  /**
   * Returns the per-bundle group lists from the synced metatag config.
   */
  private function bundleGroups(): array {
    $path = dirname(__DIR__, 6) . '/config/sync/metatag.settings.yml';
    $this->assertFileExists($path);

    $bundle_groups = [];
    foreach (Yaml::parseFile($path)['entity_type_groups'] as $entity_type => $bundles) {
      foreach ($bundles as $bundle => $groups) {
        $bundle_groups["$entity_type.$bundle"] = array_keys($groups);
      }
    }
    $this->assertNotEmpty($bundle_groups);
    return $bundle_groups;
  }

  /**
   * Every bundle exposing metatags can show the AI Metadata group.
   */
  public function testEveryMetatagBundleExposesTheBeaconGroup(): void {
    $bundle_groups = $this->bundleGroups();

    // Pinned rather than derived: a bundle silently dropped from the map would
    // otherwise satisfy the loop below while rendering every metatag group on
    // its form. Adding a bundle here should be a deliberate edit.
    $this->assertEqualsCanonicalizing([
      'media.document',
      'node.event',
      'node.page',
      'node.post',
      'node.profile',
      'node.resource',
    ], array_keys($bundle_groups));

    foreach ($bundle_groups as $bundle => $groups) {
      $this->assertContains('ys_beacon', $groups, "$bundle is missing the ys_beacon metatag group");
    }
  }

  /**
   * No bundle still references the superseded ai_engine group.
   *
   * This module supersedes ai_engine_metadata: it redefines the same tag IDs
   * under its own group and ys_beacon_metatag_groups_alter() drops the leftover
   * ai_engine group outright, so the key resolves to nothing.
   */
  public function testNoBundleReferencesTheLegacyAiEngineGroup(): void {
    foreach ($this->bundleGroups() as $bundle => $groups) {
      $this->assertNotContains('ai_engine', $groups, "$bundle still references the superseded ai_engine metatag group");
    }
  }

}
