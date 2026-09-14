<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\PlatformAdminChecker;
use Drupal\ys_core\PlatformAdminCheckerInterface;

/**
 * Tests the platform's single "is this a platform admin?" mechanism.
 *
 * This service is the one answer to that question
 * (yalesites-org/YaleSites-Internal#1560). Before it there were several
 * spellings - a procedural helper, a form-local helper, and two open-coded
 * role checks - which is how they drifted apart in the first place.
 *
 * The user 1 clause is deliberately written out rather than left to Drupal's
 * permission bypass. Since Drupal 10.3 that bypass lives in
 * SuperUserAccessPolicy behind the security.enable_super_user container
 * parameter, so hasPermission() alone would stop being true for user 1 the day
 * anyone hardens that parameter off. That guarantee covers callers of this
 * service only, not routes gated declaratively on the bare permission - see
 * PlatformAdminCheckerInterface.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\PlatformAdminChecker
 */
class PlatformAdminCheckerTest extends UnitTestCase {

  /**
   * User 1 is a platform admin even when the permission check says no.
   *
   * This is the case that survives security.enable_super_user being turned
   * off, and the reason this service exists rather than a bare
   * hasPermission() call at each site.
   *
   * @covers ::isPlatformAdmin
   */
  public function testUserOneIsPlatformAdminWithoutThePermission(): void {
    $checker = new PlatformAdminChecker($this->account(1, FALSE));

    $this->assertTrue($checker->isPlatformAdmin());
  }

  /**
   * Holding the permission makes an account a platform admin.
   *
   * @covers ::isPlatformAdmin
   */
  public function testAccountWithThePermissionIsPlatformAdmin(): void {
    $checker = new PlatformAdminChecker($this->account(42, TRUE));

    $this->assertTrue($checker->isPlatformAdmin());
  }

  /**
   * An ordinary account is not a platform admin.
   *
   * @covers ::isPlatformAdmin
   */
  public function testOrdinaryAccountIsNotPlatformAdmin(): void {
    $checker = new PlatformAdminChecker($this->account(42, FALSE));

    $this->assertFalse($checker->isPlatformAdmin());
  }

  /**
   * A passed non-admin is checked instead of an admin current user.
   *
   * Access checks and plugin code need to ask about somebody other than the
   * person making the request, so the argument has to win.
   *
   * @covers ::isPlatformAdmin
   */
  public function testExplicitAccountOverridesTheCurrentUser(): void {
    $checker = new PlatformAdminChecker($this->account(1, TRUE));

    $this->assertFalse($checker->isPlatformAdmin($this->account(42, FALSE)));
  }

  /**
   * A passed platform admin is recognised when the current user is not one.
   *
   * The mirror of the case above, and the direction real callers use: an
   * access check asking about somebody else while the requester is ordinary.
   *
   * @covers ::isPlatformAdmin
   */
  public function testExplicitPlatformAdminIsRecognisedForAnOrdinaryRequester(): void {
    $checker = new PlatformAdminChecker($this->account(42, FALSE));

    $this->assertTrue($checker->isPlatformAdmin($this->account(43, TRUE)));
  }

  /**
   * User 1 passed as the argument is recognised without the permission.
   *
   * The uid 1 clause has to work through the parameter too, not just for the
   * current user - this is the whole reason the service states it rather than
   * relying on Drupal's permission bypass.
   *
   * @covers ::isPlatformAdmin
   */
  public function testExplicitUserOneIsRecognisedWithoutThePermission(): void {
    $checker = new PlatformAdminChecker($this->account(42, FALSE));

    $this->assertTrue($checker->isPlatformAdmin($this->account(1, FALSE)));
  }

  /**
   * A string uid 1 still counts, since not every account returns an int.
   *
   * @covers ::isPlatformAdmin
   */
  public function testStringUserIdOneIsRecognised(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('1');
    $account->method('hasPermission')->willReturn(FALSE);

    $this->assertTrue((new PlatformAdminChecker($account))->isPlatformAdmin());
  }

  /**
   * The constant is the permission the module's own YAML declares and requires.
   *
   * Neither YAML file can reference a PHP constant, so this reads them both
   * rather than restating the literal - a rename in one place alone silently
   * unhooks the route from the gate, and that is the failure this pins.
   */
  public function testThePermissionStringIsTheOneDeclaredInYaml(): void {
    $module = dirname(__DIR__, 3);

    $permissions = Yaml::decode(
      file_get_contents($module . '/ys_core.permissions.yml')
    );
    $this->assertArrayHasKey(
      PlatformAdminCheckerInterface::PERMISSION,
      $permissions,
      'ys_core.permissions.yml must declare the permission the checker uses.'
    );

    $routes = Yaml::decode(
      file_get_contents($module . '/ys_core.routing.yml')
    );
    $this->assertSame(
      PlatformAdminCheckerInterface::PERMISSION,
      $routes['ys_core.platform_admin_settings']['requirements']['_permission'],
      'The Platform Admin Settings route must require that same permission.'
    );
  }

  /**
   * Builds an account that reports the given uid and permission answer.
   *
   * @param int $uid
   *   The user id the account reports.
   * @param bool $has_permission
   *   Whether the account holds the platform admin permission.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The mocked account.
   */
  private function account(int $uid, bool $has_permission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')
      ->with(PlatformAdminCheckerInterface::PERMISSION)
      ->willReturn($has_permission);

    return $account;
  }

}
