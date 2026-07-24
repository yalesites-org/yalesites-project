<?php

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the AI Tester integration plugin.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin
 *
 * @group ys_beacon
 */
class AiTesterIntegrationPluginTest extends UnitTestCase {

  const PLUGIN_DEFINITION = [
    'id' => 'ys_ai_tester',
    'label' => 'AI Tester',
    'description' => 'AI Tester description.',
  ];

  /**
   * Builds the plugin with a config factory stub and a mocked current user.
   *
   * @param array $beacon_settings
   *   Values to return for ys_beacon.settings.
   *
   * @return \Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin
   *   The plugin under test.
   */
  protected function buildPlugin(array $beacon_settings): AiTesterIntegrationPlugin {
    $config_factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => $beacon_settings,
    ]);
    $current_user = $this->createMock(AccountInterface::class);
    return new AiTesterIntegrationPlugin($config_factory, self::PLUGIN_DEFINITION, $current_user);
  }

  /**
   * Sets up Drupal's container so Url::fromRoute() and ::access() can run.
   */
  protected function setUpUrlContainer(): void {
    $container = new ContainerBuilder();
    $url_generator = $this->createMock('Drupal\Core\Routing\UrlGeneratorInterface');
    $url_generator->method('generateFromRoute')->willReturn('/admin/config/yalesites/ys-beacon/tester');
    $access_manager = $this->createMock('Drupal\Core\Access\AccessManagerInterface');
    $access_manager->method('checkNamedRoute')->willReturn(TRUE);
    $container->set('url_generator', $url_generator);
    $container->set('access_manager', $access_manager);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnWhenChatEnabled(): void {
    $this->assertTrue($this->buildPlugin(['enable_chat' => TRUE])->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenChatDisabled(): void {
    // An index name alone no longer turns the tester on; chat must be enabled.
    $this->assertFalse($this->buildPlugin(['azure_index_name' => 'site-live'])->isTurnedOn());
    $this->assertFalse($this->buildPlugin(['azure_index_name' => '', 'enable_chat' => FALSE])->isTurnedOn());
  }

  /**
   * @covers ::configUrl
   */
  public function testConfigUrlPointsAtTesterRoute(): void {
    $this->setUpUrlContainer();
    $url = $this->buildPlugin(['azure_index_name' => 'site-live'])->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_ai_tester.tester', $url->getRouteName());
  }

  /**
   * @covers ::syncUrl
   */
  public function testSyncUrlIsNull(): void {
    $this->assertNull($this->buildPlugin(['azure_index_name' => 'site-live'])->syncUrl());
  }

  /**
   * @covers ::build
   */
  public function testBuildIncludesConfigureLinkWhenTurnedOn(): void {
    $this->setUpUrlContainer();
    $form = $this->buildPlugin(['enable_chat' => TRUE])->build();
    $this->assertArrayHasKey('configure', $form['#actions']);
    $this->assertArrayNotHasKey('not_configured', $form['#actions']);
    $this->assertSame('link', $form['#actions']['configure']['#type']);
  }

  /**
   * @covers ::build
   */
  public function testBuildShowsNotConfiguredWhenUnconfigured(): void {
    $this->setUpUrlContainer();
    $form = $this->buildPlugin(['azure_index_name' => '', 'enable_chat' => FALSE])->build();
    $this->assertArrayHasKey('not_configured', $form['#actions']);
    $this->assertArrayNotHasKey('configure', $form['#actions']);
  }

  /**
   * @covers ::save
   */
  public function testSaveIsNoop(): void {
    $this->assertNull($this->buildPlugin(['azure_index_name' => 'site-live'])->save([], []));
  }

}
