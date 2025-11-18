<?php

namespace Drupal\Tests\ys_file_management\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Kernel tests for ConditionalMediaDeleteForm.
 *
 * @group ys_file_management
 * @group yalesites
 */
class ConditionalMediaDeleteFormTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'image',
    'media',
    'media_file_delete',
    'ys_file_management',
  ];

  /**
   * The media file deleter service.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileDeleter
   */
  protected $mediaFileDeleter;

  /**
   * Test file entity.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $testFile;

  /**
   * Test media entity.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected $testMedia;

  /**
   * User with file manager permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $fileManagerUser;

  /**
   * User without file manager permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installSchema('system', 'sequences');
    $this->installConfig(['system', 'field', 'file', 'media', 'ys_file_management']);

    // Get the service.
    $this->mediaFileDeleter = $this->container->get('ys_file_management.media_file_deleter');

    // Create test users.
    $this->fileManagerUser = User::create([
      'name' => 'file_manager',
      'uid' => 2,
    ]);
    $this->fileManagerUser->save();

    $this->regularUser = User::create([
      'name' => 'regular_user',
      'uid' => 3,
    ]);
    $this->regularUser->save();

    // Create role with manage media files permission.
    $role = Role::create([
      'id' => 'file_manager',
      'label' => 'File Manager',
    ]);
    $role->grantPermission('manage media files');
    $role->save();
    $this->fileManagerUser->addRole('file_manager');
    $this->fileManagerUser->save();
  }

  /**
   * Tests that the MediaFileDeleter service is available.
   *
   * @covers \Drupal\ys_file_management\Service\MediaFileDeleter
   */
  public function testServiceExists() {
    $this->assertNotNull($this->mediaFileDeleter);
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Service\MediaFileDeleter',
      $this->mediaFileDeleter
    );
  }

  /**
   * Tests file validation methods.
   *
   * @covers \Drupal\ys_file_management\Service\MediaFileDeleter::validateFile
   */
  public function testValidateFile() {
    // Create a test file.
    $file = File::create([
      'uri' => 'public://test-file.txt',
      'filename' => 'test-file.txt',
    ]);
    $file->save();

    // Valid file should return TRUE.
    $this->assertTrue($this->mediaFileDeleter->validateFile($file));

    // NULL should return FALSE.
    $this->assertFalse($this->mediaFileDeleter->validateFile(NULL));

    // Non-FileInterface object should return FALSE.
    $this->assertFalse($this->mediaFileDeleter->validateFile(new \stdClass()));
  }

  /**
   * Tests URI validation.
   *
   * @covers \Drupal\ys_file_management\Service\MediaFileDeleter::validateFileUri
   */
  public function testValidateFileUri() {
    // Valid public:// scheme should return TRUE.
    $this->assertTrue($this->mediaFileDeleter->validateFileUri('public://test.jpg'));

    // Valid private:// scheme should return TRUE (if enabled).
    // Note: This may return FALSE if private files aren't configured.
    $result = $this->mediaFileDeleter->validateFileUri('private://test.jpg');
    $this->assertIsBool($result);

    // Invalid scheme should return FALSE.
    $this->assertFalse($this->mediaFileDeleter->validateFileUri('invalid://test.jpg'));
  }

  /**
   * Tests the permission constant is defined.
   *
   * @covers \Drupal\ys_file_management\Form\ConditionalMediaDeleteForm::PERMISSION_MANAGE_FILES
   */
  public function testPermissionConstant() {
    $reflection = new \ReflectionClass('Drupal\ys_file_management\Form\ConditionalMediaDeleteForm');
    $this->assertTrue($reflection->hasConstant('PERMISSION_MANAGE_FILES'));
    $this->assertEquals('manage media files', $reflection->getConstant('PERMISSION_MANAGE_FILES'));
  }

  /**
   * Tests that file manager user has correct permissions.
   */
  public function testFileManagerPermissions() {
    $this->assertTrue($this->fileManagerUser->hasPermission('manage media files'));
    $this->assertFalse($this->regularUser->hasPermission('manage media files'));
  }

}
