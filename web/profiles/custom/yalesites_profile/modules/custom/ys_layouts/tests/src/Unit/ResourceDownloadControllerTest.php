<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\file\FileInterface;
use Drupal\ys_layouts\Controller\ResourceDownloadController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the resource download controller.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Controller\ResourceDownloadController
 *
 * @group yalesites
 * @group ys_layouts
 */
class ResourceDownloadControllerTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The file system mock.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * The controller under test.
   *
   * @var \Drupal\ys_layouts\Controller\ResourceDownloadController
   */
  protected $controller;

  /**
   * The file storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $this->fileStorage],
    ]);

    $this->controller = new ResourceDownloadController($this->entityTypeManager, $this->fileSystem);
  }

  /**
   * A missing file entity throws a not-found exception.
   *
   * @covers ::download
   */
  public function testDownloadThrowsNotFoundForMissingFile(): void {
    $this->fileStorage->method('load')->with(99)->willReturn(NULL);

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('File not found.');

    $this->controller->download(99);
  }

  /**
   * A file entity whose disk path is missing throws a not-found exception.
   *
   * @covers ::download
   */
  public function testDownloadThrowsNotFoundWhenFileMissingFromDisk(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://missing.pdf');
    $this->fileStorage->method('load')->with(5)->willReturn($file);
    $this->fileSystem->method('realpath')->with('public://missing.pdf')->willReturn(FALSE);

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('File not found on disk.');

    $this->controller->download(5);
  }

  /**
   * An existing file is served with forced-download headers.
   *
   * @covers ::download
   */
  public function testDownloadServesExistingFileWithForcedHeaders(): void {
    $path = tempnam(sys_get_temp_dir(), 'ys_layouts_test_');
    file_put_contents($path, 'test content');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://download.pdf');
    $file->method('getFilename')->willReturn('download.pdf');
    $file->method('getMimeType')->willReturn('application/pdf');
    $file->method('getSize')->willReturn(12);
    $this->fileStorage->method('load')->with(7)->willReturn($file);
    $this->fileSystem->method('realpath')->with('public://download.pdf')->willReturn($path);

    try {
      $response = $this->controller->download(7);

      $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
      $this->assertStringContainsString('download.pdf', $response->headers->get('Content-Disposition'));
      $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
    finally {
      unlink($path);
    }
  }

  /**
   * A missing file entity is denied access.
   *
   * @covers ::access
   */
  public function testAccessForbiddenForMissingFile(): void {
    $this->fileStorage->method('load')->with(99)->willReturn(NULL);

    $result = $this->controller->access(99);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * A file not attached to a published resource node is denied access.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenNotAttachedToPublishedResource(): void {
    $file = $this->createMock(FileInterface::class);
    $this->fileStorage->method('load')->with(3)->willReturn($file);

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $this->fileStorage],
      ['node', $nodeStorage],
    ]);
    $controller = new ResourceDownloadController($entityTypeManager, $this->fileSystem);

    $result = $controller->access(3);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * A file attached to a published resource node is allowed access.
   *
   * @covers ::access
   */
  public function testAccessAllowedWhenAttachedToPublishedResource(): void {
    $file = $this->createMock(FileInterface::class);
    $this->fileStorage->method('load')->with(3)->willReturn($file);

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([10]);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['file', $this->fileStorage],
      ['node', $nodeStorage],
    ]);
    $controller = new ResourceDownloadController($entityTypeManager, $this->fileSystem);

    $result = $controller->access(3);

    $this->assertTrue($result->isAllowed());
  }

}
