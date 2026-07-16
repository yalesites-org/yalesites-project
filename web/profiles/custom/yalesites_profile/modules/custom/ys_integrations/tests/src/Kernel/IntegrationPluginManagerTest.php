<?php

namespace Drupal\Tests\ys_integrations\Kernel;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\IntegrationPluginInterface;
use Drupal\ys_integrations\IntegrationPluginManager;

/**
 * Tests discovery and instantiation via the IntegrationPluginManager.
 *
 * A test-only module (ys_integrations_test) supplies a known integration
 * plugin so discovery can be asserted without depending on the other custom
 * modules that ship real integrations.
 *
 * @coversDefaultClass \Drupal\ys_integrations\IntegrationPluginManager
 *
 * @group ys_integrations
 * @group yalesites
 */
class IntegrationPluginManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ys_integrations',
    'ys_integrations_test',
  ];

  /**
   * The integration plugin manager.
   *
   * @var \Drupal\ys_integrations\IntegrationPluginManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('ys_integrations.integration_plugin_manager');
  }

  /**
   * The manager service is registered and is a default plugin manager.
   */
  public function testManagerServiceExists(): void {
    $this->assertInstanceOf(IntegrationPluginManager::class, $this->manager);
    $this->assertInstanceOf(DefaultPluginManager::class, $this->manager);
  }

  /**
   * Attribute discovery finds the test integration plugin.
   *
   * @covers ::getDefinitions
   */
  public function testDiscoversTestIntegrationPlugin(): void {
    $definitions = $this->manager->getDefinitions();

    $this->assertIsArray($definitions);
    $this->assertArrayHasKey('ys_integrations_test', $definitions);

    $definition = $definitions['ys_integrations_test'];
    $this->assertSame('ys_integrations_test', $definition['id']);
    $this->assertSame('ys_integrations_test', $definition['provider']);
    $this->assertSame('Drupal\ys_integrations_test\Plugin\ys_integrations\TestIntegrationPlugin', $definition['class']);
    $this->assertSame('Test Integration', (string) $definition['label']);
  }

  /**
   * HasDefinition() reports the known plugin and rejects an unknown id.
   *
   * @covers ::hasDefinition
   */
  public function testHasDefinition(): void {
    $this->assertTrue($this->manager->hasDefinition('ys_integrations_test'));
    $this->assertFalse($this->manager->hasDefinition('does_not_exist'));
  }

  /**
   * CreateInstance() returns an integration plugin instance.
   *
   * @covers ::createInstance
   */
  public function testCreateInstanceReturnsIntegrationPlugin(): void {
    $plugin = $this->manager->createInstance('ys_integrations_test');

    $this->assertInstanceOf(IntegrationPluginInterface::class, $plugin);
    $this->assertInstanceOf(IntegrationPluginBase::class, $plugin);
    // The test plugin inherits the base defaults.
    $this->assertFalse($plugin->isTurnedOn());
    $this->assertSame([], $plugin->build());
  }

}
