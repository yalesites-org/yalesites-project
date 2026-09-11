<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\PdfTextExtractionBatch;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_beacon\Service\PdfTextExtractorInterface;
use Drupal\ys_beacon\Service\PdfTextIndexer;

/**
 * Proves PDF text extraction is queued for every way a document is opted in.
 *
 * Media is excluded from AI indexing by default, so a document is never
 * extractable at upload time and an editor has to opt it in afterwards - which
 * is a plain media update. The trigger this replaces only fired when the source
 * file id changed, so extraction could never run for a document uploaded and
 * opted in through the normal editorial flow (issue #1580). These tests drive
 * the hooks directly rather than enabling ys_beacon, whose dependency graph is
 * heavy, following BeaconImmediateRemovalTest; the indexability, authorization
 * and extractor collaborators are doubled so each transition can be scripted.
 *
 * The sweep is covered here too, because it answers the same question from the
 * other side: it is what picks up the documents the hooks never fired for. One
 * mechanism backs both the backfill and the "Index now" / "Re-index all
 * content" controls, so proving it once proves both. What extraction itself
 * stores is covered by PdfTextIndexerTest.
 *
 * @group ys_beacon
 */
class PdfTextExtractionTriggerTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'image', 'media'];

  /**
   * Whether the doubled indexability service reports media as opted out.
   */
  private bool $optedOut = TRUE;

  /**
   * The text the doubled parser returns.
   */
  private string $extractedText = 'Extracted body text';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system']);

    $media_type = MediaType::create([
      'id' => 'document',
      'label' => 'Document',
      'source' => 'file',
    ]);
    $media_type->save();
    $source_field = $media_type->getSource()->createSourceField($media_type);
    $source_field->getFieldStorageDefinition()->save();
    $source_field->save();
    $media_type->set('source_configuration', ['source_field' => $source_field->getName()])->save();

    FieldStorageConfig::create([
      'field_name' => PdfTextIndexer::FIELD,
      'entity_type' => 'media',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => PdfTextIndexer::FIELD,
      'entity_type' => 'media',
      'bundle' => 'document',
      'label' => 'AI extracted text',
    ])->save();

    // The hooks read ys_beacon.settings directly. That config's schema ships
    // with the (here-disabled) ys_beacon module, so write it straight to the
    // config storage rather than through the schema-validating save path.
    \Drupal::service('config.storage')->write('ys_beacon.settings', [
      'azure_index_name' => 'test-index',
    ]);
    \Drupal::configFactory()->reset('ys_beacon.settings');

    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexingDisabled')->willReturnCallback(fn () => $this->optedOut);
    // The update hook's other job - removing chunks the moment content stops
    // being indexable - bails on still-indexable content, which keeps these
    // tests on the extraction path.
    $indexability->method('isIndexable')->willReturn(TRUE);
    $this->container->set('ys_beacon.indexability', $indexability);

    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn(TRUE);
    $this->container->set('ys_beacon.authorization', $authorization);

    $extractor = $this->createMock(PdfTextExtractorInterface::class);
    $extractor->method('extractText')->willReturnCallback(fn () => $this->extractedText);
    $this->container->set('ys_beacon.pdf_text_indexer', new PdfTextIndexer(
      $this->container->get('entity_type.manager'),
      $this->container->get('file_system'),
      $extractor,
      $indexability,
      $this->container->get('config.factory'),
      $this->container->get('logger.factory')->get('ys_beacon'),
      $this->container->get('entity_field.manager'),
      $this->container->get('keyvalue'),
    ));

    // ys_beacon is not enabled here, so its hooks are included and invoked
    // directly. The file has no include-time side effects.
    require_once dirname(__DIR__, 3) . '/ys_beacon.module';
  }

  /**
   * Opting an existing document in queues its extraction.
   *
   * The reported bug: the opt-in save carries the same source file, so the
   * old file-changed trigger never fired and the document stayed empty.
   */
  public function testOptingInQueuesExtraction(): void {
    $media = $this->createPdfMedia();
    ys_beacon_entity_insert($media);
    $this->assertSame(0, $this->queueDepth(), 'A document uploaded while opted out queues nothing.');

    $this->optedOut = FALSE;
    $media->original = clone $media;
    ys_beacon_entity_update($media);

    $this->assertSame(1, $this->queueDepth(), 'Opting the document in queues its text extraction.');
  }

  /**
   * A document created already opted in is queued at insert.
   *
   * Programmatic and migration paths can set the metatag at insert time.
   */
  public function testDocumentCreatedOptedInIsQueued(): void {
    $this->optedOut = FALSE;
    $media = $this->createPdfMedia();

    ys_beacon_entity_insert($media);

    $this->assertSame(1, $this->queueDepth(), 'A document that is already opted in is queued on creation.');
  }

  /**
   * A document left opted out is never queued, however often it is saved.
   */
  public function testOptedOutDocumentIsNeverQueued(): void {
    $media = $this->createPdfMedia();
    ys_beacon_entity_insert($media);
    $media->original = clone $media;
    ys_beacon_entity_update($media);

    $this->assertSame(0, $this->queueDepth(), 'The editor opt-out must keep the document out of extraction entirely.');
  }

  /**
   * Storing the extracted text does not queue the work again.
   *
   * Extraction saves the media, which is itself an update: without the
   * recorded attempt the new trigger would re-queue on its own output.
   */
  public function testStoringExtractedTextDoesNotRequeue(): void {
    $this->optedOut = FALSE;
    $media = $this->createPdfMedia();
    ys_beacon_entity_insert($media);
    $this->assertSame(1, $this->queueDepth());

    $this->indexer()->extractAndStore($media->id());
    $stored = Media::load($media->id());
    $this->assertSame('Extracted body text', $stored->get(PdfTextIndexer::FIELD)->value);

    $stored->original = clone $stored;
    ys_beacon_entity_update($stored);

    $this->assertSame(1, $this->queueDepth(), 'Writing the extracted text back must not queue extraction again.');
  }

  /**
   * A PDF with no text layer is attempted once, not on every later save.
   */
  public function testImageOnlyPdfIsNotReattempted(): void {
    $this->optedOut = FALSE;
    $this->extractedText = '';
    $media = $this->createPdfMedia();

    $this->indexer()->extractAndStore($media->id());
    $reloaded = Media::load($media->id());
    $reloaded->original = clone $reloaded;
    ys_beacon_entity_update($reloaded);

    $this->assertSame(0, $this->queueDepth(), 'A scanned PDF that legitimately yields no text is not parsed again.');
    $this->assertSame([], $this->indexer()->pendingMediaIds(), 'An attempted document drops out of the backfill sweep.');
  }

  /**
   * Replacing the file re-queues extraction for the new document.
   */
  public function testReplacingTheFileRequeuesExtraction(): void {
    $this->optedOut = FALSE;
    $media = $this->createPdfMedia();
    $this->indexer()->extractAndStore($media->id());

    $replacement = $this->createPdfFile('replacement.pdf');
    $reloaded = Media::load($media->id());
    $reloaded->original = clone $reloaded;
    $reloaded->set('field_media_file', ['target_id' => $replacement->id()]);
    ys_beacon_entity_update($reloaded);

    $this->assertSame(1, $this->queueDepth(), 'A replaced file is a different document and is extracted again.');
  }

  /**
   * Documents still owed extraction are the ones the backfill sweeps.
   */
  public function testPendingMediaIdsListsUnextractedDocuments(): void {
    $this->optedOut = FALSE;
    $first = $this->createPdfMedia();
    $second = $this->createPdfMedia('second.pdf');

    $this->assertEqualsCanonicalizing(
      [(string) $first->id(), (string) $second->id()],
      $this->indexer()->pendingMediaIds(),
      'Both documents are waiting for extraction.',
    );

    $this->indexer()->extractAndStore($first->id());

    $this->assertSame(
      [(string) $second->id()],
      $this->indexer()->pendingMediaIds(),
      'An extracted document is no longer swept.',
    );
  }

  /**
   * The queued payload is the media id the worker expects.
   */
  public function testQueuedItemCarriesTheMediaId(): void {
    $this->optedOut = FALSE;
    $media = $this->createPdfMedia();
    ys_beacon_entity_insert($media);

    $item = \Drupal::queue('ys_beacon_pdf_text_extraction')->claimItem();

    $this->assertSame(['media_id' => $media->id()], $item->data);
  }

  /**
   * The sweep extracts every document still waiting for it.
   *
   * This is the backfill and the "Index now" pre-pass: one mechanism, so the
   * documents no editorial save ever queued are picked up by both.
   */
  public function testSweepExtractsPendingDocuments(): void {
    $this->optedOut = FALSE;
    $first = $this->createPdfMedia();
    $second = $this->createPdfMedia('second.pdf');

    $context = [];
    PdfTextExtractionBatch::process($this->indexer()->pendingMediaIds(), $context);

    $this->assertSame('Extracted body text', Media::load($first->id())->get(PdfTextIndexer::FIELD)->value);
    $this->assertSame('Extracted body text', Media::load($second->id())->get(PdfTextIndexer::FIELD)->value);
    $this->assertSame(2, $context['results']['checked']);
  }

  /**
   * The sweep never extracts a document the editor opted out of.
   */
  public function testSweepSkipsOptedOutDocuments(): void {
    $media = $this->createPdfMedia();

    $context = [];
    PdfTextExtractionBatch::process([$media->id()], $context);

    $this->assertSame('', (string) Media::load($media->id())->get(PdfTextIndexer::FIELD)->value);
  }

  /**
   * The sweep is chunked, so a large library is not one batch operation.
   *
   * A batch operation cannot be interrupted part-way, so a chunk has to stay
   * small enough that one request only ever parses one document.
   */
  public function testSweepIsChunkedAcrossOperations(): void {
    $batch = PdfTextExtractionBatch::build(range(1, 25));

    $this->assertCount(25, $batch['operations'], '25 documents are split one per operation.');
  }

  /**
   * No batch is started when no document needs extraction.
   */
  public function testNoBatchWhenNothingIsPending(): void {
    $this->assertNull(PdfTextExtractionBatch::build([]));
  }

  /**
   * The PDF text indexer under test.
   */
  private function indexer(): PdfTextIndexer {
    return $this->container->get('ys_beacon.pdf_text_indexer');
  }

  /**
   * The number of items waiting in the extraction queue.
   */
  private function queueDepth(): int {
    return (int) \Drupal::queue('ys_beacon_pdf_text_extraction')->numberOfItems();
  }

  /**
   * Creates a saved PDF file entity.
   */
  private function createPdfFile(string $filename): File {
    $uri = 'public://' . $filename;
    file_put_contents($uri, 'dummy');
    $file = File::create(['uri' => $uri, 'filename' => $filename, 'filemime' => 'application/pdf']);
    $file->save();
    return $file;
  }

  /**
   * Creates a document media entity wrapping a saved PDF file.
   */
  private function createPdfMedia(string $filename = 'report.pdf'): Media {
    $media = Media::create([
      'bundle' => 'document',
      'name' => $filename,
      'field_media_file' => ['target_id' => $this->createPdfFile($filename)->id()],
    ]);
    $media->save();
    return $media;
  }

}
