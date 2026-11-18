<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the Platform Admin Settings route with the super-user policy off.
 *
 * This is the one configuration in which the old gate and the new gate
 * disagree, and therefore the only place the fix for
 * yalesites-org/YaleSites-Internal#1695 is observable.
 *
 * Since Drupal 10.3 user 1's permission bypass lives in SuperUserAccessPolicy
 * behind the `security.enable_super_user` container parameter. With it off,
 * user 1 holds no permission it was not explicitly granted. The platform admin
 * service states the uid-1 clause itself and so still reports TRUE, but a
 * route gated on the bare permission does not consult the service and refused
 * user 1 - the service and the route it guards contradicting each other. Now
 * that the route delegates to the service, the two agree again.
 *
 * The parameter is not turned off on the platform today - it is core's default
 * of TRUE and nothing in web/sites overrides it - so this is a latent gap
 * rather than a live outage. It is worth a test precisely because hardening
 * that parameter off is a plausible future change, and the failure it would
 * have produced - the platform superadmin locked out of the platform admin
 * settings page - is both severe and hard to attribute after the fact.
 *
 * @group ys_core
 * @group yalesites
 */
class PlatformAdminRouteWithoutSuperUserTest extends YsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ys_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Take away user 1's permission bypass.
   *
   * {@inheritdoc}
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
  }

  /**
   * The premise: without the bypass, user 1 does not hold the permission.
   *
   * Asserted rather than assumed, because every other assertion in this class
   * is meaningless if the bypass is still in effect - they would all pass for
   * the wrong reason.
   */
  public function testUserOneDoesNotHoldThePermissionWithoutTheBypass(): void {
    $this->assertFalse(
      $this->userOne()->hasPermission('administer platform admin settings')
    );
  }

  /**
   * User 1 still reaches the route, because the gate now asks the service.
   *
   * This is the regression this ticket fixes: before the route delegated to
   * ys_core.platform_admin_checker, this returned FALSE while
   * isPlatformAdmin() returned TRUE for the same account.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testUserOneStillHasAccessWithoutTheBypass(): void {
    $account = $this->userOne();

    $this->assertTrue(
      $this->container->get('ys_core.platform_admin_checker')->isPlatformAdmin($account),
      'The service must report user 1 as a platform admin regardless of the bypass.'
    );
    $this->assertTrue(
      $this->hasAccess($account),
      'The route must agree with the service it is supposed to delegate to.'
    );
  }

  /**
   * A permission holder that is not user 1 still reaches the route.
   *
   * The uid-1 clause must not have become the only way in.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testPermissionHolderStillHasAccessWithoutTheBypass(): void {
    $this->userOne();

    $this->assertTrue($this->hasAccess(
      $this->createUser(['administer platform admin settings'])
    ));
  }

  /**
   * An account with neither uid 1 nor the permission is still refused.
   *
   * Nobody gains access from this change.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testOrdinaryAccountStillHasNoAccessWithoutTheBypass(): void {
    $this->userOne();

    $this->assertFalse($this->hasAccess(
      $this->createUser(['yalesites manage settings'])
    ));
  }

  /**
   * Returns user 1, asserting that it really is uid 1.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account for user 1.
   */
  private function userOne(): AccountInterface {
    $account = $this->createUser();
    $this->assertSame(1, (int) $account->id(), 'Expected the first created user to be uid 1.');

    return $account;
  }

  /**
   * Checks access to the Platform Admin Settings route for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return bool
   *   TRUE if the account can reach the route.
   */
  private function hasAccess(AccountInterface $account): bool {
    return $this->container->get('access_manager')
      ->checkNamedRoute('ys_core.platform_admin_settings', [], $account);
  }

}
