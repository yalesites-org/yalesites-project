<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_themes\ThemeSettingsManager;
use Drupal\ys_themes\ThemesTwigExtension;
use Twig\TwigFunction;

/**
 * Unit tests for the ThemesTwigExtension Twig extension.
 *
 * @coversDefaultClass \Drupal\ys_themes\ThemesTwigExtension
 * @group ys_themes
 * @group yalesites
 */
class ThemesTwigExtensionTest extends UnitTestCase {

  /**
   * The mocked theme settings manager.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $themeSettingsManager;

  /**
   * The extension under test.
   *
   * @var \Drupal\ys_themes\ThemesTwigExtension
   */
  protected $extension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->themeSettingsManager = $this->createMock(ThemeSettingsManager::class);
    $this->extension = new ThemesTwigExtension($this->themeSettingsManager);
  }

  /**
   * Tests get functions registers expected twig functions.
   *
   * GetFunctions() registers exactly the three expected Twig functions, each
   * bound as a callable method on this extension instance.
   *
   * @covers ::getFunctions
   */
  public function testGetFunctionsRegistersExpectedTwigFunctions(): void {
    $functions = $this->extension->getFunctions();

    $this->assertCount(3, $functions);
    foreach ($functions as $function) {
      $this->assertInstanceOf(TwigFunction::class, $function);
    }

    $names = array_map(fn(TwigFunction $function) => $function->getName(), $functions);
    $this->assertSame(['getThemeSetting', 'getAllThemeSettings', 'getSettingValues'], $names);

    $callables = array_map(fn(TwigFunction $function) => $function->getCallable(), $functions);
    $this->assertSame([$this->extension, 'getThemeSetting'], $callables[0]);
    $this->assertSame([$this->extension, 'getAllThemeSettings'], $callables[1]);
    $this->assertSame([$this->extension, 'getSettingValues'], $callables[2]);
  }

  /**
   * GetThemeSetting() delegates to ThemeSettingsManager::getSetting().
   *
   * @covers ::getThemeSetting
   */
  public function testGetThemeSettingDelegatesToManager(): void {
    $this->themeSettingsManager->expects($this->once())
      ->method('getSetting')
      ->with('global_theme')
      ->willReturn('two');

    $this->assertSame('two', $this->extension->getThemeSetting('global_theme'));
  }

  /**
   * GetAllThemeSettings() delegates to ThemeSettingsManager::getAllSettings().
   *
   * @covers ::getAllThemeSettings
   */
  public function testGetAllThemeSettingsDelegatesToManager(): void {
    $all_settings = ['global_theme' => 'one'];
    $this->themeSettingsManager->expects($this->once())
      ->method('getAllSettings')
      ->willReturn($all_settings);

    $this->assertSame($all_settings, $this->extension->getAllThemeSettings());
  }

  /**
   * GetSettingValues() delegates to ThemeSettingsManager::getOptions().
   *
   * @covers ::getSettingValues
   */
  public function testGetSettingValuesDelegatesToManager(): void {
    $values = ['one' => ['label' => 'Old Blues', 'color_theme' => 'one']];
    $this->themeSettingsManager->expects($this->once())
      ->method('getOptions')
      ->with('global_theme')
      ->willReturn($values);

    $this->assertSame($values, $this->extension->getSettingValues('global_theme'));
  }

}
