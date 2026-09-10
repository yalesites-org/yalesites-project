<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Service\LayoutUpdater;

/**
 * Tests that no shipped default section loses its 70/30 separator.
 *
 * The Two column (70/30) separator used to be drawn unconditionally in
 * `_yds-layout.scss`, so the Divider checkbox YSLayoutOptions puts on every
 * section did nothing on that layout. Gating the separator on the toggle
 * (component-library-twig, yalesites-project#1514) makes the control work, but
 * it also means every 70/30 section that exists today -- all of which render a
 * separator -- loses it unless its stored setting is turned on. There are two
 * populations, and missing either one is a visible regression on live sites:
 *
 *  1. Nodes whose Layout Builder layout has been overridden store their own
 *     sections. `ys_layouts_deploy_9005()` walks those, and
 *     SeventyThirtyDividerMigrationTest covers it.
 *  2. Nodes still served by the content type's default display read the
 *     section out of config, which no deploy hook touches. Only
 *     `core.entity_view_display.node.profile.default.yml` ships a
 *     `ys_layout_two_column` default section, so that file has to carry
 *     `divider: 1` itself. That is what this class guards.
 *
 * Asserted against the exported YAML rather than a rendered page because the
 * failure mode is silent: a config re-export that quietly drops the key ships
 * a regression that nothing else fails on.
 *
 * @group yalesites
 * @group ys_layouts
 */
class SeventyThirtyDividerDefaultsTest extends UnitTestCase {

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * The shipped default profile layout keeps its 70/30 separator.
   *
   * A profile page whose layout has never been overridden renders this
   * section, so the deploy hook cannot reach it. Without `divider: 1` here,
   * gating the separator on the toggle removes the line between a profile's
   * content and its contact sidebar on every site.
   */
  public function testProfileDefaultSectionOptsIntoTheDivider(): void {
    $path = $this->configSyncDir() . '/core.entity_view_display.node.profile.default.yml';
    $this->assertFileExists($path);

    $display = Yaml::decode(file_get_contents($path));
    $sections = $display['third_party_settings']['layout_builder']['sections'] ?? [];
    $this->assertNotEmpty($sections, 'The profile default display ships no sections.');

    $matched = 0;
    foreach ($sections as $section) {
      if (($section['layout_id'] ?? NULL) !== LayoutUpdater::SEVENTY_THIRTY_LAYOUT_ID) {
        continue;
      }
      $matched++;
      $this->assertSame(
        1,
        $section['layout_settings']['divider'] ?? 0,
        'The default profile 70/30 section must opt into the divider, or '
        . 'profile pages on the default layout lose the separator they '
        . 'render today.'
      );
    }

    $this->assertSame(
      1,
      $matched,
      'Expected exactly one ys_layout_two_column default section in the '
      . 'profile display; the count changed, so re-check this expectation.'
    );
  }

  /**
   * No other shipped display has an unmigrated 70/30 default section.
   *
   * Read out of every exported display rather than from a list here, so a new
   * default 70/30 section added anywhere in config is caught by this test
   * instead of shipping without its divider setting.
   *
   * If a future content type's default 70/30 legitimately wants no separator,
   * that is a real exception rather than a regression -- add it to an explicit
   * exemption list here with a note saying why, rather than deleting the test.
   */
  public function testNoShippedDefaultSectionMissesTheDivider(): void {
    $files = glob($this->configSyncDir() . '/core.entity_view_display.*.yml');
    $this->assertNotEmpty($files, 'No entity view display config was found.');

    $missing = [];
    foreach ($files as $path) {
      $display = Yaml::decode(file_get_contents($path));
      $sections = $display['third_party_settings']['layout_builder']['sections'] ?? [];
      foreach ($sections as $section) {
        if (($section['layout_id'] ?? NULL) !== LayoutUpdater::SEVENTY_THIRTY_LAYOUT_ID) {
          continue;
        }
        if (empty($section['layout_settings']['divider'])) {
          $missing[] = basename($path);
        }
      }
    }

    $this->assertSame(
      [],
      $missing,
      'These displays ship a 70/30 default section with the divider off, so '
      . 'they will render without the separator they have today: '
      . implode(', ', $missing)
    );
  }

}
