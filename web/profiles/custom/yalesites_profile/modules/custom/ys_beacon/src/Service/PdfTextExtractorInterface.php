<?php

namespace Drupal\ys_beacon\Service;

/**
 * Extracts the text layer from a PDF file.
 *
 * Abstracts the PDF parsing library so the extraction orchestration
 * (PdfTextIndexer) can be tested without it and the library can be swapped.
 */
interface PdfTextExtractorInterface {

  /**
   * Extracts the text content of a PDF.
   *
   * @param string $path
   *   An absolute local filesystem path to the PDF.
   *
   * @return string
   *   The extracted text, or an empty string for an image-only PDF that has
   *   no text layer.
   *
   * @throws \RuntimeException
   *   When the file cannot be parsed (corrupt or unreadable).
   */
  public function extractText(string $path): string;

}
