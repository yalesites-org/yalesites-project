<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Fails runs whose batch stopped writing and never reported a status.
 *
 * A tester run is inserted as 'processing' and moved to its final status by
 * AiTesterBatch::finished(). That callback only runs if the batch reaches its
 * end, so a batch whose AJAX connection dies mid-request - the browser reports
 * "An AJAX HTTP request terminated abnormally" with readyState 0, and nothing
 * reaches the server logs because the server never failed - leaves the row on
 * 'processing' with nothing left to ever move it.
 *
 * That state is a dead end rather than a cosmetic wrong label. AiTesterForm
 * hides the Rerun link while a run is processing and AiTesterRerunForm refuses
 * the rerun outright, so the interrupted run cannot be resumed, re-asked, or
 * used as a comparison baseline; and because a rerun still on 'processing'
 * counts as in-flight for its source run, one dropped connection also blocks
 * every later rerun of that source.
 *
 * Reconciling marks such a run 'failed', which is what the same batch would
 * have recorded had it reported: no questions confirmed complete. That restores
 * the Rerun action, so the run's answered questions can be re-asked.
 */
class StaleRunReconciler {

  /**
   * Seconds without a write after which a processing run is abandoned.
   *
   * Sized against the longest gap a live submission can leave between two
   * heartbeats. AiTesterBatch heartbeats after every question, and one question
   * is bounded by AiTesterRetry: at most MAX_QUESTION_DELAY_MS (10s) of pacing
   * plus MAX_TOTAL_RETRY_WAIT_MS (12s) of retry waits around at most
   * MAX_ATTEMPTS upstream calls, inside a request the platform terminates at
   * 120 seconds.
   *
   * That bound holds only because the heartbeat is per submission rather than
   * per run - see AiTesterBatch::touch(). A run queued behind a sibling's batch
   * set writes nothing of its own until that sibling's every question is done,
   * which is unbounded in question count; if the heartbeat is ever narrowed to
   * the answering run, no value of this constant can cover the "run both
   * assistants" case.
   *
   * The margin is deliberate: reaping early abandons a run that is still
   * working, while reaping late only delays a recovery.
   */
  const STALE_AFTER_SECONDS = 600;

  /**
   * Constructs the reconciler.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Fails every processing run that has stopped writing.
   *
   * Safe to call on any request that reads run status; it does nothing at all
   * unless a run is genuinely abandoned.
   *
   * @return int
   *   How many runs were reconciled.
   */
  public function reconcile(): int {
    $runs = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id', 'changed'])
      ->condition('status', 'processing')
      ->execute()
      ->fetchAll();

    $rows = array_map(
      static fn(object $run): array => [
        'id' => (int) $run->id,
        'changed' => (int) $run->changed,
      ],
      $runs
    );

    $stale = $this->staleIds($rows, $this->time->getRequestTime());
    if ($stale === []) {
      return 0;
    }

    $affected = (int) $this->database->update('ys_ai_tester_run')
      ->fields(['status' => 'failed'])
      ->condition('id', $stale, 'IN')
      // Guards against the batch reporting its own status between the select
      // and this update: whoever writes a final status first wins, and this
      // never overwrites it.
      ->condition('status', 'processing')
      ->execute();

    // Reported from the update rather than from the candidate list, so a run
    // that finished or was reconciled concurrently is not claimed here.
    if ($affected < 1) {
      return 0;
    }

    $this->loggerFactory->get('ys_ai_tester')->warning(
      'Marked @count abandoned AI tester run(s) as failed after @seconds seconds with no progress (candidate run ids: @ids). The batch stopped without reporting, which usually means its connection dropped mid-run.',
      [
        '@count' => $affected,
        '@seconds' => static::STALE_AFTER_SECONDS,
        '@ids' => implode(', ', $stale),
      ]
    );

    return $affected;
  }

  /**
   * Picks the runs that have been silent too long.
   *
   * Takes loaded rows rather than querying, so the threshold decision is
   * testable without a database - the same split RunComparator uses.
   *
   * @param array $runs
   *   Processing runs, each an array with 'id' and 'changed' keys.
   * @param int $now
   *   The current request time.
   *
   * @return int[]
   *   Ids of the runs to fail, in the order given.
   */
  public function staleIds(array $runs, int $now): array {
    $stale = [];
    foreach ($runs as $run) {
      // Strictly greater, so a run whose last write lands exactly on the
      // threshold is still treated as alive.
      if (($now - (int) $run['changed']) > static::STALE_AFTER_SECONDS) {
        $stale[] = (int) $run['id'];
      }
    }

    return $stale;
  }

}
