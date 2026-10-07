<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Access\BeaconSuperadminOnlyAccessCheck;

/**
 * Tests the Beacon administration form's superadmin-only access check.
 *
 * This check is deliberately stricter than the platform's general
 * platform-admin gate, so its name says so
 * (yalesites-org/YaleSites-Internal#1695) - the distinction used to live only
 * in a docblock.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Access\BeaconSuperadminOnlyAccessCheck
 */
class BeaconSuperadminOnlyAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The access result adds the 'user' cache context via cachePerUser(), which
    // validates the token against the cache_contexts_manager service.
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $this->createMock(CacheContextsManager::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds an account stub with the given uid that grants every permission.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturn(TRUE);
    return $account;
  }

  /**
   * User 1 is allowed, so the route resolves to 200 for the superadmin.
   *
   * @covers ::access
   */
  public function testAllowedForUserOne(): void {
    $result = (new BeaconSuperadminOnlyAccessCheck())->access($this->account(1));
    $this->assertTrue($result->isAllowed());
  }

  /**
   * A non-user-1 account is forbidden even when it holds every permission.
   *
   * A privileged admin therefore receives 403 on the route: the forbid is
   * explicit (not neutral) so it cannot be overridden by another access check.
   *
   * @covers ::access
   */
  public function testForbiddenForPrivilegedNonUserOne(): void {
    $result = (new BeaconSuperadminOnlyAccessCheck())->access($this->account(2));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * The anonymous user (uid 0) is forbidden.
   *
   * @covers ::access
   */
  public function testForbiddenForAnonymous(): void {
    $result = (new BeaconSuperadminOnlyAccessCheck())->access($this->account(0));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * The route and the service tag still name the same requirement.
   *
   * YAML cannot reference the constant, so ys_beacon.routing.yml and the
   * access_check tag in ys_beacon.services.yml both repeat it. Renaming one
   * alone leaves the route with no resolved access check, and AccessManager
   * treats that as neutral - so the administration form would 403 for user 1
   * as well. Pinned here because renaming this check is exactly when the two
   * can drift apart.
   */
  public function testTheRouteAndTheServiceTagAgreeOnTheRequirement(): void {
    $module = dirname(__DIR__, 3);

    $routes = Yaml::decode(file_get_contents($module . '/ys_beacon.routing.yml'));
    $this->assertArrayHasKey(
      BeaconSuperadminOnlyAccessCheck::REQUIREMENT,
      $routes['ys_beacon.admin_settings']['requirements'],
      'The Beacon administration route must be gated on this access check.'
    );

    $services = Yaml::decode(file_get_contents($module . '/ys_beacon.services.yml'));
    $this->assertSame(
      [['name' => 'access_check', 'applies_to' => BeaconSuperadminOnlyAccessCheck::REQUIREMENT]],
      $services['services']['ys_beacon.superadmin_only_access_check']['tags'],
      'The service tag must advertise the same requirement name the route uses.'
    );
  }

}
