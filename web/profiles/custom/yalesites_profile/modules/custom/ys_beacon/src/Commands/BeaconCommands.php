<?php

namespace Drupal\ys_beacon\Commands;

use Drupal\ys_beacon\PdfTextExtractionBatch;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drupal\ys_beacon\Service\PdfTextIndexer;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Beacon Azure AI Search operations.
 */
class BeaconCommands extends DrushCommands {

  public function __construct(
    protected BeaconIndexManager $indexManager,
    protected PdfTextIndexer $pdfTextIndexer,
  ) {
    parent::__construct();
  }

  /**
   * Repoints this site to a different Azure AI Search service.
   *
   * Pins the given endpoint, then creates this site's index on it and queues a
   * full reindex. The paired API key is resolved automatically from the
   * "azure_ai_search_api_keys" map, which must already contain an entry for the
   * new endpoint (the command refuses otherwise). The index on the previous
   * service is left in place - orphaned - for manual cleanup.
   *
   * @param string $url
   *   The new Azure AI Search endpoint URL.
   *
   * @command ys_beacon:repin
   * @aliases ys-beacon-repin
   * @usage ys_beacon:repin https://new-service.search.windows.net
   *   Repoints this site's Beacon index to the given Azure AI Search service.
   */
  public function repin(string $url): void {
    $name = $this->indexManager->repin($url);
    $this->logger()->success(dt('Repinned Beacon to @url; index "@name" provisioned there and content queued for reindex.', [
      '@url' => $url,
      '@name' => $name,
    ]));
  }

  /**
   * Extracts text from PDF documents that have never been processed.
   *
   * The one-time backfill for documents that predate working extraction. Text
   * is extracted in place and stored on the media, which re-tracks the item
   * for indexing on its own. Documents already attempted are skipped, so the
   * command is safe to re-run; --force clears those records so documents that
   * were attempted but stored no text are tried again - after raising
   * pdf_extraction_max_bytes, or once missing files are restored. A document
   * that already holds text is never re-parsed either way; replace the file to
   * force that.
   *
   * @param array $options
   *   The command options.
   *
   * @command ys_beacon:extract-pdf-text
   * @aliases ys-beacon-extract-pdf-text
   * @option force Retry documents that were attempted but stored no text.
   * @usage ys_beacon:extract-pdf-text
   *   Extracts text from every PDF document still waiting for it.
   * @usage ys_beacon:extract-pdf-text --force
   *   Retries documents that were attempted but produced no text.
   */
  public function extractPdfText(array $options = ['force' => FALSE]): void {
    if ($options['force']) {
      $this->pdfTextIndexer->forgetAllAttempts();
    }
    $batch = PdfTextExtractionBatch::build($this->pdfTextIndexer->pendingMediaIds());
    if (!$batch) {
      $this->logger()->success(dt('No PDF documents are waiting for text extraction.'));
      return;
    }
    batch_set($batch);
    drush_backend_batch_process();
    $this->logger()->success(dt('PDF text extraction finished.'));
  }

}
