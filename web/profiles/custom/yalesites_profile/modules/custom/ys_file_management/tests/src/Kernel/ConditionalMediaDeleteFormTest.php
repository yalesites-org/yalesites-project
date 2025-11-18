<?php

namespace Drupal\Tests\ys_file_management\Kernel;

use Drupal\Core\Form\FormState;
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
    'ys_file_management',
  ];

  /**
   * The media file deleter service.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileDeleterInterface
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
    // Check that it implements the interface.
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Service\MediaFileDeleterInterface',
      $this->mediaFileDeleter
    );
    // Check that it's the concrete implementation.
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

  /**
   * Tests that the form can be instantiated via dependency injection.
   *
   * This test ensures that all services are properly injected and the form
   * can be created through the container, catching issues like incorrect
   * interface namespaces that wouldn't be caught by unit tests.
   *
   * @covers \Drupal\ys_file_management\Form\ConditionalMediaDeleteForm::create
   */
  public function testFormInstantiation() {
    // Create a media type for testing.
    $media_type = $this->createMediaType('image');

    // Create a test file.
    $file = File::create([
      'uri' => 'public://test-image.jpg',
      'filename' => 'test-image.jpg',
    ]);
    $file->save();

    // Create a test media entity.
    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => $media_type->id(),
        'name' => 'Test Image',
        'field_media_image' => [
          'target_id' => $file->id(),
        ],
      ]);
    $media->save();

    // Get the form through the entity form builder (uses dependency injection).
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('media', 'delete');

    // Set the entity on the form.
    $form_object->setEntity($media);

    // Verify the form object was created successfully.
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Form\ConditionalMediaDeleteForm',
      $form_object
    );

    // Verify that the form has the required services injected.
    $reflection = new \ReflectionClass($form_object);

    // Check that mediaFileDeleter property exists and is the correct type.
    $this->assertTrue($reflection->hasProperty('mediaFileDeleter'));
    $deleter_property = $reflection->getProperty('mediaFileDeleter');
    $deleter_property->setAccessible(TRUE);
    $this->assertInstanceOf(
      'Drupal\ys_file_management\Service\MediaFileDeleterInterface',
      $deleter_property->getValue($form_object)
    );

    // Check that fileUsage property exists and is the correct type.
    $this->assertTrue($reflection->hasProperty('fileUsage'));
    $usage_property = $reflection->getProperty('fileUsage');
    $usage_property->setAccessible(TRUE);
    $this->assertInstanceOf(
      'Drupal\file\FileUsage\FileUsageInterface',
      $usage_property->getValue($form_object)
    );
  }

  /**
   * Tests that buildForm works correctly for file managers.
   *
   * @covers \Drupal\ys_file_management\Form\ConditionalMediaDeleteForm::buildForm
   */
  public function testBuildFormForFileManager() {
    // Set current user to file manager.
    $this->container->get('current_user')->setAccount($this->fileManagerUser);

    // Create a media type for testing.
    $media_type = $this->createMediaType('image');

    // Create a test file.
    $file = File::create([
      'uri' => 'public://test-image.jpg',
      'filename' => 'test-image.jpg',
      'uid' => $this->fileManagerUser->id(),
    ]);
    $file->save();

    // Create a test media entity.
    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => $media_type->id(),
        'name' => 'Test Image',
        'field_media_image' => [
          'target_id' => $file->id(),
        ],
      ]);
    $media->save();

    // Build the form.
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('media', 'delete');
    $form_object->setEntity($media);

    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state);

    // Verify the file deletion checkbox is present for file managers.
    $this->assertArrayHasKey('also_delete_file', $form);
    $this->assertEquals('checkbox', $form['also_delete_file']['#type']);
  }

  /**
   * Tests that buildForm works correctly for regular users.
   *
   * @covers \Drupal\ys_file_management\Form\ConditionalMediaDeleteForm::buildForm
   */
  public function testBuildFormForRegularUser() {
    // Set current user to regular user.
    $this->container->get('current_user')->setAccount($this->regularUser);

    // Create a media type for testing.
    $media_type = $this->createMediaType('image');

    // Create a test file.
    $file = File::create([
      'uri' => 'public://test-image.jpg',
      'filename' => 'test-image.jpg',
    ]);
    $file->save();

    // Create a test media entity.
    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => $media_type->id(),
        'name' => 'Test Image',
        'field_media_image' => [
          'target_id' => $file->id(),
        ],
      ]);
    $media->save();

    // Build the form.
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('media', 'delete');
    $form_object->setEntity($media);

    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state);

    // Verify the file deletion checkbox is NOT present for regular users.
    $this->assertArrayNotHasKey('also_delete_file', $form);
  }

}
