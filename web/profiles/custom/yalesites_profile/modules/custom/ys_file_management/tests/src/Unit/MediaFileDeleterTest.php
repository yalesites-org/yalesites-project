<?php

namespace Drupal\Tests\ys_file_management\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_file_management\Service\MediaFileDeleter;

/**
 * Unit tests for MediaFileDeleter service.
 *
 * @coversDefaultClass \Drupal\ys_file_management\Service\MediaFileDeleter
 * @group ys_file_management
 * @group yalesites
 */
class MediaFileDeleterTest extends UnitTestCase {

  /**
   * The file system service mock.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * The messenger service mock.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The logger factory mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The stream wrapper manager mock.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $streamWrapperManager;

  /**
   * The cache tags invalidator mock.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cacheTagsInvalidator;

  /**
   * The MediaFileDeleter service under test.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileDeleter
   */
  protected $mediaFileDeleter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mocks for all dependencies.
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    // Configure logger factory to return logger channel.
    $this->loggerFactory->method('get')
      ->with('ys_file_management')
      ->willReturn($this->loggerChannel);

    // Create the service with mocked dependencies.
    $this->mediaFileDeleter = new MediaFileDeleter(
      $this->fileSystem,
      $this->messenger,
      $this->loggerFactory,
      $this->streamWrapperManager,
      $this->cacheTagsInvalidator
    );

