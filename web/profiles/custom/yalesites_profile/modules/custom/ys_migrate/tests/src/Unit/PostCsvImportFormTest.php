<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\file\FileInterface;
use Drupal\ys_migrate\Batch\CsvImportBatch;
use Drupal\ys_migrate\Form\PostCsvImportForm;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\PostImportService;

/**
 * Unit tests for PostCsvImportForm.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Form\PostCsvImportForm
 * @group ys_migrate
 * @group yalesites
 */
class PostCsvImportFormTest extends UnitTestCase {

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
   * The post import service mock.
   *
   * @var \Drupal\ys_migrate\Service\PostImportService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $postImport;

  /**
   * The file storage mock.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileStorage;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The renderer mock.
   *
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * The form under test.
   *
   * @var \Drupal\ys_migrate\Form\PostCsvImportForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->csvValidator = $this->createMock(CsvValidatorService::class);
    $this->postImport = $this->createMock(PostImportService::class);

    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($this->fileStorage);

    $this->renderer = $this->createMock(RendererInterface::class);

    $this->form = new PostCsvImportForm(
      $this->messenger,
      $this->csvValidator,
      $this->postImport,
      $this->entityTypeManager,
      $this->renderer
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a partial mock isolating submitForm() from the real batch_set().
   */
  protected function partialForm(array $onlyMethods): PostCsvImportForm {
    $form = $this->getMockBuilder(PostCsvImportForm::class)
      ->setConstructorArgs([
        $this->messenger,
        $this->csvValidator,
        $this->postImport,
        $this->entityTypeManager,
        $this->renderer,
      ])
      ->onlyMethods($onlyMethods)
      ->getMock();
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /**
   * Builds a form state for a submitted, already-validated upload.
   */
  protected function submittedState(bool $preview, array $data): FormState {
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', $preview);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', ['data' => $data]);
    return $form_state;
  }

  /**
   * GetFormId() returns the expected form machine name.
   *
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ys_migrate_post_csv_import', $this->form->getFormId());
  }

  /**
   * BuildForm() includes a link to download a sample CSV.
   *
   * @covers ::buildForm
   */
  public function testBuildFormIncludesSampleDownloadLink() {
    $this->csvValidator->method('getExpectedPostColumns')->willReturn(['title' => 'Title']);

    $form = $this->form->buildForm([], new FormState());

    $this->assertEquals('link', $form['sample_download']['#type']);
    $this->assertEquals('ys_migrate.post_csv_sample', $form['sample_download']['#url']->getRouteName());
  }

