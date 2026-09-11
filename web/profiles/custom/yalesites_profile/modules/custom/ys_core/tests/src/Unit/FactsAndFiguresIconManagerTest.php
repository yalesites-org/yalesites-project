<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\FactsAndFiguresIconManager;

/**
 * Tests FactsAndFiguresIconManager's icon lookups against a cached config.
 *
 * These tests exercise the cache-hit path only: the mocked cache backend
 * feeds getIconConfig() a canned configuration so the lookup helpers
 * (getIconOptions(), isValidIcon(), getIconLabel(), etc.) can be verified in
 * isolation from the real component library YAML file on disk. The
 * cache-miss file-parsing and fallback-config paths depend on that real
 * file's presence under the atomic theme's node_modules and are left
 * uncovered here -- see the module test log for details.
 *
 * @coversDefaultClass \Drupal\ys_core\FactsAndFiguresIconManager
 *
 * @group ys_core
 * @group yalesites
 */
class FactsAndFiguresIconManagerTest extends UnitTestCase {

  /**
   * The cache backend mock.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cache;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_core\FactsAndFiguresIconManager
   */
  protected $manager;

  /**
   * Canned icon configuration returned from the mocked cache.
   */
  const CACHED_CONFIG = [
    'config' => [
      'version' => '1.0',
      'default_value' => '_none',
      'none_label' => '- None -',
    ],
    'icons' => [
      'graduation-cap-solid' => 'Graduation Cap',
      'trophy-solid' => 'Trophy',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->cache = $this->createMock(CacheBackendInterface::class);
    $cached = (object) ['data' => self::CACHED_CONFIG];
    $this->cache->method('get')
      ->with(FactsAndFiguresIconManager::CACHE_ID)
      ->willReturn($cached);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $this->manager = new FactsAndFiguresIconManager($this->cache, $moduleHandler, $loggerFactory);
  }

  /**
   * @covers ::getIconOptions
   */
  public function testGetIconOptionsReturnsCachedIcons(): void {
    $this->assertSame(self::CACHED_CONFIG['icons'], $this->manager->getIconOptions());
  }

  /**
   * @covers ::getFlatIconOptions
   */
  public function testGetFlatIconOptionsPrependsNoneOption(): void {
    $this->assertSame([
      '_none' => '- None -',
      'graduation-cap-solid' => 'Graduation Cap',
      'trophy-solid' => 'Trophy',
    ], $this->manager->getFlatIconOptions());
  }

  /**
   * @covers ::isValidIcon
   */
  public function testIsValidIconAcceptsNoneValue(): void {
    $this->assertTrue($this->manager->isValidIcon('_none'));
  }

  /**
   * @covers ::isValidIcon
   */
  public function testIsValidIconAcceptsKnownIcon(): void {
    $this->assertTrue($this->manager->isValidIcon('trophy-solid'));
  }

  /**
   * @covers ::isValidIcon
   */
  public function testIsValidIconRejectsUnknownIcon(): void {
    $this->assertFalse($this->manager->isValidIcon('not-a-real-icon'));
  }

  /**
   * @covers ::getIconLabel
   */
  public function testGetIconLabelReturnsNoneLabel(): void {
    $this->assertSame('- None -', $this->manager->getIconLabel('_none'));
  }

  /**
   * @covers ::getIconLabel
   */
  public function testGetIconLabelReturnsKnownLabel(): void {
    $this->assertSame('Graduation Cap', $this->manager->getIconLabel('graduation-cap-solid'));
  }

  /**
   * @covers ::getIconLabel
   */
  public function testGetIconLabelReturnsNullForUnknownIcon(): void {
    $this->assertNull($this->manager->getIconLabel('not-a-real-icon'));
  }

  /**
   * @covers ::clearCache
   */
  public function testClearCacheDeletesCachedConfig(): void {
    $this->cache->expects($this->once())
      ->method('delete')
      ->with(FactsAndFiguresIconManager::CACHE_ID);

    $this->manager->clearCache();
  }

}
