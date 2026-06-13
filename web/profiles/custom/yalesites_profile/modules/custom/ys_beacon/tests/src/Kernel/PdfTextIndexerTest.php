<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_beacon\Service\PdfTextExtractorInterface;
use Drupal\ys_beacon\Service\PdfTextIndexer;

/**
 * Tests PDF text extraction storage, opt-out, and size limits.
 *
 * The PDF parser and the metatag-based indexability check are stubbed; this
 * exercises the indexer's real interaction with media, files, and the storage
 * field.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\PdfTextIndexer
 */
class PdfTextIndexerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'image', 'media'];

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
  }

  /**
   * Extracted text is stored on the media field.
   *
   * @covers ::extractAndStore
   * @covers ::isExtractable
   */
  public function testExtractedTextIsStored(): void {
    $media = $this->createPdfMedia('hello.pdf', 'application/pdf', 'dummy');
    $indexer = $this->indexer($this->stubExtractor('Extracted body text'));

    $indexer->extractAndStore($media->id());

    $reloaded = Media::load($media->id());
    $this->assertSame('Extracted body text', $reloaded->get(PdfTextIndexer::FIELD)->value);
  }

  /**
   * An image-only PDF (no text layer) stores an empty string, not an error.
   *
   * @covers ::extractAndStore
   */
  public function testImageOnlyPdfStoresEmptyString(): void {
    $media = $this->createPdfMedia('scan.pdf', 'application/pdf', 'dummy');
    $this->indexer($this->stubExtractor(''))->extractAndStore($media->id());

    $this->assertSame('', (string) Media::load($media->id())->get(PdfTextIndexer::FIELD)->value);
  }

  /**
   * A non-PDF document is not extracted.
   *
   * @covers ::isExtractable
   */
  public function testNonPdfIsNotExtractable(): void {
    $media = $this->createPdfMedia('notes.txt', 'text/plain', 'plain');
    $this->assertFalse($this->indexer($this->stubExtractor('should not run'))->isExtractable($media));
  }

  /**
   * Content opted out via ai_disable_indexing is never extracted.
   *
   * @covers ::isExtractable
   */
  public function testOptedOutMediaIsNotExtractable(): void {
    $media = $this->createPdfMedia('private.pdf', 'application/pdf', 'dummy');
    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexingDisabled')->willReturn(TRUE);

    $this->assertFalse($this->indexer($this->stubExtractor('x'), $indexability)->isExtractable($media));
  }

  /**
   * A PDF larger than the limit is skipped, leaving the field empty.
   *
   * The default limit applies (no ys_beacon config is enabled in this test);
   * the file reports a size above it.
   *
   * @covers ::extractAndStore
   */
  public function testOversizedPdfIsSkipped(): void {
    $media = $this->createPdfMedia('big.pdf', 'application/pdf', 'more than one byte');
    // A 1-byte limit makes any real file oversized.
    $indexer = $this->indexer($this->stubExtractor('should not run'), NULL, 1);

    $indexer->extractAndStore($media->id());

    $this->assertSame('', (string) Media::load($media->id())->get(PdfTextIndexer::FIELD)->value);
  }

  /**
   * Creates a document media entity wrapping a written file.
   */
  private function createPdfMedia(string $filename, string $mime, string $contents): Media {
    $uri = 'public://' . $filename;
    file_put_contents($uri, $contents);
    $file = File::create(['uri' => $uri, 'filename' => $filename, 'filemime' => $mime]);
    $file->save();

    $media = Media::create([
      'bundle' => 'document',
      'name' => $filename,
      'field_media_file' => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * A stub PDF extractor returning a fixed string.
   */
  private function stubExtractor(string $text): PdfTextExtractorInterface {
    $extractor = $this->createMock(PdfTextExtractorInterface::class);
    $extractor->method('extractText')->willReturn($text);
    return $extractor;
  }

  /**
   * Builds a PdfTextIndexer with real services and stubbed collaborators.
   *
   * @param \Drupal\ys_beacon\Service\PdfTextExtractorInterface $extractor
   *   The (stubbed) extractor.
   * @param \Drupal\ys_beacon\Service\BeaconIndexability|null $indexability
   *   Indexability stub; defaults to "not opted out".
   * @param int|null $maxBytes
   *   When set, a config stub reports this PDF size limit (ys_beacon is not
   *   installed here, so its real config object is unavailable).
   */
  private function indexer(PdfTextExtractorInterface $extractor, ?BeaconIndexability $indexability = NULL, ?int $maxBytes = NULL): PdfTextIndexer {
    if (!$indexability) {
      $indexability = $this->createMock(BeaconIndexability::class);
      $indexability->method('isIndexingDisabled')->willReturn(FALSE);
    }
    $configFactory = $this->container->get('config.factory');
    if ($maxBytes !== NULL) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->with('pdf_extraction_max_bytes')->willReturn($maxBytes);
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->with('ys_beacon.settings')->willReturn($config);
    }
    return new PdfTextIndexer(
      $this->container->get('entity_type.manager'),
      $this->container->get('file_system'),
      $extractor,
      $indexability,
      $configFactory,
      $this->container->get('logger.factory')->get('ys_beacon'),
    );
  }

}
