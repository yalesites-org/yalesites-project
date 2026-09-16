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
use Drupal\ys_migrate\Form\ResourceCsvImportForm;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\ResourceImportService;

/**
 * Unit tests for ResourceCsvImportForm.
 *
 * Focused on submitForm()'s batching behavior, since that's the piece this
 * change touches; buildForm() and validateForm() are unchanged from their
 * shipped, manually-verified state.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Form\ResourceCsvImportForm
 * @group ys_migrate
 * @group yalesites
 */
class ResourceCsvImportFormTest extends UnitTestCase {

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
   * The resource import service mock.
   *
   * @var \Drupal\ys_migrate\Service\ResourceImportService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resourceImport;

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
   * @var \Drupal\ys_migrate\Form\ResourceCsvImportForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->csvValidator = $this->createMock(CsvValidatorService::class);
    $this->resourceImport = $this->createMock(ResourceImportService::class);

    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->with('file')->willReturn($this->fileStorage);

    $this->renderer = $this->createMock(RendererInterface::class);

    $this->form = new ResourceCsvImportForm(
      $this->messenger,
      $this->csvValidator,
      $this->resourceImport,
      $this->entityTypeManager,
      $this->renderer
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Builds a partial mock isolating submitForm() from the real batch_set().
   *
   * Setting a real batch requires a full Drupal batch API, unavailable in a
   * unit test, so setBatch() is mocked out here to capture what it was
   * called with.
   */
  protected function partialForm(array $onlyMethods): ResourceCsvImportForm {
    $form = $this->getMockBuilder(ResourceCsvImportForm::class)
      ->setConstructorArgs([
        $this->messenger,
        $this->csvValidator,
        $this->resourceImport,
        $this->entityTypeManager,
        $this->renderer,
      ])
      ->onlyMethods($onlyMethods)
      ->getMock();
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /**
   * GetFormId() returns the expected form machine name.
   *
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ys_migrate_resource_csv_import', $this->form->getFormId());
  }

  /**
   * BuildForm() includes a link to download a sample CSV.
   *
   * @covers ::buildForm
   */
  public function testBuildFormIncludesSampleDownloadLink() {
    $this->csvValidator->method('getExpectedResourceColumns')->willReturn(['title' => 'Title']);

    $form = $this->form->buildForm([], new FormState());

    $this->assertEquals('link', $form['sample_download']['#type']);
    $this->assertEquals('ys_migrate.resource_csv_sample', $form['sample_download']['#url']->getRouteName());
  }

  /**
   * BuildForm() gives every expected column a note, free-text ones included.
   *
   * Description, Teaser Title and Teaser Text previously fell through to an
   * empty notes cell with nothing to tell an editor what belongs there. The
   * form is built against the real CsvValidatorService rather than a mock
   * with a hand-listed column set, so a column added to
   * EXPECTED_RESOURCE_COLUMNS without a matching columnNotes() entry fails
   * here instead of shipping blank.
   *
   * @covers ::buildForm
   * @covers ::columnNotes
   */
  public function testBuildFormNotesEveryExpectedResourceColumn() {
    $form = new ResourceCsvImportForm(
      $this->messenger,
      new CsvValidatorService(),
      $this->resourceImport,
      $this->entityTypeManager,
      $this->renderer
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $built = $form->buildForm([], new FormState());

    foreach ($built['columns']['#rows'] as $row) {
      $this->assertNotSame('', (string) $row[1], "{$row[0]} should not have a blank notes cell.");
    }
  }

  /**
   * SubmitForm() previews the import and cleans up the uploaded file.
   *
   * Preview renders its own response synchronously and never touches the
   * batch queue, so this path is unchanged by the batch work.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewOnlyDoesNotBatch() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->resourceImport->method('previewImport')->willReturn([
      'valid_resources' => [],
      'duplicates' => [],
      'errors' => [],
      'total' => 0,
    ]);
    $this->resourceImport->expects($this->never())->method('processImport');

    $form_array = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', TRUE);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', ['data' => [['title' => 'Resource A']]]);

    $this->form->submitForm($form_array, $form_state);
  }

  /**
   * SubmitForm() preview cleanup tolerates a file entity that is already gone.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormPreviewOnlyToleratesMissingFile() {
    $this->fileStorage->method('load')->with(123)->willReturn(NULL);

    $this->resourceImport->method('previewImport')->willReturn([
      'valid_resources' => [],
      'duplicates' => [],
      'errors' => [],
      'total' => 0,
    ]);

    $form_array = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', TRUE);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', ['data' => []]);

    // No exception, nothing to assert beyond "this does not throw".
    $this->form->submitForm($form_array, $form_state);
    $this->addToAssertionCount(1);
  }

  /**
   * SubmitForm() schedules a batch instead of processing synchronously.
   *
   * Mirrors ProfileCsvImportForm's batching: created/skipped/needs_media/
   * error reporting and file cleanup move to CsvImportBatch (covered by
   * CsvImportBatchTest), so submitForm() no longer calls processImport(),
   * the messenger, or delete() directly.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormProcessImportSchedulesBatch() {
    $file = $this->createMock(FileInterface::class);
    $file->expects($this->never())->method('delete');
    $this->fileStorage->method('load')->with(123)->willReturn($file);

    $this->resourceImport->expects($this->never())->method('processImport');
    $this->messenger->expects($this->never())->method('addStatus');

    $captured = NULL;
    $form = $this->partialForm(['setBatch']);
    $form->expects($this->once())->method('setBatch')->with($this->callback(function ($batch) use (&$captured) {
      $captured = $batch;
      return TRUE;
    }));

    $data = [
      ['title' => 'Resource A'],
      ['title' => 'Resource B'],
    ];

    $form_array = [];
    $form_state = new FormState();
    $form_state->setValue('csv_file', [123]);
    $form_state->setValue('preview', FALSE);
    $form_state->setValue('skip_duplicates', TRUE);
    $form_state->set('csv_validation', ['data' => $data]);

    $form->submitForm($form_array, $form_state);

    $this->assertSame([CsvImportBatch::class, 'finished'], $captured['finished']);
    $this->assertCount(2, $captured['operations']);

    [$chunk_callback, $chunk_args] = $captured['operations'][0];
    $this->assertSame([CsvImportBatch::class, 'processChunk'], $chunk_callback);
    $this->assertSame([
      'import_service_id' => 'ys_migrate.resource_import',
      'skip_duplicates' => TRUE,
      'entity_label' => 'resource',
    ], $chunk_args[0]);
    $this->assertSame($data, $chunk_args[1]);

    [$cleanup_callback, $cleanup_args] = $captured['operations'][1];
    $this->assertSame([CsvImportBatch::class, 'deleteUploadedFile'], $cleanup_callback);
    $this->assertSame([123], $cleanup_args);
  }

}
