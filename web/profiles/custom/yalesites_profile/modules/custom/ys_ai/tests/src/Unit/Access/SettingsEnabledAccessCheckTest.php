<?php

namespace Drupal\Tests\ys_ai\Unit\Access;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Access\SettingsEnabledAccessCheck;

/**
 * Unit tests for SettingsEnabledAccessCheck.
 *
 * Access is granted only when all three of the following hold: Azure is
 * configured on the chat settings, the account has the "configure ys ai
 * user settings" permission, and the ys_ai integration is turned on in
 * ys_integrations. Any one of those failing degrades to Drupal's neutral
 * access result (not an explicit forbid), matching AccessResult::allowedIf().
 *
 * @coversDefaultClass \Drupal\ys_ai\Access\SettingsEnabledAccessCheck
 *
 * @group ys_ai
 * @group yalesites
 */
class SettingsEnabledAccessCheckTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The access check under test.
   *
   * @var \Drupal\ys_ai\Access\SettingsEnabledAccessCheck
   */
  protected $accessCheck;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->accessCheck = new SettingsEnabledAccessCheck($this->configFactory);
  }

  /**
   * Creates a mock account with a given permission result.
   */
  protected function createMockAccount(bool $has_permission): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('configure ys ai user settings')
      ->willReturn($has_permission);
    return $account;
  }

  /**
   * Creates a mock chat config with a given azure_base_url value.
   */
  protected function createMockChatConfig($azure_base_url): ImmutableConfig {
    $config = $this->getMockBuilder(ImmutableConfig::class)
      ->disableOriginalConstructor()
      ->getMock();
    $config->method('get')
      ->with('azure_base_url')
      ->willReturn($azure_base_url);
    $config->method('getCacheTags')->willReturn(['config:ai_engine_chat.settings']);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(-1);
    return $config;
  }

  /**
   * Configures the config factory for the given chat and integration configs.
   *
   * Maps each mock to its respective config name.
   */
  protected function configureConfigFactory(ImmutableConfig $chat_config, $integration_enabled): void {
    $integration_config = $this->getMockBuilder(ImmutableConfig::class)
      ->disableOriginalConstructor()
      ->getMock();
    $integration_config->method('get')
      ->with('ys_ai')
      ->willReturn($integration_enabled);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_engine_chat.settings', $chat_config],
        ['ys_integrations.integration_settings', $integration_config],
      ]);
  }

  /**
   * Access is allowed when all three conditions are met.
   *
   * Azure is configured, permission is granted, and the integration is
   * enabled.
   *
   * @covers ::access
   */
  public function testAccessAllowedWhenAllConditionsMet(): void {
    $chat_config = $this->createMockChatConfig('https://example.azure.com');
    $this->configureConfigFactory($chat_config, TRUE);

    $result = $this->accessCheck->access($this->createMockAccount(TRUE));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Access is not allowed when Azure is not configured.
   *
   * @covers ::access
   */
  public function testAccessNotAllowedWhenAzureNotConfigured(): void {
    $chat_config = $this->createMockChatConfig(NULL);
    $this->configureConfigFactory($chat_config, TRUE);

    $result = $this->accessCheck->access($this->createMockAccount(TRUE));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Access is not allowed when the account lacks the permission.
   *
   * @covers ::access
   */
  public function testAccessNotAllowedWhenPermissionMissing(): void {
    $chat_config = $this->createMockChatConfig('https://example.azure.com');
    $this->configureConfigFactory($chat_config, TRUE);

    $result = $this->accessCheck->access($this->createMockAccount(FALSE));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Access is not allowed when the ys_ai integration is disabled.
   *
   * @covers ::access
   */
  public function testAccessNotAllowedWhenIntegrationDisabled(): void {
    $chat_config = $this->createMockChatConfig('https://example.azure.com');
    $this->configureConfigFactory($chat_config, FALSE);

    $result = $this->accessCheck->access($this->createMockAccount(TRUE));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Access is not allowed when the integration setting is entirely absent.
   *
   * Exercises the "?? FALSE" fallback rather than an explicit FALSE value.
   *
   * @covers ::access
   */
  public function testAccessNotAllowedWhenIntegrationSettingMissing(): void {
    $chat_config = $this->createMockChatConfig('https://example.azure.com');
    $this->configureConfigFactory($chat_config, NULL);

    $result = $this->accessCheck->access($this->createMockAccount(TRUE));

    $this->assertFalse($result->isAllowed());
  }

  /**
   * The chat config is added as a cacheable dependency regardless of outcome.
   *
   * @covers ::access
   */
  public function testAccessAddsChatConfigCacheTags(): void {
    $chat_config = $this->createMockChatConfig(NULL);
    $this->configureConfigFactory($chat_config, TRUE);

    $result = $this->accessCheck->access($this->createMockAccount(TRUE));

    $this->assertSame(['config:ai_engine_chat.settings'], $result->getCacheTags());
  }

  /**
   * @covers ::create
   */
  public function testCreateInstantiatesWithConfigFactory(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->with('config.factory')
      ->willReturn($this->configFactory);

    $instance = SettingsEnabledAccessCheck::create($container);

    $this->assertInstanceOf(SettingsEnabledAccessCheck::class, $instance);
  }

}
