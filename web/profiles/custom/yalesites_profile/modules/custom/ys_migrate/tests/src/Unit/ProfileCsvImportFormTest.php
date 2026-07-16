<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\file\FileInterface;
use Drupal\ys_migrate\Form\ProfileCsvImportForm;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\ProfileImportService;

/**
 * Unit tests for ProfileCsvImportForm.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Form\ProfileCsvImportForm
 * @group ys_migrate
 * @group yalesites
 */
class ProfileCsvImportFormTest extends UnitTestCase {

  /**
   * The messenger mock.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The CSV validator mock.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $csvValidator;

  /**
   * The profile import service mock.
   *
   * @var \Drupal\ys_migrate\Service\ProfileImportService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $profileImport;

  /**
   * The file storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileStorage;

  /**
   * The renderer mock.
   *
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_migrate\Form\ProfileCsvImportForm
   */
  protected $form;

  /**
   * Paths of temporary files created during a test, for cleanup.
   *
   * @var string[]
   */
  protected $tempFiles = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $current_user = $this->createMock(AccountInterface::class);
    $this->csvValidator = $this->createMock(CsvValidatorService::class);
    $this->profileImport = $this->createMock(ProfileImportService::class);

    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('file')->willReturn($this->fileStorage);

    $this->renderer = $this->createMock(RendererInterface::class);

    $this->form = new ProfileCsvImportForm(
      $this->messenger,
      $current_user,
      $this->csvValidator,
      $this->profileImport,
      $entity_type_manager,
      $this->renderer
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->tempFiles as $path) {
      if (file_exists($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Creates a real temporary file so file_exists() checks succeed.
   */
  protected function createTempFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'ys_migrate_form_test_');
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * GetFormId() returns the expected form machine name.
   *
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ys_migrate_profile_csv_import', $this->form->getFormId());
  }

  /**
   * BuildForm() assembles the upload widget and both option checkboxes.
   *
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = $this->form->buildForm([], new FormState());

    $this->assertEquals('managed_file', $form['csv_file']['#type']);
    $this->assertTrue($form['csv_file']['#required']);
    $this->assertTrue($form['preview']['#default_value']);
    $this->assertTrue($form['skip_duplicates']['#default_value']);
    $this->assertEquals('submit', $form['actions']['submit']['#type']);
  }

  /**
   * ValidateForm() rejects submission when no file was uploaded.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithNoFile() {
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', []);

    $this->form->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('csv_file', $errors);
    $this->assertEquals('Please upload a CSV file.', (string) $errors['csv_file']);
  }

  /**
   * ValidateForm() rejects submission when the file entity fails to load.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithUnloadableFile() {
    $this->fileStorage->method('load')->with(123)->willReturn(NULL);

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);

    $this->form->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertEquals('Unable to load the uploaded file.', (string) $errors['csv_file']);
  }

  /**
   * ValidateForm() rejects submission when the file is missing on disk.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithMissingFileOnDisk() {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('/nonexistent/path/does-not-exist.csv');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);

    $this->form->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertEquals('The uploaded file could not be found.', (string) $errors['csv_file']);
  }

  /**
   * ValidateForm() surfaces the CsvValidatorService's message when invalid.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidCsvStructure() {
    $path = $this->createTempFile();
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($path);
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->csvValidator->method('validateCsvStructure')->with($path)->willReturn([
      'valid' => FALSE,
      'message' => 'The CSV file must contain a "Display Name" column.',
      'data' => [],
      'headers' => [],
    ]);

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);

    $this->form->validateForm($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertEquals('The CSV file must contain a "Display Name" column.', (string) $errors['csv_file']);
  }

  /**
   * ValidateForm() stores the validation result for submitForm() when valid.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithValidCsv() {
    $path = $this->createTempFile();
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($path);
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $validation_result = [
      'valid' => TRUE,
      'message' => 'CSV file is valid. Found 1 profiles.',
      'data' => [['display name' => 'Jane Doe']],
      'headers' => ['display name' => 'Display Name'],
    ];
    $this->csvValidator->method('validateCsvStructure')->with($path)->willReturn($validation_result);

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);

    $this->form->validateForm($form, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $this->assertSame($validation_result, $form_state->get('csv_validation'));
  }

  /**
   * SubmitForm() previews the import and cleans up the uploaded file.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewOnly() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->profileImport->method('previewImport')->willReturn([
      'valid_profiles' => [
        ['display_name' => 'Jane Doe', 'email' => 'jane@example.com', 'position' => '', 'department' => ''],
      ],
      'duplicates' => ['Existing Person'],
      'total' => 2,
    ]);
    $this->renderer->method('render')->willReturn('<table>rendered preview</table>');

    // Called once for the "N valid profiles found" summary, and again for
    // the rendered preview table since valid_profiles is non-empty.
    $this->messenger->expects($this->exactly(2))->method('addStatus');

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', TRUE);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', ['data' => [['display name' => 'Jane Doe']]]);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * SubmitForm() processes the import and reports created/skipped/errors.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormProcessImport() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->profileImport->method('processImport')->willReturn([
      'created' => 2,
      'skipped' => 1,
      'errors' => ['Row 4: Something went wrong.'],
    ]);

    $this->messenger->expects($this->once())->method('addStatus');
    $this->messenger->expects($this->once())->method('addWarning');
    $this->messenger->expects($this->once())->method('addError')->with('Row 4: Something went wrong.');

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', FALSE);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', [
      'data' => [
        ['display name' => 'Jane Doe'],
        ['display name' => 'John Smith'],
        ['display name' => 'Extra'],
      ],
    ]);

    $this->form->submitForm($form, $form_state);
  }

}
