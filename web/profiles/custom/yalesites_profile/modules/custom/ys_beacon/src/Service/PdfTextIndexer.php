<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Extracts PDF text into a media field for AI search indexing.
 *
 * The actual parsing is queued (see the PdfTextExtraction queue worker) so a
 * large upload never blocks the editorial save. This service holds the
 * decision and storage logic; it is the single place that knows which media
 * qualify and where the text lands.
 */
class PdfTextIndexer {

  /**
   * The media field that stores extracted PDF text.
   */
  public const FIELD = 'field_ai_pdf_text';

  /**
   * The key/value collection recording which file each attempt was made on.
   */
  public const ATTEMPT_COLLECTION = 'ys_beacon.pdf_extraction';

  /**
   * Fallback maximum PDF size to extract, in bytes, when unset in config.
   */
  protected const DEFAULT_MAX_BYTES = 20971520;

  /**
   * Records the source file id each media item was last attempted against.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected KeyValueStoreInterface $attempts;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected PdfTextExtractorInterface $extractor,
    protected BeaconIndexability $indexability,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected EntityFieldManagerInterface $entityFieldManager,
    KeyValueFactoryInterface $keyValueFactory,
  ) {
    $this->attempts = $keyValueFactory->get(self::ATTEMPT_COLLECTION);
  }

  /**
   * Whether a media entity is a PDF whose text should be extracted.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return bool
   *   TRUE for a PDF document media that has the storage field and has not
   *   opted out of AI indexing.
   */
  public function isExtractable(MediaInterface $media): bool {
    if (!$media->hasField(self::FIELD)) {
      return FALSE;
    }
    // Respect the editor opt-out; never extract content marked AI-disabled.
    if ($this->indexability->isIndexingDisabled($media)) {
      return FALSE;
    }
    return $this->sourceFile($media)?->getMimeType() === 'application/pdf';
  }

  /**
   * Whether a media entity still needs its PDF text extracted.
   *
   * This is the single trigger rule, shared by the insert and update hooks and
   * by the backfill: extraction is owed whenever the media is an extractable
   * PDF that has not yet been attempted against the file it currently holds.
   * Because the attempt is recorded before the extracted text is written back,
   * that write - itself a media save - never re-queues the work; because the
   * record is keyed to the source file id, replacing the file does.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return bool
   *   TRUE when extraction should be queued or run for this media.
   */
  public function needsExtraction(MediaInterface $media): bool {
    if (!$this->isExtractable($media)) {
      return FALSE;
    }
    return $this->attempts->get((string) $media->id()) !== $this->sourceFid($media);
  }

  /**
   * Whether an indexable PDF has no extracted text to offer the index.
   *
   * A document indexed with an empty text field contributes only its filename
   * and metadata, which still matches filename-shaped queries and returns a
   * citation with nothing behind it. Callers use this to keep such a document
   * out of the index until its text lands.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return bool
   *   TRUE for an extractable PDF whose stored text is empty.
   */
  public function lacksExtractedText(MediaInterface $media): bool {
    return $this->isExtractable($media)
      && trim((string) $media->get(self::FIELD)->value) === '';
  }

  /**
   * Returns the ids of media that may still be owed text extraction.
   *
   * Narrowed to media that could plausibly need work - the bundles carrying the
   * storage field and holding no text are selected in the database, then
   * already-attempted ones are dropped in PHP - so a caller does not load the
   * whole media library to find out. It is a filter,
   * not the decision: needsExtraction() is still the authority on each item.
   * A replaced file whose previous extraction was attempted is excluded here
   * and covered by the update hook instead.
   *
   * @return array
   *   Media ids, as strings.
   */
  public function pendingMediaIds(): array {
    $bundles = $this->entityFieldManager->getFieldMap()['media'][self::FIELD]['bundles'] ?? [];
    if (!$bundles) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(FALSE);
    $empty = $query->orConditionGroup()
      ->notExists(self::FIELD)
      ->condition(self::FIELD, '');
    $ids = $query->condition('bundle', $bundles, 'IN')
      ->condition($empty)
      ->execute();

    $attempted = $this->attempts->getAll();
    return array_values(array_filter(
      array_map('strval', $ids),
      static fn (string $id): bool => !array_key_exists($id, $attempted)
    ));
  }

