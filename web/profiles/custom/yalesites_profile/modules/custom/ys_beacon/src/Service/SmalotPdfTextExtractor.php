<?php

namespace Drupal\ys_beacon\Service;

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
   * {@inheritdoc}
   */
  public function extractText(string $path): string {
    try {
      $document = (new Parser())->parseFile($path);
      return trim((string) preg_replace('/\s+/', ' ', $document->getText()));
    }
    catch (\Throwable $e) {
      // Wrap parser/library errors so callers catch one exception type.
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
  }

}
