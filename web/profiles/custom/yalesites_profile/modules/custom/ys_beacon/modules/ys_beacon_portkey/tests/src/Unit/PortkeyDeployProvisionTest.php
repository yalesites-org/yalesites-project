<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_beacon_portkey\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the deploy hook restores settings config:import just deleted.
 *
 * Ys_beacon_portkey.settings is covered by the same ys_beacon* config_ignore
 * entry as its parent module and is deleted the same way: config:import is
 * what installs the module on a site that does not have it yet, and the
 * changelist the importer recalculates afterwards reads the freshly seeded
 * object as a delete. ys_beacon.deploy.php carries the full trace
 * (yalesites-org/YaleSites-Internal#1491).
 *
 * Without the settings the provider has no api_key pointer and cannot load
 * its key, so Beacon cannot reach the LLM at all.
 *
 * @group ys_beacon
 */
class PortkeyDeployProvisionTest extends UnitTestCase {

  /**
   * The config factory backing the hook under test.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  private ConfigFactory $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon_portkey.install';
    require_once dirname(__DIR__, 3) . '/ys_beacon_portkey.deploy.php';

    // ConfigFactory subscribes to its own save events to drop the config it has
    // cached; without that wiring a read after a write returns a stale object,
    // which is a harness artifact rather than anything the hook does.
    $dispatcher = new EventDispatcher();
    $this->configFactory = new ConfigFactory(
      new MemoryStorage(),
      $dispatcher,
      $this->createMock(TypedConfigManagerInterface::class)
    );
    $dispatcher->addSubscriber($this->configFactory);

    // Points at the real module root, so the restore reads the shipped file.
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('getPath')
      ->with('ys_beacon_portkey')
      ->willReturn($this->moduleRoot());

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('extension.list.module', $list);
    // The hook loads the install file through the module handler; setUp() has
    // already required it, so the stub only has to accept the call.
    $container->set(
      'module_handler',
      $this->createMock(ModuleHandlerInterface::class)
    );
    $container->set(
      'cache_tags.invalidator',
      $this->createMock(CacheTagsInvalidatorInterface::class)
    );
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * The module directory holding the shipped config/install file.
   *
   * @return string
   *   The absolute path to the ys_beacon_portkey module root.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The shipped defaults, parsed from the real config/install file.
   *
   * @return array
   *   The shipped defaults.
   */
  private function shippedDefaults(): array {
    return Yaml::parseFile(
      $this->moduleRoot() . '/config/install/ys_beacon_portkey.settings.yml'
    );
  }

  /**
   * Settings deleted by the import are restored with the shipped defaults.
   */
  public function testRestoresSettingsDeletedByConfigImport(): void {
    $this->assertTrue(
      $this->configFactory->get('ys_beacon_portkey.settings')->isNew(),
      'The settings object starts absent, as on a site the import cleared.'
    );

    ys_beacon_portkey_deploy_10001();

    $restored = $this->configFactory->get('ys_beacon_portkey.settings');
    $this->assertFalse($restored->isNew(), 'The object is recreated.');
    $this->assertSame(
      $this->shippedDefaults(),
      $restored->getRawData(),
      'The shipped defaults are restored verbatim.'
    );

    // The provider resolves its key through this pointer; without it Beacon
    // cannot authenticate to the gateway.
    $this->assertSame(
      'portkey_llm_api_key',
      $restored->get('api_key'),
      'The api_key pointer is restored.'
    );
  }

  /**
   * A configured provider is left completely untouched.
   */
  public function testLeavesConfiguredProviderUntouched(): void {
    $saved = [
      'api_key' => 'site_specific_key',
      'host' => 'https://example.test/v1',
    ];
    $this->configFactory->getEditable('ys_beacon_portkey.settings')
      ->setData($saved)
      ->save();

    ys_beacon_portkey_deploy_10001();

    $this->assertSame(
      $saved,
      $this->configFactory->get('ys_beacon_portkey.settings')->getRawData(),
      'A configured provider is not rewritten.'
    );
  }

}
