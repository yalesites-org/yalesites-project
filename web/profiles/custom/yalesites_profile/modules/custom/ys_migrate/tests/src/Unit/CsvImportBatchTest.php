<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Batch\CsvImportBatch;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for CsvImportBatch.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Batch\CsvImportBatch
 * @group ys_migrate
 * @group yalesites
 */
class CsvImportBatchTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Base container so any TranslatableMarkup built by the class under test
    // can be cast to a string. Individual tests layer additional services
    // (messenger, entity_type.manager, an import service id) onto this same
    // container.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds $count placeholder CSV rows.
   */
  protected function rows(int $count): array {
    return array_fill(0, $count, ['title' => 'Row']);
  }

  /**
   * Build() splits rows into CHUNK_SIZE-row operations plus a cleanup op.
   *
   * @covers ::build
   */
  public function testBuildChunksRowsAndAppendsCleanupOperation() {
    $rows = $this->rows(125);

    $batch = CsvImportBatch::build('ys_migrate.profile_import', $rows, TRUE, 42, 'profile', 'Importing profiles');

    // 125 rows at CHUNK_SIZE (50) => 3 process operations + 1 cleanup.
    $this->assertCount(4, $batch['operations']);

    [$callback1, $args1] = $batch['operations'][0];
    $this->assertSame([CsvImportBatch::class, 'processChunk'], $callback1);
    $this->assertSame('ys_migrate.profile_import', $args1[0]);
    $this->assertCount(50, $args1[1]);
    $this->assertTrue($args1[2]);
    $this->assertSame('profile', $args1[3]);

    [, $args3] = $batch['operations'][2];
    $this->assertCount(25, $args3[1], 'Last chunk carries the remainder.');

    [$cleanupCallback, $cleanupArgs] = $batch['operations'][3];
    $this->assertSame([CsvImportBatch::class, 'deleteUploadedFile'], $cleanupCallback);
    $this->assertSame([42], $cleanupArgs);
  }

  /**
   * Build() with fewer rows than CHUNK_SIZE produces a single chunk.
   *
   * @covers ::build
   */
  public function testBuildWithFewerRowsThanChunkSizeProducesSingleChunk() {
    $batch = CsvImportBatch::build('ys_migrate.profile_import', $this->rows(10), TRUE, 1, 'profile', 'Importing profiles');

    // 1 process operation + 1 cleanup.
    $this->assertCount(2, $batch['operations']);
  }

  /**
   * Build() with no rows still schedules the cleanup operation.
   *
   * @covers ::build
   */
  public function testBuildWithEmptyRowsStillCleansUpFile() {
    $batch = CsvImportBatch::build('ys_migrate.profile_import', [], TRUE, 7, 'profile', 'Importing profiles');

    $this->assertCount(1, $batch['operations']);
    [$callback, $args] = $batch['operations'][0];
    $this->assertSame([CsvImportBatch::class, 'deleteUploadedFile'], $callback);
    $this->assertSame([7], $args);
  }

  /**
   * Build() sets the batch title and static finished callback.
   *
   * @covers ::build
   */
  public function testBuildSetsTitleAndFinishedCallback() {
    $batch = CsvImportBatch::build('ys_migrate.profile_import', $this->rows(1), TRUE, 1, 'profile', 'Importing profiles');

    $this->assertSame('Importing profiles', $batch['title']);
    $this->assertSame([CsvImportBatch::class, 'finished'], $batch['finished']);
  }

  /**
   * ProcessChunk() resolves the import service from the container by id.
   *
   * @covers ::processChunk
   */
  public function testProcessChunkAccumulatesAcrossCalls() {
    $importService = $this->getMockBuilder(\stdClass::class)->addMethods(['processImport'])->getMock();
    $importService->method('processImport')->willReturnOnConsecutiveCalls(
      ['created' => 2, 'skipped' => 1, 'errors' => ['Row 2: boom']],
      ['created' => 3, 'skipped' => 0, 'errors' => []],
    );

    $container = \Drupal::getContainer();
    $container->set('ys_migrate.profile_import', $importService);

    $context = [];
    CsvImportBatch::processChunk('ys_migrate.profile_import', ['row1'], TRUE, 'profile', $context);
    CsvImportBatch::processChunk('ys_migrate.profile_import', ['row2'], TRUE, 'profile', $context);

    $this->assertSame(5, $context['results']['created']);
    $this->assertSame(1, $context['results']['skipped']);
    $this->assertSame(['Row 2: boom'], $context['results']['errors']);
    $this->assertSame('profile', $context['results']['entity_label']);
    $this->assertSame([], $context['results']['needs_media']);
  }

  /**
   * ProcessChunk() merges 'needs_media' when the underlying service returns it.
   *
   * @covers ::processChunk
   */
  public function testProcessChunkMergesNeedsMedia() {
    $importService = $this->getMockBuilder(\stdClass::class)->addMethods(['processImport'])->getMock();
    $importService->method('processImport')->willReturnOnConsecutiveCalls(
      ['created' => 1, 'skipped' => 0, 'errors' => [], 'needs_media' => ['Resource A']],
      ['created' => 1, 'skipped' => 0, 'errors' => [], 'needs_media' => ['Resource B']],
    );

    $container = \Drupal::getContainer();
    $container->set('ys_migrate.resource_import', $importService);

    $context = [];
    CsvImportBatch::processChunk('ys_migrate.resource_import', ['row1'], TRUE, 'resource', $context);
    CsvImportBatch::processChunk('ys_migrate.resource_import', ['row2'], TRUE, 'resource', $context);

    $this->assertSame(['Resource A', 'Resource B'], $context['results']['needs_media']);
  }

  /**
   * DeleteUploadedFile() deletes the file entity when it still exists.
   *
   * @covers ::deleteUploadedFile
   */
  public function testDeleteUploadedFileDeletesWhenFileExists() {
    $file = $this->getMockBuilder(\stdClass::class)->addMethods(['delete'])->getMock();
    $file->expects($this->once())->method('delete');

    $storage = $this->getMockBuilder(\stdClass::class)->addMethods(['load'])->getMock();
    $storage->method('load')->with(99)->willReturn($file);

    $entityTypeManager = $this->getMockBuilder(\stdClass::class)->addMethods(['getStorage'])->getMock();
    $entityTypeManager->method('getStorage')->with('file')->willReturn($storage);

    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entityTypeManager);

    $context = [];
    CsvImportBatch::deleteUploadedFile(99, $context);
  }

  /**
   * DeleteUploadedFile() does nothing if the file was already removed.
   *
   * @covers ::deleteUploadedFile
   */
  public function testDeleteUploadedFileSkipsMissingFile() {
    $storage = $this->getMockBuilder(\stdClass::class)->addMethods(['load'])->getMock();
    $storage->method('load')->with(99)->willReturn(NULL);

    $entityTypeManager = $this->getMockBuilder(\stdClass::class)->addMethods(['getStorage'])->getMock();
    $entityTypeManager->method('getStorage')->with('file')->willReturn($storage);

    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entityTypeManager);

    // No exception, nothing to assert beyond "this does not throw".
    $context = [];
    CsvImportBatch::deleteUploadedFile(99, $context);
    $this->addToAssertionCount(1);
  }

  /**
   * BuildMessages() reports created and skipped counts using the given label.
   *
   * @covers ::buildMessages
   */
  public function testBuildMessagesReportsCreatedAndSkipped() {
    $messages = CsvImportBatch::buildMessages([
      'entity_label' => 'profile',
      'created' => 4,
      'skipped' => 2,
      'errors' => [],
      'needs_media' => [],
    ]);

    $this->assertCount(2, $messages);
    $this->assertSame('addStatus', $messages[0][0]);
    $this->assertEquals(new TranslatableMarkup('Created @count @label(s).', ['@count' => 4, '@label' => 'profile']), $messages[0][1]);
    $this->assertSame('addWarning', $messages[1][0]);
    $this->assertEquals(
      new TranslatableMarkup('Skipped @count duplicate @label(s).', ['@count' => 2, '@label' => 'profile']),
      $messages[1][1]
    );
  }

  /**
   * BuildMessages() omits created/skipped lines when both counts are zero.
   *
   * @covers ::buildMessages
   */
  public function testBuildMessagesOmitsZeroCounts() {
    $messages = CsvImportBatch::buildMessages([
      'entity_label' => 'profile',
      'created' => 0,
      'skipped' => 0,
      'errors' => [],
      'needs_media' => [],
    ]);

    $this->assertSame([], $messages);
  }

  /**
   * BuildMessages() surfaces the needs_media titles as a warning.
   *
   * @covers ::buildMessages
   */
  public function testBuildMessagesReportsNeedsMedia() {
    $messages = CsvImportBatch::buildMessages([
      'entity_label' => 'resource',
      'created' => 1,
      'skipped' => 0,
      'errors' => [],
      'needs_media' => ['Resource A', 'Resource B'],
    ]);

    $needsMediaMessages = array_values(array_filter($messages, fn($m) => str_contains((string) $m[1], 'Resource Media')));
    $this->assertCount(1, $needsMediaMessages);
    $this->assertSame('addWarning', $needsMediaMessages[0][0]);
    $this->assertStringContainsString('Resource A, Resource B', (string) $needsMediaMessages[0][1]);
  }

  /**
   * BuildMessages() forwards each error as its own addError entry, verbatim.
   *
   * @covers ::buildMessages
   */
  public function testBuildMessagesForwardsErrorsVerbatim() {
    $messages = CsvImportBatch::buildMessages([
      'entity_label' => 'profile',
      'created' => 0,
      'skipped' => 0,
      'errors' => ['Row 2: Something went wrong.', 'Row 5: Also wrong.'],
      'needs_media' => [],
    ]);

    $this->assertSame('addError', $messages[0][0]);
    $this->assertSame('Row 2: Something went wrong.', $messages[0][1]);
    $this->assertSame('addError', $messages[1][0]);
    $this->assertSame('Row 5: Also wrong.', $messages[1][1]);
  }

  /**
   * Finished() dispatches buildMessages() output through the messenger.
   *
   * @covers ::finished
   */
  public function testFinishedDispatchesMessagesThroughMessenger() {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->once())->method('addWarning');
    $messenger->expects($this->never())->method('addError');

    $container = \Drupal::getContainer();
    $container->set('messenger', $messenger);

    CsvImportBatch::finished(TRUE, [
      'entity_label' => 'profile',
      'created' => 3,
      'skipped' => 1,
      'errors' => [],
      'needs_media' => [],
    ], []);
  }

  /**
   * Finished() reports a single error and does not attempt to build messages.
   *
   * $success is FALSE when the batch itself did not complete (e.g. a PHP
   * fatal partway through), and $results may not have the expected shape.
   *
   * @covers ::finished
   */
  public function testFinishedReportsBatchFailure() {
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $messenger->expects($this->never())->method('addStatus');

    $container = \Drupal::getContainer();
    $container->set('messenger', $messenger);

    CsvImportBatch::finished(FALSE, [], []);
  }

}
