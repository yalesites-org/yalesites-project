<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Access\BeaconAdminAccessCheck;

/**
 * Tests the Beacon administration form access check.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Access\BeaconAdminAccessCheck
 */
class BeaconAdminAccessCheckTest extends UnitTestCase {

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
    $result = (new BeaconAdminAccessCheck())->access($this->account(1));
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
    $result = (new BeaconAdminAccessCheck())->access($this->account(2));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * The anonymous user (uid 0) is forbidden.
   *
   * @covers ::access
   */
  public function testForbiddenForAnonymous(): void {
    $result = (new BeaconAdminAccessCheck())->access($this->account(0));
    $this->assertTrue($result->isForbidden());
  }

}
