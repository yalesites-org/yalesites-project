<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\ai\Entity\AiGuardrailModeEnum;
use Drupal\ai\Guardrail\Result\GuardrailResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Records aggregate counts of guardrail-relevant Beacon chat events.
 *
 * Beacon deliberately does not store conversations - the platform tells users
 * they are not saved - so this store holds counters and nothing else. There is
 * no column a question or an answer could be written into.
 *
 * The recording API is deliberately closed: every public record*() method takes
 * either no argument or a bounded identifier (a detector pattern name, a
 * guardrail label from admin config), and the key-assembling ::record() is
 * protected. A caller therefore has no way to pass conversation text in, even
 * by accident - which matters because the obvious future caller, a streaming
 * guardrail counting its own stops, has the offending text right there in hand.
 * See yalesites-org/YaleSites-Internal#1469.
 *
 * This class also owns the whole key vocabulary - the event names, the
 * dimension prefixes, and which keys are totals rather than breakdowns - so
 * callers never assemble a key and the report never infers structure from one.
 *
 * Counts are bucketed per UTC day so a spike is visible as a trend; a single
 * total since install cannot show a jailbreak campaign. Buckets are pruned to
 * self::RETENTION_DAYS.
 *
 * Storage is a table rather than State for two reasons. State's
 * read-modify-write is not atomic, so concurrent turns on a public,
 * unauthenticated endpoint would silently lose increments; and every state
 * write invalidates the whole state cache, which is a poor trade on a
 * high-traffic route. A keyed table increments atomically in the database
 * (UPDATE ... SET event_count = event_count + 1) and its composite primary key
 * makes the insert race harmless.
 *
 * Every operation degrades quietly: a chat turn must never fail, and must never
 * slow down, because telemetry could not be written.
 */
class GuardrailTelemetry {

  /**
   * The table holding the counters.
   */
  public const TABLE = 'ys_beacon_telemetry';

  /**
   * A chat turn was served.
   *
   * The denominator for every other counter: without it a rise in refusals
   * cannot be told apart from a rise in traffic.
   */
  public const EVENT_TURNS = 'turns';

  /**
   * The model declined to answer.
   */
  public const EVENT_REFUSAL = 'refusal';

  /**
   * A guardrail returned a stop.
   *
   * Counted per stopping guardrail rather than per turn, so a turn stopped by
   * two guardrails contributes two.
   */
  public const EVENT_GUARDRAIL_STOP = 'guardrail_stop';

  /**
   * Retrieval returned no citations, so the answer was ungrounded.
   */
  public const EVENT_ZERO_CITATIONS = 'zero_citations';

  /**
   * The question matched a known prompt-injection pattern.
   */
  public const EVENT_INJECTION_PATTERN = 'injection_pattern';

  /**
   * Every event type, in reporting order.
   *
   * The report tells an event total from a dimensioned breakdown by consulting
   * this list, so adding a new event type here is all it takes for it to be
   * reported as a total rather than listed among the breakdowns.
   */
  public const EVENTS = [
    self::EVENT_TURNS,
    self::EVENT_REFUSAL,
    self::EVENT_GUARDRAIL_STOP,
    self::EVENT_ZERO_CITATIONS,
    self::EVENT_INJECTION_PATTERN,
  ];

  /**
   * How many days of buckets are kept.
   */
  public const RETENTION_DAYS = 90;

  /**
   * Default reporting window, in days.
   */
  public const DEFAULT_REPORT_DAYS = 30;

  /**
   * Maximum stored event key length, matching the column width.
   */
  protected const MAX_KEY_LENGTH = 160;

  /**
   * Whether the retention prune has already run for this request.
   *
   * @var bool
   */
  protected bool $pruned = FALSE;

  /**
   * Constructs the telemetry recorder.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to pick the day bucket.
   * @param \Psr\Log\LoggerInterface $logger
   *   The ys_beacon logger channel.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Records that a chat turn was served.
   */
  public function recordTurn(): void {
    $this->record(self::EVENT_TURNS);
  }

