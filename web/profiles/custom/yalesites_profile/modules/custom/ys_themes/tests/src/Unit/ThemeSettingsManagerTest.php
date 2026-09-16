<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_themes\ThemeSettingsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for the ThemeSettingsManager service.
 *
 * @coversDefaultClass \Drupal\ys_themes\ThemeSettingsManager
 * @group ys_themes
 * @group yalesites
 */
class ThemeSettingsManagerTest extends UnitTestCase {

  /**
   * The mocked editable config object.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(Config::class);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('ys_themes.theme_settings')
      ->willReturn($this->config);

    $this->manager = new ThemeSettingsManager($config_factory);
  }

  /**
   * GetOptions() with no argument returns the full THEME_SETTINGS constant.
   *
   * @covers ::getOptions
   */
  public function testGetOptionsReturnsAllSettingsWhenNoNameGiven(): void {
    $this->assertSame(ThemeSettingsManager::THEME_SETTINGS, $this->manager->getOptions());
  }

  /**
   * GetOptions() with a setting name returns just that setting's values.
   *
   * @covers ::getOptions
   */
  public function testGetOptionsReturnsValuesForGivenSettingName(): void {
    $this->assertSame(
      ThemeSettingsManager::THEME_SETTINGS['global_theme']['values'],
      $this->manager->getOptions('global_theme')
    );
  }

  /**
   * GetSetting() reads through to the underlying config object.
   *
   * @covers ::getSetting
   */
  public function testGetSettingReadsFromConfig(): void {
    $this->config->method('get')
      ->with('global_theme')
      ->willReturn('three');

    $this->assertSame('three', $this->manager->getSetting('global_theme'));
  }

  /**
   * GetAllSettings() reads the whole config object (empty key).
   *
   * @covers ::getAllSettings
   */
  public function testGetAllSettingsReadsWholeConfigObject(): void {
    $all_settings = ['global_theme' => 'one', 'button_theme' => 'two'];
    $this->config->method('get')
      ->with('')
      ->willReturn($all_settings);

    $this->assertSame($all_settings, $this->manager->getAllSettings());
  }

  /**
   * SetSetting() sets the value on config and saves with the sync flag set.
   *
   * @covers ::setSetting
   */
  public function testSetSettingSetsAndSavesWithSyncFlag(): void {
    $this->config->expects($this->once())
      ->method('set')
      ->with('global_theme', 'three')
      ->willReturnSelf();
    $this->config->expects($this->once())
      ->method('save')
      ->with(TRUE);

    $this->manager->setSetting('global_theme', 'three');
  }

  /**
   * Create() builds the service from the container's config.factory.
   *
   * @covers ::create
   */
  public function testCreateBuildsServiceFromContainer(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('ys_themes.theme_settings')
      ->willReturn($this->config);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->with('config.factory')
      ->willReturn($config_factory);

    $manager = ThemeSettingsManager::create($container);

    $this->assertInstanceOf(ThemeSettingsManager::class, $manager);
    $this->config->method('get')->with('global_theme')->willReturn('one');
    $this->assertSame('one', $manager->getSetting('global_theme'));
  }

}
