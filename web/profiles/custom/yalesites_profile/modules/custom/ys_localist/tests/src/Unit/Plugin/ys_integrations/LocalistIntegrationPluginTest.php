<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\ys_integrations;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_localist\Plugin\ys_integrations\LocalistIntegrationPlugin;

/**
 * Unit tests for the Localist integration plugin.
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\ys_integrations\LocalistIntegrationPlugin
 *
 * @group ys_localist
 * @group yalesites
 */
class LocalistIntegrationPluginTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The mocked config object returned for 'ys_localist.settings'.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The mocked access manager, used to answer Url::access() calls.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $accessManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('ys_localist.settings')
      ->willReturn($this->config);

    $this->accessManager = $this->createMock(AccessManagerInterface::class);
    $container = new ContainerBuilder();
    $container->set('access_manager', $this->accessManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the plugin under test with the given current user.
   */
  protected function createPlugin(?AccountInterface $current_user = NULL): LocalistIntegrationPlugin {
    $current_user = $current_user ?? $this->createMock(AccountInterface::class);
    $plugin_definition = [
      'id' => 'ys_localist',
      'label' => 'Localist',
      'description' => 'Provides integration with the Localist API.',
    ];
    $plugin = new LocalistIntegrationPlugin(
      $this->configFactory,
      $plugin_definition,
      $current_user
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnReturnsTrueWhenSyncEnabled(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(TRUE);
    $this->assertTrue($this->createPlugin()->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnReturnsFalseWhenConfigValueMissing(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(NULL);
    $this->assertFalse($this->createPlugin()->isTurnedOn());
  }

  /**
   * @covers ::configUrl
   */
  public function testConfigUrlPointsToSettingsRoute(): void {
    $url = $this->createPlugin()->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_localist.settings', $url->getRouteName());
  }

  /**
   * @covers ::syncUrl
   */
  public function testSyncUrlPointsToRunMigrationsRoute(): void {
    $url = $this->createPlugin()->syncUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_localist.run_migrations', $url->getRouteName());
  }

  /**
   * @covers ::build
   */
  public function testBuildIncludesSyncActionWhenTurnedOn(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(TRUE);
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);

    $form = $this->createPlugin()->build();

    $this->assertArrayHasKey('configure', $form['#actions']);
    $this->assertArrayHasKey('sync', $form['#actions']);
    $this->assertArrayNotHasKey('not_configured', $form['#actions']);
  }

  /**
   * @covers ::build
   */
  public function testBuildShowsNotConfiguredMessageWhenTurnedOff(): void {
    $this->config->method('get')->with('enable_localist_sync')->willReturn(FALSE);
    $this->accessManager->method('checkNamedRoute')->willReturn(TRUE);

    $form = $this->createPlugin()->build();

    $this->assertArrayHasKey('configure', $form['#actions']);
    $this->assertArrayNotHasKey('sync', $form['#actions']);
    $this->assertArrayHasKey('not_configured', $form['#actions']);
  }

  /**
   * @covers ::save
   */
  public function testSaveIsNoOp(): void {
    $form_state = $this->createMock(FormStateInterface::class);
    $form = [];
    $this->assertNull($this->createPlugin()->save($form, $form_state));
  }

}
