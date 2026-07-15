<?php

namespace Drupal\Tests\ys_ai\Unit\Plugin\ys_integrations;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Plugin\ys_integrations\AiIntegrationPlugin;

/**
 * Unit tests for AiIntegrationPlugin.
 *
 * @coversDefaultClass \Drupal\ys_ai\Plugin\ys_integrations\AiIntegrationPlugin
 *
 * @group ys_ai
 * @group yalesites
 */
class AiIntegrationPluginTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The current user mock.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The access manager mock, registered in the container for Url::access().
   *
   * @var \Drupal\Core\Access\AccessManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $accessManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);

    // Url::access() reaches for \Drupal::service('access_manager') when the
    // service isn't injected into the Url object directly, so a minimal
    // container is needed to exercise build().
    $this->accessManager = $this->createMock(AccessManagerInterface::class);
    $container = new ContainerBuilder();
    $container->set('access_manager', $this->accessManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Creates a mock ai_engine_chat.settings config with a given azure_base_url.
   */
  protected function mockChatConfig($azure_base_url): ImmutableConfig {
    $config = $this->getMockBuilder(ImmutableConfig::class)
      ->disableOriginalConstructor()
      ->getMock();
    $config->method('get')
      ->with('azure_base_url')
      ->willReturn($azure_base_url);
    return $config;
  }

  /**
   * Builds the plugin under test with the given plugin definition.
   */
  protected function createPlugin(array $plugin_definition = []): AiIntegrationPlugin {
    $plugin_definition += [
      'label' => 'AI',
      'description' => 'Provides integration with the AI engine.',
    ];
    $plugin = new AiIntegrationPlugin($this->configFactory, $plugin_definition, $this->currentUser);
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * IsTurnedOn() is true when azure_base_url is set on the chat config.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnWhenAzureConfigured(): void {
    $this->configFactory->method('get')
      ->with('ai_engine_chat.settings')
      ->willReturn($this->mockChatConfig('https://example.azure.com'));

    $this->assertTrue($this->createPlugin()->isTurnedOn());
  }

  /**
   * IsTurnedOn() is false when azure_base_url is empty.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnWhenAzureNotConfigured(): void {
    $this->configFactory->method('get')
      ->with('ai_engine_chat.settings')
      ->willReturn($this->mockChatConfig(NULL));

    $this->assertFalse($this->createPlugin()->isTurnedOn());
  }

  /**
   * ConfigUrl() points at the ys_ai settings route.
   *
   * @covers ::configUrl
   */
  public function testConfigUrlPointsToYsAiSettings(): void {
    $url = $this->createPlugin()->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_ai.settings', $url->getRouteName());
  }

  /**
   * SyncUrl() has no sync route for this integration.
   *
   * @covers ::syncUrl
   */
  public function testSyncUrlIsNull(): void {
    $this->assertNull($this->createPlugin()->syncUrl());
  }

  /**
   * Build() offers a "Configure" action when the integration is turned on.
   *
   * The action is gated on route access.
   *
   * @covers ::build
   */
  public function testBuildOffersConfigureActionWhenTurnedOn(): void {
    $this->configFactory->method('get')
      ->with('ai_engine_chat.settings')
      ->willReturn($this->mockChatConfig('https://example.azure.com'));
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);

    $build = $this->createPlugin()->build();

    $this->assertSame('AI', (string) $build['title']);
    $this->assertArrayHasKey('configure', $build['#actions']);
    $this->assertTrue($build['#actions']['configure']['#access']);
    $this->assertArrayNotHasKey('not_configured', $build['#actions']);
  }

  /**
   * Build() shows a "not configured" message when the integration is off.
   *
   * @covers ::build
   */
  public function testBuildShowsNotConfiguredWhenTurnedOff(): void {
    $this->configFactory->method('get')
      ->with('ai_engine_chat.settings')
      ->willReturn($this->mockChatConfig(NULL));
    $this->accessManager->method('checkNamedRoute')->willReturn(FALSE);

    $build = $this->createPlugin()->build();

    $this->assertArrayNotHasKey('configure', $build['#actions']);
    $this->assertStringContainsString('not configured', (string) $build['#actions']['not_configured']['#markup']);
  }

  /**
   * Save() is a no-op and returns nothing.
   *
   * @covers ::save
   */
  public function testSaveIsNoOp(): void {
    $this->assertNull($this->createPlugin()->save([], NULL));
  }

}
