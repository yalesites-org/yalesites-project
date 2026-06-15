<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
   * Fallback maximum PDF size to extract, in bytes, when unset in config.
   */
  protected const DEFAULT_MAX_BYTES = 20971520;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected PdfTextExtractorInterface $extractor,
    protected BeaconIndexability $indexability,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
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
   * Extracts and stores the text for a queued media id.
   *
   * @param int|string $media_id
   *   The media entity id.
   */
  public function extractAndStore(int|string $media_id): void {
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface || !$this->isExtractable($media)) {
      return;
    }

    $file = $this->sourceFile($media);
    $path = $file ? $this->fileSystem->realpath($file->getFileUri()) : NULL;
    if (!$file || !$path || !is_file($path)) {
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
   * Loads the source file entity behind a media item.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The source file, or NULL when unavailable.
   */
  protected function sourceFile(MediaInterface $media): ?FileInterface {
    try {
      $fid = $media->getSource()->getSourceFieldValue($media);
      if ($fid && is_numeric($fid)) {
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        return $file instanceof FileInterface ? $file : NULL;
      }
    }
    catch (\Throwable $e) {
      // Non-file media source: not extractable.
    }
    return NULL;
  }

}
