<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Tests\UnitTestCase;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\ys_core\YaleSitesMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests YaleSitesMediaManager's favicon building and media lifecycle logic.
 *
 * @coversDefaultClass \Drupal\ys_core\YaleSitesMediaManager
 *
 * @group ys_core
 * @group yalesites
 */
class YaleSitesMediaManagerTest extends UnitTestCase {

  /**
   * Mock of the ys_core.site config.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $yaleSettings;

  /**
   * Mock of the system.site config.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $siteSettings;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The file URL generator mock.
   *
   * @var \Drupal\Core\File\FileUrlGenerator|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_core\YaleSitesMediaManager
   */
  protected $manager;

  /**
   * Temporary files written by writeTempFile(), cleaned up in tearDown().
   *
   * @var string[]
   */
  protected $tempFiles = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->yaleSettings = $this->createMock(Config::class);
    $this->siteSettings = $this->createMock(Config::class);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['ys_core.site', $this->yaleSettings],
      ['system.site', $this->siteSettings],
    ]);

    $this->entityTypeManager = $this->createMock(EntityTypeManager::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGenerator::class);

    $this->manager = new YaleSitesMediaManager($configFactory, $this->entityTypeManager, $this->fileUrlGenerator);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    parent::tearDown();
    foreach ($this->tempFiles as $path) {
      if (file_exists($path)) {
        unlink($path);
      }
    }
  }

  /**
   * Writes real SVG content to a temp file for getSiteNameImage() to read.
   *
   * GetSiteNameImage() reads the file directly via file_get_contents() on
   * the URI returned by getFileUri(), so a plain absolute temp path (rather
   * than a public:// stream-wrapped URI, which isn't registered outside a
   * full Drupal bootstrap) exercises the same code path.
   */
  protected function writeTempFile(string $contents): string {
    $path = tempnam(sys_get_temp_dir(), 'ys_core_media_');
    file_put_contents($path, $contents);
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * @covers ::create
   */
  public function testCreateInstantiatesFromContainer(): void {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['ys_core.site', $this->yaleSettings],
      ['system.site', $this->siteSettings],
    ]);

    $services = [
      'config.factory' => $configFactory,
      'entity_type.manager' => $this->entityTypeManager,
      'file_url_generator' => $this->fileUrlGenerator,
    ];
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn ($id) => $services[$id] ?? NULL);

    $manager = YaleSitesMediaManager::create($container);
    $this->assertInstanceOf(YaleSitesMediaManager::class, $manager);
  }

  /**
   * @covers ::getFavicons
   */
  public function testGetFaviconsReturnsFallbackWhenNoCustomFaviconConfigured(): void {
    $this->yaleSettings->method('get')->with('custom_favicon')->willReturn(NULL);

    $favicons = $this->manager->getFavicons();

    $this->assertCount(4, $favicons);
    $this->assertSame(
      '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/apple-touch-icon.png',
      $favicons['apple-touch-icon']['#attributes']['href']
    );
  }

  /**
   * @covers ::getFavicons
   */
  public function testGetFaviconsKeepsFallbackWhenCustomFileNotFound(): void {
    $this->yaleSettings->method('get')->with('custom_favicon')->willReturn([7]);

    // getFavicons() fetches both the 'file' and 'image_style' storages
    // unconditionally before checking whether the file was actually found.
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(7)->willReturn(NULL);
    $imageStyleStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $fileStorage],
      ['image_style', $imageStyleStorage],
    ]);

    $favicons = $this->manager->getFavicons();

    $this->assertCount(4, $favicons);
    $this->assertSame(
      '/profiles/custom/yalesites_profile/modules/custom/ys_core/images/favicons/favicon.ico',
      $favicons['icon-ico']['#attributes']['href']
    );
  }

  /**
   * @covers ::getFavicons
   */
  public function testGetFaviconsUsesCustomFileForEachAvailableStyle(): void {
    $this->yaleSettings->method('get')->with('custom_favicon')->willReturn([7]);

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://favicon-source.png');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(7)->willReturn($file);

    $style = $this->createMock(ImageStyleInterface::class);
    $style->method('buildUrl')->with('public://favicon-source.png')
      ->willReturn('public://styles/favicon_180x180/favicon-source.png');

    $imageStyleStorage = $this->createMock(EntityStorageInterface::class);
    $imageStyleStorage->method('load')->willReturn($style);

    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $fileStorage],
      ['image_style', $imageStyleStorage],
    ]);

    $this->fileUrlGenerator->method('transformRelative')
      ->willReturn('/sites/default/files/styles/favicon_180x180/favicon-source.png');

    $favicons = $this->manager->getFavicons();

    $this->assertCount(4, $favicons);
    $this->assertSame(
      '/sites/default/files/styles/favicon_180x180/favicon-source.png',
      $favicons['apple-touch-icon']['#attributes']['href']
    );
  }

  /**
   * @covers ::getFavicons
   */
  public function testGetFaviconsDropsSizesWithMissingImageStyle(): void {
    $this->yaleSettings->method('get')->with('custom_favicon')->willReturn([7]);

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://favicon-source.png');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(7)->willReturn($file);

    // Only favicon_180x180 exists as an image style; the other three sizes
    // are expected to be dropped from the returned favicon data.
    $style = $this->createMock(ImageStyleInterface::class);
    $style->method('buildUrl')->willReturn('public://styles/favicon_180x180/favicon-source.png');

    $imageStyleStorage = $this->createMock(EntityStorageInterface::class);
    $imageStyleStorage->method('load')->willReturnMap([
      ['favicon_180x180', $style],
      ['favicon_32x32', NULL],
      ['favicon_16x16', NULL],
      ['favicon_16x16_ico', NULL],
    ]);

    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $fileStorage],
      ['image_style', $imageStyleStorage],
    ]);

    $this->fileUrlGenerator->method('transformRelative')
      ->willReturn('/sites/default/files/styles/favicon_180x180/favicon-source.png');

    $favicons = $this->manager->getFavicons();

    $this->assertSame(['apple-touch-icon'], array_keys($favicons));
  }

  /**
   * @covers ::handleMediaFilesystem
   */
  public function testHandleMediaFilesystemSkipsWhenValueUnchanged(): void {
    $this->entityTypeManager->expects($this->never())->method('getStorage');
    $this->manager->handleMediaFilesystem([7], [7]);
  }

  /**
   * @covers ::handleMediaFilesystem
   */
  public function testHandleMediaFilesystemDeletesOldAndSavesNewFile(): void {
    $oldFile = $this->createMock(FileInterface::class);
    $oldFile->expects($this->once())->method('delete');

    $newFile = $this->createMock(FileInterface::class);
    $newFile->expects($this->once())->method('setPermanent');
    $newFile->expects($this->once())->method('save');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->willReturnMap([
      [3, $oldFile],
      [9, $newFile],
    ]);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->manager->handleMediaFilesystem([9], [3]);
  }

  /**
   * @covers ::handleMediaFilesystem
   */
  public function testHandleMediaFilesystemOnlyDeletesWhenNoNewFile(): void {
    $oldFile = $this->createMock(FileInterface::class);
    $oldFile->expects($this->once())->method('delete');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(3)->willReturn($oldFile);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->manager->handleMediaFilesystem(NULL, [3]);
  }

  /**
   * @covers ::handleMediaFilesystem
   */
  public function testHandleMediaFilesystemOnlySavesWhenNoOldFile(): void {
    $newFile = $this->createMock(FileInterface::class);
    $newFile->expects($this->once())->method('setPermanent');
    $newFile->expects($this->once())->method('save');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(9)->willReturn($newFile);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->manager->handleMediaFilesystem([9], NULL);
  }

  /**
   * @covers ::getSiteNameImage
   */
  public function testGetSiteNameImageReturnsNullWhenFileNotFound(): void {
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(5)->willReturn(NULL);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->assertNull($this->manager->getSiteNameImage(5));
  }

  /**
   * @covers ::getSiteNameImage
   */
  public function testGetSiteNameImageReturnsNullForNonSvgFile(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('logo.png');

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(5)->willReturn($file);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->assertNull($this->manager->getSiteNameImage(5));
  }

  /**
   * @covers ::getSiteNameImage
   */
  public function testGetSiteNameImageReplacesExistingTitleTag(): void {
    $path = $this->writeTempFile('<svg><title>Old Title</title><path d="M0 0"/></svg>');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('logo.svg');
    $file->method('getFileUri')->willReturn($path);

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(5)->willReturn($file);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->siteSettings->method('get')->with('name')->willReturn('Yale University');

    $svg = $this->manager->getSiteNameImage(5);

    $this->assertStringContainsString('<title>Yale University</title>', $svg);
    $this->assertStringNotContainsString('Old Title', $svg);
  }

  /**
   * @covers ::getSiteNameImage
   */
  public function testGetSiteNameImageInsertsTitleTagWhenMissing(): void {
    $path = $this->writeTempFile('<svg viewBox="0 0 10 10"><path d="M0 0"/></svg>');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('logo.svg');
    $file->method('getFileUri')->willReturn($path);

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->with(5)->willReturn($file);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($fileStorage);

    $this->siteSettings->method('get')->with('name')->willReturn('Yale University');

    $svg = $this->manager->getSiteNameImage(5);

    $this->assertStringContainsString(
      '<svg viewBox="0 0 10 10">' . "\n" . '<title>Yale University</title>',
      $svg
    );
  }

}
