<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_system_instructions\Access\SystemInstructionsAccessCheck;

/**
 * Unit tests for SystemInstructionsAccessCheck.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Access\SystemInstructionsAccessCheck
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class SystemInstructionsAccessCheckTest extends UnitTestCase {

  /**
   * Config values for 'ys_integrations.integration_settings', keyed by name.
   *
   * @var array
   */
  protected $integrationSettings = ['ys_ai_system_instructions' => TRUE];

  /**
   * Config values for 'ys_ai_system_instructions.settings', keyed by name.
   *
   * @var array
   */
  protected $instructionsSettings = [
    'system_instructions_enabled' => TRUE,
    'system_instructions_api_endpoint' => 'https://api.example.com',
    'system_instructions_web_app_name' => 'test-app',
    'system_instructions_api_key' => 'test_key_id',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // access()'s allowed path builds an AccessResult whose cache contexts are
    // validated via the cache_contexts_manager service.
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the access check under test with mocked configuration.
   */
  protected function createAccessCheck(): SystemInstructionsAccessCheck {
    $integration_config = $this->createMock(ImmutableConfig::class);
    $integration_config->method('get')->willReturnCallback(
      fn ($key) => $this->integrationSettings[$key] ?? NULL
    );

    $instructions_config = $this->createMock(ImmutableConfig::class);
    $instructions_config->method('get')->willReturnCallback(
      fn ($key) => $this->instructionsSettings[$key] ?? NULL
    );

    // access() adds these configs as cacheable dependencies, which reads their
    // cache metadata; stub it so AccessResult never receives NULL contexts.
    foreach ([$integration_config, $instructions_config] as $config) {
      $config->method('getCacheContexts')->willReturn([]);
      $config->method('getCacheTags')->willReturn([]);
      $config->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    }

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnMap([
      ['ys_integrations.integration_settings', $integration_config],
      ['ys_ai_system_instructions.settings', $instructions_config],
    ]);

    return new SystemInstructionsAccessCheck($config_factory);
  }

  /**
   * Builds a mocked account with the given permission granted or not.
   */
  protected function createAccount(bool $has_permission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('manage ys ai system instructions')
      ->willReturn($has_permission);
    return $account;
  }

  /**
   * Tests access() is forbidden when the user lacks the permission.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWithoutPermission(): void {
    $result = $this->createAccessCheck()->access($this->createAccount(FALSE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests access() is forbidden when the integration is not enabled.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenIntegrationDisabled(): void {
    $this->integrationSettings['ys_ai_system_instructions'] = FALSE;

    $result = $this->createAccessCheck()->access($this->createAccount(TRUE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests access() is forbidden when the feature itself is not enabled.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenFeatureDisabled(): void {
    $this->instructionsSettings['system_instructions_enabled'] = FALSE;

    $result = $this->createAccessCheck()->access($this->createAccount(TRUE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests access() is forbidden when API configuration is incomplete.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenApiConfigurationIncomplete(): void {
    $this->instructionsSettings['system_instructions_api_key'] = '';

    $result = $this->createAccessCheck()->access($this->createAccount(TRUE));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests access() is allowed when permission, integration, and API are set.
   *
   * @covers ::access
   */
  public function testAccessAllowedWhenFullyConfigured(): void {
    $result = $this->createAccessCheck()->access($this->createAccount(TRUE));

    $this->assertTrue($result->isAllowed());
  }

}
