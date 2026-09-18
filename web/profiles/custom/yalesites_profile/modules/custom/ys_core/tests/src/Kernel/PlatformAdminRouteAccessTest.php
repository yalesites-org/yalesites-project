<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

/**
 * Tests who can reach the Platform Admin Settings route.
 *
 * The route used to be gated on a bare `_permission` requirement and is now
 * gated on an access check delegating to ys_core.platform_admin_checker
 * (yalesites-org/YaleSites-Internal#1695). The point of that swap is that it
 * changes nothing today, so this class asserts the real answer for real
 * accounts through the access manager - the same mechanism a request uses -
 * rather than inspecting the route definition.
 *
 * This class runs with the super-user access policy ON, which is the platform's
 * actual configuration (`security.enable_super_user` is core's default and
 * nothing in web/sites overrides it). The companion class
 * \Drupal\Tests\ys_core\Kernel\PlatformAdminRouteWithoutSuperUserTest covers
 * the other setting, where the old and new gates genuinely disagree.
 *
 * @group ys_core
 * @group yalesites
 */
class PlatformAdminRouteAccessTest extends YsKernelTestBase {

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
   * Keep user 1 a super user - this is the platform's real configuration.
   *
   * KernelTestBase would infer TRUE for a test outside core anyway; it is
   * stated so the contrast with the companion class is legible here.
   *
   * {@inheritdoc}
   */
  protected bool $usesSuperUserAccessPolicy = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
  }

  /**
   * User 1 can reach the route.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testUserOneHasAccess(): void {
    $this->assertTrue($this->hasAccess($this->userOne()));
  }

  /**
   * An account holding only the platform admin permission can reach the route.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testPermissionHolderHasAccess(): void {
    $this->userOne();

    $this->assertTrue($this->hasAccess(
      $this->createUser(['administer platform admin settings'])
    ));
  }

  /**
   * An account with neither uid 1 nor the permission cannot reach the route.
   *
   * 'yalesites manage settings' is the permission ordinary site admins hold,
   * so this is the realistic denial case rather than a bare account.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testSiteAdminWithoutThePermissionHasNoAccess(): void {
    $this->userOne();

    $this->assertFalse($this->hasAccess(
      $this->createUser(['yalesites manage settings'])
    ));
  }

  /**
   * The anonymous user cannot reach the route.
   *
   * @covers \Drupal\ys_core\Access\PlatformAdminAccessCheck::access
   */
  public function testAnonymousHasNoAccess(): void {
    $this->assertFalse($this->hasAccess(User::getAnonymousUser()));
  }

  /**
   * Returns user 1, asserting that it really is uid 1.
   *
   * The first createUser() call in a kernel test takes uid 1, but the whole
   * point of these assertions is the uid-1 clause, so a silent drift to uid 2
   * would leave the suite green while testing nothing.
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
