<?php

namespace Drupal\Tests\ys_localist\Kernel;

use Drupal\Core\Url;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Pins CSRF protection on the Localist sync routes.
 *
 * Both routes change data on a plain GET, so without a token any page the
 * admin visits could trigger a sync with an image tag or a link.
 *
 * @group ys_localist
 * @group yalesites
 */
class SyncRouteCsrfTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'migrate',
    'migrate_plus',
    'migrate_tools',
    'ys_core',
    'ys_integrations',
    'ys_localist',
  ];

  /**
   * The routes that run a sync on GET.
   *
   * @return array
   *   Data sets of route names.
   */
  public static function syncRouteProvider(): array {
    return [
      'events sync' => ['ys_localist.run_migrations'],
      'groups sync' => ['ys_localist.sync_groups'],
    ];
  }

  /**
   * The route requires a CSRF token, and generated URLs carry one.
   *
   * @dataProvider syncRouteProvider
   */
  public function testSyncRouteRequiresCsrfToken(string $route_name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);

    $this->assertSame('TRUE', $route->getRequirement('_csrf_token'));
    $this->assertStringContainsString('token=', Url::fromRoute($route_name)->toString());
  }

}