  /**
   * Extracts and stores the text for a queued media id.
   *
   * @param int|string $media_id
   *   The media entity id.
   */
  public function extractAndStore(int|string $media_id): void {
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    // needsExtraction() rather than isExtractable(): the queue does not
    // de-duplicate, and the trigger now fires on every media update, so an
    // editor who saves a document several times before cron runs leaves
    // several items pointing at it. Without this the second and later items
    // re-parse the whole PDF for a result that is already stored.
    if (!$media instanceof MediaInterface || !$this->needsExtraction($media)) {
      return;
    }

    // Record the attempt before doing anything else: every path from here is a
    // final decision about this file, and an unrecorded one would be selected
    // again by every later save, sweep and backfill. That matters most for the
    // unreadable-file case below, which is not rare in bulk - a migrated site
    // with dangling file rows would otherwise carry a backlog that can never
    // drain. Restoring a missing file does not re-trigger extraction on its
    // own; replacing the file does, and `drush ys_beacon:extract-pdf-text
    // --force` clears the records outright.
    $this->recordAttempt($media);

    $file = $this->sourceFile($media);
    $path = $file ? $this->fileSystem->realpath($file->getFileUri()) : NULL;
    if (!$file || !$path || !is_file($path)) {
      // isExtractable() already confirmed a PDF source file, so an unresolvable
      // path here is an actionable anomaly (missing/moved file), not a routine
      // skip. Log it to match the other non-happy paths in this method.
      $this->logger->warning('Skipped PDF text extraction for media @id: the source file could not be read from disk.', [
        '@id' => $media->id(),
      ]);
      return;
    }

    $max = (int) ($this->configFactory->get('ys_beacon.settings')->get('pdf_extraction_max_bytes') ?: self::DEFAULT_MAX_BYTES);
    if ((int) $file->getSize() > $max) {
      $this->logger->warning('Skipped PDF text extraction for media @id: file size @size exceeds the @max byte limit.', [
        '@id' => $media->id(),
        '@size' => $file->getSize(),
        '@max' => $max,
      ]);
      return;
    }

    try {
      $text = $this->extractor->extractText($path);
      if ($text === '') {
        // A successful parse with no text means an image-only (scanned) PDF
        // with no text layer: expected, log at info. Only reached when
        // extraction did not throw, so a corrupt PDF is never mislabelled.
        $this->logger->info('No extractable text in PDF for media @id (likely image-only).', ['@id' => $media->id()]);
      }
    }
    catch (\RuntimeException $e) {
      // A corrupt or unreadable PDF must not crash the queue; record it and
      // leave the field empty so indexing simply has no body for this file.
      $this->logger->warning('PDF text extraction failed for media @id: @message', [
        '@id' => $media->id(),
        '@message' => $e->getMessage(),
      ]);
      $text = '';
    }

    // Only write when the value actually changes, so storing the result does
    // not churn revisions or re-trigger work.
    if ((string) $media->get(self::FIELD)->value !== $text) {
      $media->set(self::FIELD, $text);
      $media->save();
    }
  }

  /**
   * Forgets the recorded extraction attempt for a media item.
   *
   * @param int|string $media_id
   *   The media entity id.
   */
  public function forgetAttempt(int|string $media_id): void {
    $this->attempts->delete((string) $media_id);
  }

  /**
   * Forgets every recorded extraction attempt, so all documents are retried.
   */
  public function forgetAllAttempts(): void {
    $this->attempts->deleteAll();
  }

  /**
   * Records that this media has been attempted against its current file.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   */
  protected function recordAttempt(MediaInterface $media): void {
    $fid = $this->sourceFid($media);
    if ($fid !== NULL) {
      $this->attempts->set((string) $media->id(), $fid);
    }
  }

  /**
   * Returns the source file id of a media entity, or NULL.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return string|null
   *   The source file id, or NULL when the media has no file source.
   */
  protected function sourceFid(MediaInterface $media): ?string {
    try {
      $fid = $media->getSource()->getSourceFieldValue($media);
      return $fid !== NULL ? (string) $fid : NULL;
    }
    catch (\Throwable $e) {
      // Non-file media source: no file to identify.
      return NULL;
    }
  }

  /**
   * Loads the source file entity behind a media item.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The source file, or NULL when unavailable.
   */
  protected function sourceFile(MediaInterface $media): ?FileInterface {
    $fid = $this->sourceFid($media);
    if ($fid !== NULL && is_numeric($fid)) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      return $file instanceof FileInterface ? $file : NULL;
    }
    return NULL;
  }

}
