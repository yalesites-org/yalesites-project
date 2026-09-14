<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\SiteimproveRouteChecker;

/**
 * Characterisation tests for the SiteImprove load rule.
 *
 * Pins the current behaviour of the logic extracted from
 * _ys_core_should_load_siteimprove(): SiteImprove loads on front-facing pages
 * and is suppressed on admin and content-authoring routes/paths.
 *
 * @coversDefaultClass \Drupal\ys_core\SiteimproveRouteChecker
 *
 * @group ys_core
 */
class SiteimproveRouteCheckerTest extends UnitTestCase {

  /**
   * @covers ::shouldLoad
   *
   * @dataProvider routeProvider
   */
  public function testShouldLoad(?string $route_name, string $current_path, bool $expected): void {
    $this->assertSame(
      $expected,
      SiteimproveRouteChecker::shouldLoad($route_name, $current_path)
    );
  }

  /**
   * Provides route/path pairs and whether SiteImprove should load.
   *
   * @return array
   *   Each case: [route name, current path, expected shouldLoad()].
   */
  public static function routeProvider(): array {
    return [
      // Front-facing pages load SiteImprove.
      'node canonical' => ['entity.node.canonical', '/about-us', TRUE],
      'public profile' => ['entity.user.canonical', '/jane-doe', TRUE],
      'taxonomy term page' => ['entity.taxonomy_term.canonical', '/tags/news', TRUE],
      'front page' => ['view.frontend.page_1', '/', TRUE],
      'null route, front-facing path' => [NULL, '/some-page', TRUE],

      // Suppressed by route name even when the path is otherwise benign.
      'node revisions route' => ['entity.node.version_history', '/node/5/revisions', FALSE],
      'translation overview route' => ['entity.node.content_translation_overview', '/node/5/translations', FALSE],
      'admin config route' => ['system.admin_config', '/admin/config/system', FALSE],

      // Suppressed by admin/editing path prefix.
      'admin path prefix' => ['some.route', '/admin/config/system', FALSE],
      'node add path prefix' => ['some.route', '/node/add', FALSE],
      'layout builder path prefix' => ['some.route', '/layout_builder/whatever', FALSE],
      'devel path prefix' => ['some.route', '/devel/php', FALSE],

      // Suppressed by an edit/add/delete/layout path segment.
      'edit path segment' => ['some.route', '/node/5/edit', FALSE],
      'delete path segment' => ['some.route', '/widget/3/delete', FALSE],
      'layout path segment' => ['some.route', '/node/5/layout', FALSE],
      'add path segment' => ['some.route', '/gallery/add', FALSE],
    ];
  }

}