  /**
   * BuildForm() gives every expected column a note.
   *
   * Built against the real CsvValidatorService, so a column added to
   * EXPECTED_POST_COLUMNS without a matching columnNotes() entry fails here
   * instead of shipping with a blank notes cell.
   *
   * @covers ::buildForm
   * @covers ::columnNotes
   */
  public function testBuildFormNotesEveryExpectedPostColumn() {
    $form = new PostCsvImportForm(
      $this->messenger,
      new CsvValidatorService(),
      $this->postImport,
      $this->entityTypeManager,
      $this->renderer
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $built = $form->buildForm([], new FormState());

    $this->assertCount(count(CsvValidatorService::EXPECTED_POST_COLUMNS), $built['columns']['#rows']);
    foreach ($built['columns']['#rows'] as $row) {
      $this->assertNotSame('', (string) $row[1], "{$row[0]} should not have a blank notes cell.");
    }
  }

  /**
   * SubmitForm() previews the import and cleans up the uploaded file.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewOnlyDoesNotBatch() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->postImport->method('previewImport')->willReturn([
      'valid_posts' => [],
      'duplicates' => [],
      'errors' => [],
      'total' => 0,
    ]);
    $this->postImport->expects($this->never())->method('processImport');

    $form_array = [];
    $this->form->submitForm($form_array, $this->submittedState(TRUE, [['title' => 'Post A']]));
  }

  /**
   * SubmitForm() preview cleanup tolerates a file entity that is already gone.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewOnlyToleratesMissingFile() {
    $this->fileStorage->method('load')->with(123)->willReturn(NULL);

    $this->postImport->method('previewImport')->willReturn([
      'valid_posts' => [],
      'duplicates' => [],
      'errors' => [],
      'total' => 0,
    ]);

    // No exception, nothing to assert beyond "this does not throw".
    $form_array = [];
    $this->form->submitForm($form_array, $this->submittedState(TRUE, []));
    $this->addToAssertionCount(1);
  }

  /**
   * Preview reports duplicates and errors, and tables the posts it would make.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewReportsResults() {
    $this->fileStorage->method('load')->willReturn(NULL);

    $this->postImport->method('previewImport')->willReturn([
      'valid_posts' => [
        [
          'title' => 'Had Image',
          'publish_date' => '2026-03-04',
          'category' => ['News'],
          'body' => '<p>Words.</p>',
          'body_has_media' => TRUE,
          'teaser_media' => 123,
        ],
      ],
      'duplicates' => ['Already Here'],
      'errors' => ['Row 4: Publish Date is required.'],
      'total' => 3,
    ]);

    $warnings = [];
    $this->messenger->method('addWarning')->willReturnCallback(function ($message) use (&$warnings) {
      $warnings[] = (string) $message;
    });
    $this->messenger->expects($this->once())->method('addError')->with('Row 4: Publish Date is required.');

    $table = NULL;
    $this->renderer->method('render')->willReturnCallback(function ($build) use (&$table) {
      $table = $build;
      return '';
    });

    $form_array = [];
    $this->form->submitForm($form_array, $this->submittedState(TRUE, []));

    $this->assertStringContainsString('Already Here', implode(' ', $warnings));
    $this->assertSame('Posts to be created', (string) $table['#caption']);
    $this->assertSame(
      ['Had Image', '2026-03-04', 'News', 'Yes', 123, 'Yes'],
      array_map(fn ($cell) => is_object($cell) ? (string) $cell : $cell, $table['#rows'][0])
    );
  }

  /**
   * SubmitForm() schedules a batch through the post import service.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormProcessImportSchedulesBatch() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->never())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->postImport->expects($this->never())->method('processImport');
    $this->messenger->expects($this->never())->method('addStatus');

    $captured = NULL;
    $form = $this->partialForm(['setBatch']);
    $form->expects($this->once())->method('setBatch')->with($this->callback(function ($batch) use (&$captured) {
      $captured = $batch;
      return TRUE;
    }));

    $data = [
      ['title' => 'Post A', 'publish date' => '2026-03-04'],
      ['title' => 'Post B', 'publish date' => '2026-03-05'],
    ];

    $form_array = [];
    $form_state = $this->submittedState(FALSE, $data);
    $form->submitForm($form_array, $form_state);

    $this->assertSame([CsvImportBatch::class, 'finished'], $captured['finished']);
    $this->assertCount(2, $captured['operations']);

    [$chunk_callback, $chunk_args] = $captured['operations'][0];
    $this->assertSame([CsvImportBatch::class, 'processChunk'], $chunk_callback);
    $this->assertSame([
      'import_service_id' => 'ys_migrate.post_import',
      'skip_duplicates' => TRUE,
      'entity_label' => 'post',
    ], $chunk_args[0]);
    $this->assertSame($data, $chunk_args[1]);

    [$cleanup_callback, $cleanup_args] = $captured['operations'][1];
    $this->assertSame([CsvImportBatch::class, 'deleteUploadedFile'], $cleanup_callback);
    $this->assertSame([123], $cleanup_args);

    // The parsed rows travel in the batch operations; keeping them on the
    // form state too would serialize them twice.
    $this->assertNull($form_state->get('csv_validation'));
  }

}
