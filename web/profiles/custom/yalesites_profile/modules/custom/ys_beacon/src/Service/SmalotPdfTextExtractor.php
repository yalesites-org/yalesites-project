<?php

namespace Drupal\ys_beacon\Service;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Extracts PDF text with the smalot/pdfparser library.
 *
 * The smalot/pdfparser library is a pure-PHP parser (no system binaries such
 * as pdftotext), which keeps extraction working on the managed Pantheon
 * platform.
 * It reads the embedded text layer; image-only (scanned) PDFs have none, so it
 * returns an empty string rather than performing OCR.
 */
class SmalotPdfTextExtractor implements PdfTextExtractorInterface {

  /**
   * Per-stream decompression ceiling, in bytes.
   *
   * Passed to the parser as a decode memory limit so a decompression-bomb
   * object stream (small on disk, gigabytes once inflated) fails as a
   * catchable parser error instead of exhausting PHP memory and killing the
   * cron worker. Generous for any legitimate text layer within the
   * separately capped upload size.
   */
  protected const DECODE_MEMORY_LIMIT = 104857600;

  /**
   * {@inheritdoc}
   */
  public function extractText(string $path): string {
    try {
      $config = new Config();
      // Only the text layer is wanted; retaining embedded image bytes is pure
      // memory waste and a primary out-of-memory driver for image-heavy PDFs.
      $config->setRetainImageContent(FALSE);
      // Bound per-stream inflation so a crafted PDF cannot expand unbounded.
      $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);
      $document = (new Parser([], $config))->parseFile($path);
      return trim((string) preg_replace('/\s+/', ' ', $document->getText()));
    }
    catch (\Throwable $e) {
      // Wrap parser/library errors so callers catch one exception type.
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
  }

}
