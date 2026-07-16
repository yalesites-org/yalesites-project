<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_campus_groups\CampusGroupsConfig;

/**
 * Unit tests for the CampusGroupsConfig service.
 *
 * @coversDefaultClass \Drupal\ys_campus_groups\CampusGroupsConfig
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class CampusGroupsConfigTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
  }

  /**
   * @covers ::create
   */
  public function testCreateReturnsCampusGroupsConfigInstance(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->with('config.factory')
      ->willReturn($this->configFactory);

    $service = CampusGroupsConfig::create($container);

    $this->assertInstanceOf(CampusGroupsConfig::class, $service);
  }

  /**
   * Locks in the current getConfig() behavior for the GAP.
   *
   * Paired with testGetConfigShouldUseCampusGroupsSettingsName() -- delete
   * once the GAP is fixed.
   *
   * CampusGroupsConfig requests the config object named
   * "ys_campus_group.settings" (singular "group"), but every other class in
   * this module -- CampusGroupsSettings, CampusGroupsIntegrationPlugin,
   * RunMigrations, CampusGroupUrl, and the cron hook -- reads and writes
   * "ys_campus_groups.settings" (plural). The service is not currently
   * registered or used anywhere in the module, so this typo is latent rather
   * than actively causing incorrect behavior today.
   *
   * @covers ::__construct
   * @covers ::getConfig
   */
  public function testGetConfigUsesSingularConfigNameCurrentBehavior(): void {
    $this->configFactory->expects($this->once())
      ->method('getEditable')
      ->with('ys_campus_group.settings')
      ->willReturn($this->createMock(ImmutableConfig::class));

    $service = new CampusGroupsConfig($this->configFactory);
    $service->getConfig();
  }

  /**
   * Paired with testGetConfigUsesSingularConfigNameCurrentBehavior().
   *
   * GAP: CampusGroupsConfig reads "ys_campus_group.settings" (singular)
   * while the rest of the module reads and writes "ys_campus_groups.settings"
   * (plural), so if this service is ever wired up it will silently see a
   * different config object than the settings form saves to.
   *
   * @covers ::__construct
   * @covers ::getConfig
   */
  public function testGetConfigShouldUseCampusGroupsSettingsName(): void {
    $this->markTestSkipped('GAP: CampusGroupsConfig::__construct() calls $config_factory->getEditable(\'ys_campus_group.settings\') (singular), but every other class in the module uses \'ys_campus_groups.settings\' (plural) -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_campus_groups.md');
  }

}
