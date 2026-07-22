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
   * CampusGroupsConfig must read the same config the rest of the module uses.
   *
   * Every other class in the module (CampusGroupsSettings,
   * CampusGroupsIntegrationPlugin, RunMigrations, CampusGroupUrl) reads and
   * writes "ys_campus_groups.settings" (plural), so CampusGroupsConfig must
   * request that same object, not the singular "ys_campus_group.settings".
   *
   * @covers ::__construct
   * @covers ::getConfig
   */
  public function testGetConfigShouldUseCampusGroupsSettingsName(): void {
    $this->configFactory->expects($this->once())
      ->method('getEditable')
      ->with('ys_campus_groups.settings')
      ->willReturn($this->createMock(ImmutableConfig::class));

    $service = new CampusGroupsConfig($this->configFactory);
    $service->getConfig();
  }

}
