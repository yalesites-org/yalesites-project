<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Editoria11yRouteSuppressor;

/**
 * Tests the route rules for suppressing the Editoria11y checker.
 *
 * Regression coverage for the bug where the Editoria11y overlay appeared on
 * content authoring forms in the admin UI. The checker should run on
 * front-facing content and on Editoria11y's own pages, but not on
 * admin/authoring routes.
 *
 * @coversDefaultClass \Drupal\ys_core\Editoria11yRouteSuppressor
 *
 * @group ys_core
 */
class Editoria11yRouteSuppressorTest extends UnitTestCase {

  /**
   * @covers ::shouldSuppress
   *
   * @dataProvider routeProvider
   */
  public function testShouldSuppress(?string $route_name, bool $is_admin_route, bool $expected): void {
    $this->assertSame(
      $expected,
      Editoria11yRouteSuppressor::shouldSuppress($route_name, $is_admin_route)
    );
  }

  /**
   * Provides route names with the admin flag and the expected suppression.
   *
   * @return array
   *   Each case: [route name, is admin route, expected shouldSuppress()].
   */
  public static function routeProvider(): array {
    return [
      // Editoria11y's own pages are kept even though they are admin routes.
      'reports dashboard kept' => ['editoria11y.reports_dashboard', TRUE, FALSE],
      'demo kept' => ['editoria11y.demo', TRUE, FALSE],

      // Front-facing routes keep the checker.
      'node canonical kept' => ['entity.node.canonical', FALSE, FALSE],
      'public profile kept' => ['entity.user.canonical', FALSE, FALSE],
      'view listing kept' => ['view.frontend_listing.page_1', FALSE, FALSE],
      'node preview kept' => ['entity.node.preview', FALSE, FALSE],

      // Admin routes are suppressed regardless of name.
      'admin content listing suppressed' => ['system.admin_content', TRUE, TRUE],
      'admin config suppressed' => ['system.admin_config', TRUE, TRUE],

      // Authoring forms are suppressed by name pattern even when the route is
      // not flagged as an admin route (e.g. taxonomy term edit).
      'node add suppressed' => ['node.add', FALSE, TRUE],
      'node edit suppressed' => ['entity.node.edit_form', FALSE, TRUE],
      'node delete suppressed' => ['entity.node.delete_form', FALSE, TRUE],
      'term add suppressed' => ['entity.taxonomy_term.add_form', FALSE, TRUE],
      'term edit suppressed' => ['entity.taxonomy_term.edit_form', FALSE, TRUE],
      'block add suppressed' => ['entity.block_content.add_form', FALSE, TRUE],
      'media edit suppressed' => ['entity.media.edit_form', FALSE, TRUE],
      'user create suppressed' => ['user.admin_create', FALSE, TRUE],

      // No matched route keeps the checker (defensive: never crash on NULL).
      'null route kept' => [NULL, FALSE, FALSE],
    ];
  }

}