    // Add string translation mock.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(function ($string) {
        return $string;
      });
    $this->mediaFileDeleter->setStringTranslation($translation);
  }

  /**
   * Creates a mock file entity.
   *
   * @param int $id
   *   The file ID.
   * @param string $uri
   *   The file URI.
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\file\FileInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock file entity.
   */
  protected function createMockFile($id = 1, $uri = 'public://test.jpg', $filename = 'test.jpg') {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($id);
    $file->method('getFileUri')->willReturn($uri);
    $file->method('getFilename')->willReturn($filename);
    return $file;
  }

  /**
   * Tests validateFile() with a valid FileInterface object.
   *
   * @covers ::validateFile
   */
  public function testValidateFileWithValidFile() {
    $file = $this->createMockFile();
    $result = $this->mediaFileDeleter->validateFile($file);
    $this->assertTrue($result);
  }

  /**
   * Tests validateFile() with NULL.
   *
   * @covers ::validateFile
   */
  public function testValidateFileWithNull() {
    $result = $this->mediaFileDeleter->validateFile(NULL);
    $this->assertFalse($result);
  }

  /**
   * Tests validateFile() with invalid object.
   *
   * @covers ::validateFile
   */
  public function testValidateFileWithInvalidObject() {
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Invalid file object provided to MediaFileDeleter');

    $result = $this->mediaFileDeleter->validateFile(new \stdClass());
    $this->assertFalse($result);
  }

  /**
   * Tests validateFileUri() with a valid URI.
   *
   * @covers ::validateFileUri
   */
  public function testValidateFileUriWithValidUri() {
    // Mock only isValidScheme - getScheme is static and will work normally.
    $this->streamWrapperManager->method('isValidScheme')
      ->with('public')
      ->willReturn(TRUE);

    $result = $this->mediaFileDeleter->validateFileUri('public://test.jpg');
    $this->assertTrue($result);
  }

  /**
   * Tests validateFileUri() with invalid URI scheme.
   *
   * @covers ::validateFileUri
   */
  public function testValidateFileUriWithInvalidScheme() {
    // Mock only isValidScheme - getScheme is static and will work normally.
    $this->streamWrapperManager->method('isValidScheme')
      ->with('invalid')
      ->willReturn(FALSE);

    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Invalid file URI scheme for @uri', ['@uri' => 'invalid://test.jpg']);

    $result = $this->mediaFileDeleter->validateFileUri('invalid://test.jpg');
    $this->assertFalse($result);
  }

  /**
   * Tests deleteFile() with successful deletion.
   *
   * @covers ::deleteFile
   * @covers ::validateFile
   * @covers ::validateFileUri
   */
  public function testDeleteFileSuccess() {
    $file = $this->createMockFile(123, 'public://test.jpg', 'test.jpg');

    // Mock only isValidScheme - getScheme is static and will work normally.
    $this->streamWrapperManager->method('isValidScheme')
      ->with('public')
      ->willReturn(TRUE);

    // File system deletion succeeds.
    $this->fileSystem->expects($this->once())
      ->method('delete')
      ->with('public://test.jpg')
      ->willReturn(TRUE);

    // File entity deletion.
    $file->expects($this->once())
      ->method('delete');

    // Cache invalidation.
    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['file:123']);

    // User feedback.
    $this->messenger->expects($this->once())
      ->method('addMessage');

    // Success logging.
    $this->loggerChannel->expects($this->once())
      ->method('info')
      ->with(
        'Successfully deleted file @name (fid: @fid, uri: @uri)',
        [
          '@name' => 'test.jpg',
          '@fid' => 123,
          '@uri' => 'public://test.jpg',
        ]
      );

    $result = $this->mediaFileDeleter->deleteFile($file);
    $this->assertTrue($result);
  }

  /**
   * Tests deleteFile() when filesystem deletion fails.
   *
   * @covers ::deleteFile
   */
  public function testDeleteFileFilesystemFails() {
    $file = $this->createMockFile(123, 'public://test.jpg', 'test.jpg');

    // Mock only isValidScheme - getScheme is static.
    $this->streamWrapperManager->method('isValidScheme')->willReturn(TRUE);

    // File system deletion fails.
    $this->fileSystem->method('delete')->willReturn(FALSE);

    // Entity still deleted (best-effort).
    $file->expects($this->once())->method('delete');

    // Cache still invalidated.
    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['file:123']);

    // Warning shown to user.
    $this->messenger->expects($this->once())->method('addWarning');

    // Warning logged.
    $this->loggerChannel->expects($this->once())
      ->method('warning')
      ->with(
        'File system deletion failed for @uri (fid: @fid). File entity will be deleted to maintain database consistency.',
        [
          '@uri' => 'public://test.jpg',
          '@fid' => 123,
        ]
      );

    $result = $this->mediaFileDeleter->deleteFile($file);
    $this->assertFalse($result);
  }

  /**
   * Tests deleteFile() when FileException is thrown.
   *
   * @covers ::deleteFile
   */
  public function testDeleteFileWithFileException() {
    $file = $this->createMockFile(123, 'public://test.jpg', 'test.jpg');

    // Mock only isValidScheme - getScheme is static.
    $this->streamWrapperManager->method('isValidScheme')->willReturn(TRUE);

    // File system throws FileException.
    $this->fileSystem->method('delete')
      ->willThrowException(new FileException('File is locked'));

    // Entity should NOT be deleted.
    $file->expects($this->never())->method('delete');

    // No cache invalidation on failure.
    $this->cacheTagsInvalidator->expects($this->never())
      ->method('invalidateTags');

    // Error shown to user.
    $this->messenger->expects($this->once())->method('addError');

    // Error logged.
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with(
        'FileException while deleting @file (fid: @fid): @error. File entity NOT deleted.',
        $this->callback(function ($context) {
          return $context['@file'] === 'public://test.jpg'
            && $context['@fid'] === 123
            && $context['@error'] === 'File is locked';
        })
      );

    $result = $this->mediaFileDeleter->deleteFile($file);
    $this->assertFalse($result);
  }

  /**
   * Tests deleteFile() when EntityStorageException is thrown.
   *
   * @covers ::deleteFile
   */
  public function testDeleteFileWithEntityStorageException() {
    $file = $this->createMockFile(123, 'public://test.jpg', 'test.jpg');

    // Mock only isValidScheme - getScheme is static.
    $this->streamWrapperManager->method('isValidScheme')->willReturn(TRUE);

    // File system deletion succeeds.
    $this->fileSystem->method('delete')->willReturn(TRUE);

    // Entity deletion throws exception.
    $file->method('delete')
      ->willThrowException(new EntityStorageException('Database error'));

    // Error shown to user.
    $this->messenger->expects($this->once())->method('addError');

    // Error logged.
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with(
        'EntityStorageException deleting file entity @fid: @error. Physical file may be orphaned.',
        $this->callback(function ($context) {
          return $context['@fid'] === 123
            && $context['@error'] === 'Database error';
        })
      );

    $result = $this->mediaFileDeleter->deleteFile($file);
    $this->assertFalse($result);
  }

  /**
   * Tests deleteFile() with invalid file object.
   *
   * @covers ::validateFile
   */
  public function testDeleteFileWithInvalidFile() {
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Invalid file object provided to MediaFileDeleter');

    // Test validateFile with non-FileInterface.
    $result = $this->mediaFileDeleter->validateFile(new \stdClass());
    $this->assertFalse($result);
  }

  /**
   * Tests deleteFile() with invalid URI.
   *
   * @covers ::deleteFile
   */
  public function testDeleteFileWithInvalidUri() {
    $file = $this->createMockFile(123, 'invalid://test.jpg', 'test.jpg');

    // Mock only isValidScheme - getScheme is static and will extract 'invalid'.
    $this->streamWrapperManager->method('isValidScheme')
      ->with('invalid')
      ->willReturn(FALSE);

    $this->messenger->expects($this->once())
      ->method('addError');

    // Logger is called twice: once in validateFileUri, once in deleteFile.
    $this->loggerChannel->expects($this->exactly(2))
      ->method('error');

    $result = $this->mediaFileDeleter->deleteFile($file);
    $this->assertFalse($result);
  }

  /**
   * Tests that the service implements the interface.
   *
   * @covers ::__construct
   */
  public function testServiceImplementsInterface() {
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Service\MediaFileDeleterInterface',
      $this->mediaFileDeleter
    );
  }

  /**
   * Tests getFileCacheTags() helper method.
   *
   * Uses reflection to test the protected method since it's used internally
   * by deleteFile() for cache invalidation.
   */
  public function testGetFileCacheTags() {
    $reflection = new \ReflectionClass($this->mediaFileDeleter);
    $method = $reflection->getMethod('getFileCacheTags');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->mediaFileDeleter, '123');
    $this->assertEquals(['file:123'], $result);
  }

  /**
   * Tests getLogger() helper method.
   *
   * Uses reflection to test the protected method since it's used internally
   * throughout the service.
   */
  public function testGetLogger() {
    $reflection = new \ReflectionClass($this->mediaFileDeleter);
    $method = $reflection->getMethod('getLogger');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->mediaFileDeleter);
    $this->assertInstanceOf(
      'Drupal\Core\Logger\LoggerChannelInterface',
      $result
    );
  }

}