  /**
   * Records that the model declined to answer.
   */
  public function recordRefusal(): void {
    $this->record(self::EVENT_REFUSAL);
  }

  /**
   * Records that retrieval produced no citations for a turn.
   */
  public function recordZeroCitations(): void {
    $this->record(self::EVENT_ZERO_CITATIONS);
  }

  /**
   * Records that a question matched an injection pattern.
   *
   * @param string $pattern
   *   The pattern name from GuardrailSignalDetector. A fixed name from that
   *   class's own list - never any part of the question.
   */
  public function recordInjectionPattern(string $pattern): void {
    $this->record(self::EVENT_INJECTION_PATTERN, ['pattern.' . $pattern]);
  }

  /**
   * Records a stop applied by a guardrail while the answer was streaming.
   *
   * This is the entry point for a StreamableGuardrailInterface plugin to count
   * its own stops, and the only way such a stop can be counted at all: the AI
   * module evaluates streaming guardrails inside the response iterator and
   * never reports the result back to the caller, so
   * ::recordGuardrailResults() cannot see them. Call it from the plugin,
   * passing the plugin's own label.
   *
   * @param string $plugin_label
   *   The guardrail's label, matching the dimension ::recordGuardrailResults()
   *   uses so both modes aggregate under one plugin key. Never answer text.
   */
  public function recordStreamingStop(string $plugin_label): void {
    $this->record(self::EVENT_GUARDRAIL_STOP, [
      'mode.' . AiGuardrailModeEnum::DuringGenerate->value,
      'plugin.' . $plugin_label,
    ]);
  }

