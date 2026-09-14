<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Access\SystemInstructionsAccessCheck;

/**
 * Tests access to the Beacon system instructions screens.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Access\SystemInstructionsAccessCheck
 */
class SystemInstructionsAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The access result adds the 'user.permissions' cache context via
    // cachePerPermissions(), which validates the token against the
    // cache_contexts_manager service.
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $this->createMock(CacheContextsManager::class));
    \Drupal::setContainer($container);
  }

  /**
   * Builds the access check with the Beacon integration toggle set.
   *
   * @param bool $integrationEnabled
   *   Whether ys_integrations.integration_settings:ys_beacon is on.
   */
  private function check(bool $integrationEnabled): SystemInstructionsAccessCheck {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('ys_beacon')->willReturn($integrationEnabled);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheTags')->willReturn(['config:ys_integrations.integration_settings']);
    $config->method('getCacheMaxAge')->willReturn(-1);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ys_integrations.integration_settings')
      ->willReturn($config);

    return new SystemInstructionsAccessCheck($configFactory);
  }

  /**
   * Builds an account stub with or without the required permission.
   */
  private function account(bool $hasPermission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('manage ys beacon system instructions')
      ->willReturn($hasPermission);
    return $account;
  }

  /**
   * Forbidden without the manage-instructions permission.
   *
   * @covers ::access
   */
  public function testForbiddenWithoutPermission(): void {
    $result = $this->check(TRUE)->access($this->account(FALSE));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Forbidden with the permission but the Beacon integration disabled.
   *
   * @covers ::access
   */
  public function testForbiddenWhenIntegrationDisabled(): void {
    $result = $this->check(FALSE)->access($this->account(TRUE));
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Allowed with the permission and the Beacon integration enabled.
   *
   * @covers ::access
   */
  public function testAllowedWhenPermittedAndEnabled(): void {
    $result = $this->check(TRUE)->access($this->account(TRUE));
    $this->assertTrue($result->isAllowed());
  }

}
