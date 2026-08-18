<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Form\DashboardSettingsForm;
use Drupal\ys_core\PlatformAdminSettingInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests how the dashboard settings form decides who owns the source section.
 *
 * The announcements source section stays on this form, but its gate no longer
 * matches on the platform_admin role machine name: it now asks for the
 * `administer platform admin settings` permission, the single mechanism the
 * platform uses for this (yalesites-org/YaleSites-Internal#1560). User 1 still
 * passes, via Drupal's permission bypass rather than a hardcoded uid check.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Form\DashboardSettingsForm
 */
class DashboardSettingsFormTest extends UnitTestCase {

  /**
   * Ownership of the source section follows the platform admin permission.
   *
   * @dataProvider platformAdminPermissionProvider
   *
   * @covers ::isPlatformAdmin
   */
  public function testIsPlatformAdminReflectsPermission(bool $has_permission): void {
    $this->assertSame($has_permission, $this->isPlatformAdmin($has_permission));
  }

  /**
   * Whether the account holds the platform admin permission.
   *
   * @return array[]
   *   Test cases, each a single boolean.
   */
  public function platformAdminPermissionProvider(): array {
    return [
      'platform admin' => [TRUE],
      'site admin' => [FALSE],
    ];
  }

  /**
   * Runs the form's gate against an account with or without the permission.
   */
  private function isPlatformAdmin(bool $has_permission): bool {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with(PlatformAdminSettingInterface::PERMISSION)
      ->willReturn($has_permission);

    // FormBase::currentUser() resolves through the container.
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $form_object = (new \ReflectionClass(DashboardSettingsForm::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(DashboardSettingsForm::class, 'isPlatformAdmin');
    $method->setAccessible(TRUE);

    return $method->invoke($form_object);
  }

}
