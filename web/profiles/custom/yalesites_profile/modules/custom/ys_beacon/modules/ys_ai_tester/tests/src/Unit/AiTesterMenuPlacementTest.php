<?php

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the AI Tester menu link nests under the Beacon menu group.
 *
 * The AI Tester belongs with the other Beacon AI screens (settings,
 * administration, system instructions) rather than as a sibling at the
 * integrations root.
 *
 * @group ys_beacon
 */
class AiTesterMenuPlacementTest extends UnitTestCase {

  /**
   * The AI Tester link is parented to the YaleSites Beacon menu item.
   */
  public function testTesterLinkNestsUnderBeacon(): void {
    $path = dirname(__DIR__, 3) . '/ys_ai_tester.links.menu.yml';
    $this->assertFileExists($path);
    $links = Yaml::parseFile($path);
    $this->assertSame('ys_beacon.admin', $links['ys_ai_tester.tester']['parent']);
  }

}
