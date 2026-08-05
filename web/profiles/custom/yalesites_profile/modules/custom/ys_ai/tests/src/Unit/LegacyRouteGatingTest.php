<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that every ys_ai-owned route is gated on Beacon supersession.
 *
 * Menu links follow route access in Drupal, so the requirement is what removes
 * the "YaleSites AI" tree and the "System Instructions Settings" item under
 * Configuration > AI Engine. It is asserted over the routing files rather than
 * per access check because the guarantee is that no route is missed: a
 * bookmarked URL must not reach a form whose menu link is hidden.
 *
 * @group ys_ai
 * @group yalesites
 */
class LegacyRouteGatingTest extends UnitTestCase {

  /**
   * The route requirement added by ys_ai.
   */
  protected const REQUIREMENT = '_ys_ai_not_superseded';

  /**
   * Every route in both ys_ai routing files carries the requirement.
   */
  public function testAllLegacyAiRoutesRequireNotSuperseded(): void {
    $module_dir = dirname(__DIR__, 3);
    $routing_files = [
      $module_dir . '/ys_ai.routing.yml',
      $module_dir . '/modules/ys_ai_system_instructions/ys_ai_system_instructions.routing.yml',
    ];

    $checked = [];
    foreach ($routing_files as $path) {
      $this->assertFileExists($path);
      foreach (Yaml::parseFile($path) as $route_name => $route) {
        $this->assertSame(
          'TRUE',
          $route['requirements'][self::REQUIREMENT] ?? NULL,
          sprintf('Route %s is not gated on %s.', $route_name, self::REQUIREMENT)
        );
        $checked[] = $route_name;
      }
    }

    // Guards against the files being emptied or renamed out from under the
    // loop, which would otherwise pass vacuously.
    $this->assertEqualsCanonicalizing([
      'ys_ai.settings',
      'ys_ai_system_instructions.settings',
      'ys_ai_system_instructions.form',
      'ys_ai_system_instructions.versions',
      'ys_ai_system_instructions.view',
      'ys_ai_system_instructions.revert',
    ], $checked, 'The set of ys_ai-owned routes changed; confirm each one is gated.');
  }

  /**
   * A tagged access check actually answers that requirement.
   *
   * Drupal ignores a route requirement no access check applies to, rather than
   * failing, so a dropped tag or a typo in applies_to would silently reopen
   * all six routes while the assertions above stayed green.
   */
  public function testRequirementIsAnsweredByTaggedAccessCheck(): void {
    $services = Yaml::parseFile(dirname(__DIR__, 3) . '/ys_ai.services.yml');

    $applies_to = [];
    foreach ($services['services'] as $service) {
      foreach ($service['tags'] ?? [] as $tag) {
        if (($tag['name'] ?? NULL) === 'access_check') {
          $applies_to[] = $tag['applies_to'] ?? NULL;
        }
      }
    }

    $this->assertContains(self::REQUIREMENT, $applies_to);
  }

}