  /**
   * Records the stopping guardrail results of a completed chat turn.
   *
   * Only stops are counted; a guardrail that passed or rewrote is not an event
   * worth measuring here.
   *
   * Two limitations come from the contrib ai module and are recorded honestly
   * rather than papered over. "By plugin" means by plugin *label*, because
   * GuardrailResultInterface exposes no plugin id. And the association between
   * a result and the guardrail set it came from is discarded before a caller
   * can see it, so the set is only attributed when exactly one set was active;
   * otherwise it is recorded as ambiguous rather than credited to every set.
   *
   * @param array $results_by_mode
   *   Guardrail results keyed by mode, as returned by
   *   \Drupal\ai\OperationType\InputInterface::getAllGuardrailResults().
   * @param string[] $set_ids
   *   Ids of the guardrail sets that were active for the turn.
   *
   * @return int
   *   How many stops were counted. Returned so a caller can act on the fact
   *   that the turn was stopped without re-parsing the contrib results; a
   *   partial count is returned if reading them fails part-way.
   */
  public function recordGuardrailResults(array $results_by_mode, array $set_ids): int {
    $stops = 0;

    // A guardrail plugin's label() is third-party code running on the response
    // path, so treat it as able to fail without taking the turn with it.
    try {
      $set = match (count($set_ids)) {
        1 => (string) reset($set_ids),
        0 => 'unknown',
        default => 'ambiguous',
      };

      foreach ($results_by_mode as $mode => $results) {
        if (!is_array($results)) {
          continue;
        }
        foreach ($results as $result) {
          if (!$result instanceof GuardrailResultInterface || !$result->stop()) {
            continue;
          }
          $this->record(self::EVENT_GUARDRAIL_STOP, [
            'mode.' . $mode,
            'plugin.' . $result->getGuardrailLabel(),
            'set.' . $set,
          ]);
          $stops++;
        }
      }
    }
    catch (\Throwable $e) {
      $this->warn('Beacon guardrail telemetry could not read a guardrail result: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $stops;
  }

  /**
   * Reads the recorded counts for a window ending today.
   *
   * @param int $days
   *   How many days back to report, including today.
   *
   * @return array
   *   An array with:
   *   - window_days: the requested window.
   *   - totals: count per event type, summed over the window, key-sorted.
   *   - breakdowns: count per dimensioned key, summed over the window.
   *   - days: per-day maps of event key to count, most recent day first.
   */
  public function getReport(int $days = self::DEFAULT_REPORT_DAYS): array {
    $report = [
      'window_days' => $days,
      'totals' => [],
      'breakdowns' => [],
      'days' => [],
    ];

    try {
      $rows = $this->database->select(self::TABLE, 't')
        ->fields('t', ['bucket_date', 'event_key', 'event_count'])
        ->condition('bucket_date', $this->dateDaysAgo(max($days, 1) - 1), '>=')
        ->orderBy('bucket_date', 'DESC')
        ->orderBy('event_key')
        ->execute();

      foreach ($rows as $row) {
        $group = in_array($row->event_key, self::EVENTS, TRUE)
          ? 'totals'
          : 'breakdowns';
        $count = (int) $row->event_count;
        $current = $report[$group][$row->event_key] ?? 0;
        $report[$group][$row->event_key] = $current + $count;
        $report['days'][$row->bucket_date][$row->event_key] = $count;
      }
      ksort($report['totals']);
      ksort($report['breakdowns']);
    }
    catch (\Throwable $e) {
      $this->warn('Beacon guardrail telemetry could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);
      $report['totals'] = [];
      $report['breakdowns'] = [];
      $report['days'] = [];
    }

    return $report;
  }

  /**
   * Reads one event's count for every day in a window, oldest day first.
   *
   * Unlike ::getReport(), days with no events are present with a count of 0:
   * this feeds the distribution chart, where a quiet day is a data point and a
   * gap would misread as a shorter window. Lives here rather than in the
   * controller because the day-bucket vocabulary is this class's to own.
   *
   * @param string $event
   *   One of the self::EVENT_* constants.
   * @param int $days
   *   How many days back to read, including today.
   *
   * @return int[]
   *   Count keyed by bucket date, chronological, one entry per day.
   */
  public function getDailySeries(string $event, int $days = self::RETENTION_DAYS): array {
    $days = max($days, 1);

    $series = [];
    for ($ago = $days - 1; $ago >= 0; $ago--) {
      $series[$this->dateDaysAgo($ago)] = 0;
    }

    try {
      $rows = $this->database->select(self::TABLE, 't')
        ->fields('t', ['bucket_date', 'event_count'])
        ->condition('event_key', $event)
        ->condition('bucket_date', $this->dateDaysAgo($days - 1), '>=')
        ->execute();

      foreach ($rows as $row) {
        // Guarded rather than assigned blindly: a row dated ahead of today
        // (a clock change, a restored database) must not add a key outside the
        // window and lengthen the chart.
        if (isset($series[$row->bucket_date])) {
          $series[$row->bucket_date] = (int) $row->event_count;
        }
      }
    }
    catch (\Throwable $e) {
      $this->warn('Beacon guardrail telemetry series could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $series;
  }

  /**
   * Records one occurrence of an event.
   *
   * The event's own total is incremented once, plus one row per dimension, so
   * the per-event-type total stays correct however many breakdowns a single
   * occurrence carries.
   *
   * Protected on purpose: see the class docblock. Callers use the record*()
   * methods above, which fix the vocabulary and cannot carry conversation text.
   *
   * @param string $event
   *   One of the self::EVENT_* constants.
   * @param string[] $dimensions
   *   Breakdown suffixes, each already prefixed with its kind.
   */
  protected function record(string $event, array $dimensions = []): void {
    $keys = [$event];
    foreach ($dimensions as $dimension) {
      $keys[] = $event . '.' . $dimension;
    }

    // One try/catch for the whole batch: if the store is unavailable the turn
    // continues unaffected and the operator gets a single warning, not one per
    // counter.
    try {
      $bucket = $this->dateDaysAgo(0);
      $inserted = FALSE;
      foreach ($keys as $key) {
        $inserted = $this->increment($bucket, $key) || $inserted;
      }
      // Pruning is worth doing only when a bucket that did not exist before
      // appeared, and only once per request however many counters that was.
      if ($inserted && !$this->pruned) {
        $this->pruned = TRUE;
        $this->prune();
      }
    }
    catch (\Throwable $e) {
      $this->warn('Beacon guardrail telemetry could not record @event: @message', [
        '@event' => $event,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Increments one counter, creating its row if needed.
   *
   * The update is tried first: after a day's first event the row already
   * exists, so the ordinary case costs a single atomic statement. (Drupal's
   * Merge would always spend a SELECT before writing.) A concurrent insert of
   * the same counter is caught and folded into an increment, so no count is
   * lost.
   *
   * @param string $bucket
   *   The day bucket.
   * @param string $key
   *   The raw event key; normalized before storage.
   *
   * @return bool
   *   TRUE if a new row was inserted.
   */
  protected function increment(string $bucket, string $key): bool {
    $keys = [
      'bucket_date' => $bucket,
      'event_key' => $this->normalizeKey($key),
    ];

    if ($this->addOne($keys) > 0) {
      return FALSE;
    }

    try {
      $this->database->insert(self::TABLE)
        ->fields($keys + ['event_count' => 1])
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException $e) {
      // A concurrent turn created this counter first; add to it instead.
      $this->addOne($keys);
      return FALSE;
    }
  }

  /**
   * Adds one to an existing counter.
   *
   * @param array $keys
   *   The bucket_date and event_key identifying the counter.
   *
   * @return int
   *   How many rows were updated: 0 when the counter does not exist yet.
   */
  protected function addOne(array $keys): int {
    $update = $this->database->update(self::TABLE)
      ->expression('event_count', '[event_count] + :increment', [
        ':increment' => 1,
      ]);
    foreach ($keys as $field => $value) {
      $update->condition($field, $value);
    }

    return (int) $update->execute();
  }

  /**
   * Deletes buckets older than the retention window.
   */
  protected function prune(): void {
    $this->database->delete(self::TABLE)
      ->condition('bucket_date', $this->dateDaysAgo(self::RETENTION_DAYS), '<')
      ->execute();
  }

  /**
   * Returns the bucket for a number of days before this request.
   *
   * Buckets are UTC days so they are stable regardless of the site's display
   * timezone; the report labels the column accordingly.
   *
   * @param int $days
   *   How many days back, 0 being today.
   *
   * @return string
   *   The bucket date, as YYYY-MM-DD.
   */
  protected function dateDaysAgo(int $days): string {
    return gmdate('Y-m-d', $this->time->getRequestTime() - ($days * 86400));
  }

  /**
   * Reduces a key to a bounded, predictable identifier.
   *
   * Guardrail labels are free text set by whoever configured the guardrail, so
   * they are folded to lowercase, stripped of anything outside a safe
   * identifier alphabet, and truncated to the column width. This keeps the key
   * space stable and readable in the report.
   *
   * @param string $key
   *   The raw key.
   *
   * @return string
   *   The normalized key.
   */
  protected function normalizeKey(string $key): string {
    $key = preg_replace('/[^a-z0-9._:-]+/', '_', mb_strtolower($key));

    return mb_substr(trim((string) $key, '_'), 0, self::MAX_KEY_LENGTH);
  }

  /**
   * Logs a warning without letting a failing logger break the caller.
   *
   * @param string $message
   *   The message, with placeholders.
   * @param array $context
   *   The placeholder values.
   */
  private function warn(string $message, array $context): void {
    try {
      $this->logger->warning($message, $context);
    }
    catch (\Throwable) {
      // Logging goes to the same database these counters do, so a store outage
      // can take the log write down with it. Losing a warning is acceptable;
      // breaking a chat turn to report one is not.
    }
  }

}
