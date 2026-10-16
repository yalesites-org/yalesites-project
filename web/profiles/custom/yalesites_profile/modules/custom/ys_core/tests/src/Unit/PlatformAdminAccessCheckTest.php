<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Access\PlatformAdminAccessCheck;
use Drupal\ys_core\PlatformAdminCheckerInterface;

/**
 * Tests the route access check that delegates to the platform admin service.
 *
 * This check exists so that a route can be gated on the same definition of
 * "platform admin" that PHP callers get. YAML cannot call a service, so a
 * route with a bare _permission requirement bypasses the service entirely -
 * which is the gap this closes (yalesites-org/YaleSites-Internal#1695).
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Access\PlatformAdminAccessCheck
 */
class PlatformAdminAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // cachePerUser() validates its cache context token against this service.
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $this->createMock(CacheContextsManager::class));
    \Drupal::setContainer($container);
  }

  /**
   * A platform admin is allowed.
   *
   * @covers ::access
   */
  public function testPlatformAdminIsAllowed(): void {
    $result = $this->check(TRUE)->access($this->createMock(AccountInterface::class));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * A non-platform-admin is denied, neutrally rather than forbidden.
   *
   * This is the behavioural equivalence that makes swapping the route's
   * _permission requirement for this check a no-op today. Core's
   * PermissionAccessCheck returns allowedIfHasPermission(), which is NEUTRAL
   * when the permission is missing, and a route denies on neutral anyway. An
   * explicit forbidden() would look identical on this route - nothing else
   * gates it - but forbidden always wins, so it would silently change how the
   * route composes with any access check added later. Contrast
   * \Drupal\ys_beacon\Access\BeaconSuperadminOnlyAccessCheck, which forbids on
   * purpose for exactly that reason.
   *
   * @covers ::access
   */
  public function testDenialIsNeutralNotForbidden(): void {
    $result = $this->check(FALSE)->access($this->createMock(AccountInterface::class));

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isNeutral());
    $this->assertFalse($result->isForbidden());
  }

  /**
   * The account under test is the one passed in, not the current user.
   *
   * An access check is always asked about the account making the request,
   * which is not necessarily the container's current user - during a menu
   * build or an account switch it is not. Passing it through is what keeps
   * this check honest, so it is asserted rather than assumed.
   *
   * @covers ::access
   */
  public function testTheCheckedAccountIsThePassedAccount(): void {
    $account = $this->createMock(AccountInterface::class);
    $checker = $this->createMock(PlatformAdminCheckerInterface::class);
    $checker->expects($this->once())
      ->method('isPlatformAdmin')
      ->with($this->identicalTo($account))
      ->willReturn(TRUE);

    $this->assertTrue((new PlatformAdminAccessCheck($checker))->access($account)->isAllowed());
  }

  /**
   * The result declares both the user and the permissions cache contexts.
   *
   * Both, because the definition consults both. 'user' is required since the
   * definition includes a uid check, so two accounts with identical
   * permissions get different answers when one is user 1. 'user.permissions'
   * is required because it is what bubbles the config:user.role.* invalidation
   * tags - UserCacheContext contributes no metadata of its own - and dropping
   * it would silently discard the invalidation that core's
   * allowedIfHasPermission() used to contribute on this route.
   *
   * Asserting 'user.permissions' explicitly is the point of this test:
   * CacheContextsManager optimizes it away as implied by 'user' before the
   * keys are built, so nothing downstream would reveal its absence here.
   *
   * @covers ::access
   */
  public function testTheResultIsCachedPerUserAndPerPermissions(): void {
    $contexts = $this->check(TRUE)->access($this->createMock(AccountInterface::class))
      ->getCacheContexts();

    $this->assertContains('user', $contexts);
    $this->assertContains('user.permissions', $contexts);
  }

  /**
   * The route is gated on this check rather than on the bare permission.
   *
   * The gap being closed here is invisible in PHP: it lives in the route's
   * requirements. YAML cannot reference a PHP constant or a service, so the
   * requirement name is repeated in ys_core.routing.yml and in the service
   * tag in ys_core.services.yml, and renaming one alone unhooks the route
   * from this gate. That does not open the route - AccessManager::check()
   * starts from AccessResult::neutral() and leaves it there when a route has
   * no resolved checks, so the page 403s for everyone including user 1 - but
   * a total lockout of the platform admin page is its own outage. That is the
   * failure this pins, for the same reason PlatformAdminCheckerTest pins the
   * permission string.
   */
  public function testTheRouteIsGatedOnThisCheck(): void {
    $module = dirname(__DIR__, 3);

    $routes = Yaml::decode(file_get_contents($module . '/ys_core.routing.yml'));
    $requirements = $routes['ys_core.platform_admin_settings']['requirements'];

    $this->assertArrayHasKey(
      PlatformAdminAccessCheck::REQUIREMENT,
      $requirements,
      'The Platform Admin Settings route must be gated on this access check.'
    );
    $this->assertArrayNotHasKey(
      '_permission',
      $requirements,
      'A bare _permission requirement would reopen the gap: it does not consult the service.'
    );

    $services = Yaml::decode(file_get_contents($module . '/ys_core.services.yml'));
    $tags = $services['services']['ys_core.platform_admin_access_check']['tags'];
    $this->assertSame(
      [['name' => 'access_check', 'applies_to' => PlatformAdminAccessCheck::REQUIREMENT]],
      $tags,
      'The service tag must advertise the same requirement name the route uses.'
    );
  }

  /**
   * Builds the check with a stub service returning the given answer.
   *
   * @param bool $is_platform_admin
   *   What the platform admin checker should report.
   *
   * @return \Drupal\ys_core\Access\PlatformAdminAccessCheck
   *   The access check under test.
   */
  private function check(bool $is_platform_admin): PlatformAdminAccessCheck {
    $checker = $this->createMock(PlatformAdminCheckerInterface::class);
    $checker->method('isPlatformAdmin')->willReturn($is_platform_admin);

    return new PlatformAdminAccessCheck($checker);
  }

}
