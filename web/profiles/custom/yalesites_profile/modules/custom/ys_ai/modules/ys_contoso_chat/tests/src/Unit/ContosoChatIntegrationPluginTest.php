<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_contoso_chat\Plugin\ys_integrations\ContosoChatIntegrationPlugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\ys_contoso_chat\Plugin\ys_integrations\ContosoChatIntegrationPlugin
 *
 * @group yalesites
 */
class ContosoChatIntegrationPluginTest extends UnitTestCase {

  const PLUGIN_DEFINITION = [
    'id' => 'ys_contoso_chat',
    'label' => 'Yale Chat',
    'description' => 'Yale Chat description.',
  ];

  /**
   * Builds the plugin with a config factory stub and a mocked current user.
   *
   * @param bool|null $enable
   *   Value to return for ys_contoso_chat.settings:enable.
   *
   * @return \Drupal\ys_contoso_chat\Plugin\ys_integrations\ContosoChatIntegrationPlugin
   *   The plugin under test.
   */
  protected function buildPlugin($enable): ContosoChatIntegrationPlugin {
    $config_factory = $this->getConfigFactoryStub([
      'ys_contoso_chat.settings' => ['enable' => $enable],
    ]);
    $current_user = $this->createMock(AccountInterface::class);
    return new ContosoChatIntegrationPlugin($config_factory, self::PLUGIN_DEFINITION, $current_user);
  }

  /**
   * Sets up Drupal's container so Url::fromRoute() and ::access() can run.
   */
  protected function setUpUrlContainer(): void {
    $container = new ContainerBuilder();
    $url_generator = $this->createMock('Drupal\Core\Routing\UrlGeneratorInterface');
    $url_generator->method('generateFromRoute')->willReturn('/admin/config/yale-chat/settings');
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
  public function testIsTurnedOnWhenEnabled(): void {
    $plugin = $this->buildPlugin(TRUE);
    $this->assertTrue($plugin->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenDisabled(): void {
    $plugin = $this->buildPlugin(FALSE);
    $this->assertFalse($plugin->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenUnset(): void {
    $plugin = $this->buildPlugin(NULL);
    $this->assertFalse($plugin->isTurnedOn());
  }

  /**
   * @covers ::configUrl
   */
  public function testConfigUrlPointsAtSettingsRoute(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin(TRUE);
    $url = $plugin->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_contoso_chat.settings', $url->getRouteName());
  }

  /**
   * @covers ::syncUrl
   */
  public function testSyncUrlIsNull(): void {
    $plugin = $this->buildPlugin(TRUE);
    $this->assertNull($plugin->syncUrl());
  }

  /**
   * @covers ::build
   */
  public function testBuildWhenEnabledShowsConfigureOnly(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin(TRUE);
    $form = $plugin->build();
    $this->assertArrayHasKey('configure', $form['#actions']);
    $this->assertArrayNotHasKey('not_configured', $form['#actions']);
    $this->assertSame('link', $form['#actions']['configure']['#type']);
    $this->assertSame('Yale Chat', (string) $form['title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildWhenDisabledShowsNotEnabledNotice(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin(FALSE);
    $form = $plugin->build();
    // The Configure link is always offered so admins can enable the widget.
    $this->assertArrayHasKey('configure', $form['#actions']);
    // When the widget is off, a "not enabled" notice is also shown.
    $this->assertArrayHasKey('not_configured', $form['#actions']);
  }

  /**
   * @covers ::save
   */
  public function testSaveIsNoop(): void {
    $plugin = $this->buildPlugin(TRUE);
    $this->assertNull($plugin->save([], []));
  }

}
