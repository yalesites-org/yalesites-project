<?php

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin
 *
 * @group yalesites
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
   * @param string|null $azure_base_url
   *   Value to return for ai_engine_chat.settings:azure_base_url.
   *
   * @return \Drupal\ys_ai_tester\Plugin\ys_integrations\AiTesterIntegrationPlugin
   *   The plugin under test.
   */
  protected function buildPlugin($azure_base_url): AiTesterIntegrationPlugin {
    $config_factory = $this->getConfigFactoryStub([
      'ai_engine_chat.settings' => ['azure_base_url' => $azure_base_url],
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
    $url_generator->method('generateFromRoute')->willReturn('/admin/config/yalesites/ys_ai/tester');
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
  public function testIsTurnedOnWhenAzureConfigured(): void {
    $plugin = $this->buildPlugin('https://example.openai.azure.com');
    $this->assertTrue($plugin->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenAzureMissing(): void {
    $plugin = $this->buildPlugin(NULL);
    $this->assertFalse($plugin->isTurnedOn());
  }

  /**
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenAzureEmpty(): void {
    $plugin = $this->buildPlugin('');
    $this->assertFalse($plugin->isTurnedOn());
  }

  /**
   * @covers ::configUrl
   */
  public function testConfigUrlPointsAtTesterRoute(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin('https://example.openai.azure.com');
    $url = $plugin->configUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('ys_ai.tester', $url->getRouteName());
  }

  /**
   * @covers ::syncUrl
   */
  public function testSyncUrlIsNull(): void {
    $plugin = $this->buildPlugin('https://example.openai.azure.com');
    $this->assertNull($plugin->syncUrl());
  }

  /**
   * @covers ::build
   */
  public function testBuildIncludesConfigureLinkWhenTurnedOn(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin('https://example.openai.azure.com');
    $form = $plugin->build();
    $this->assertArrayHasKey('configure', $form['#actions']);
    $this->assertArrayNotHasKey('not_configured', $form['#actions']);
    $this->assertSame('link', $form['#actions']['configure']['#type']);
  }

  /**
   * @covers ::build
   */
  public function testBuildShowsNotConfiguredWhenAzureMissing(): void {
    $this->setUpUrlContainer();
    $plugin = $this->buildPlugin(NULL);
    $form = $plugin->build();
    $this->assertArrayHasKey('not_configured', $form['#actions']);
    $this->assertArrayNotHasKey('configure', $form['#actions']);
  }

  /**
   * @covers ::save
   */
  public function testSaveIsNoop(): void {
    $plugin = $this->buildPlugin('https://example.openai.azure.com');
    $this->assertNull($plugin->save([], []));
  }

}
