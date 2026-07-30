<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Psr\Log\LoggerInterface;

/**
 * Stores the text of chat turns flagged as suspicious.
 *
 * This is the one place in Beacon that persists conversation text, and it is
 * deliberately a separate class from GuardrailTelemetry rather than a method on
 * it. GuardrailTelemetry's guarantee - that no caller can hand it a question or
 * an answer even by accident - is worth keeping intact, so the capability that
 * breaks that rule lives behind its own name, its own table and its own
 * permission instead of widening the counters' API.
 *
 * Two kinds of turn are recorded: one whose question matched a
 * GuardrailSignalDetector injection pattern, and one a guardrail stopped. An
 * ordinary turn is not stored, is not accumulated in memory beyond the refusal
 * sample the counters already needed, and leaves no row here.
 *
 * Requested in the review of yalesites-org/YaleSites-Internal#1469 so a
 * suspected attack can be read back and understood, which the aggregate
 * counters cannot support: a count of five "ignore_instructions" hits says
 * nothing about what was actually attempted or how the model answered.
 *
 * Three bounds keep a public, unauthenticated endpoint from turning this into a
 * storage-exhaustion surface, and each is stated on the report page rather than
 * left implicit:
 * - rows expire after self::RETENTION_DAYS days, pruned on write and on cron;
 * - each question and answer is clamped to self::MAX_TEXT_LENGTH characters;
 * - at most self::MAX_ROWS_PER_PATTERN_PER_DAY rows are kept per pattern per
 *   UTC day, evicting that pattern's oldest so the newest attempts survive.
 *
 * The aggregate injection counters are NOT capped, so a campaign stays fully
 * visible in the counts even when this text log is only sampling it.
 *
 * Like the counters, every operation degrades quietly: a chat turn must never
 * fail, or slow down, because this store could not be written.
 */
class SuspectTurnLog {

  /**
   * The table holding the flagged turns.
   */
  public const TABLE = 'ys_beacon_suspect_turn';

  /**
   * Reason value for a turn kept because a guardrail stopped it.
   *
   * Stored in the same column as a detector pattern name, so the column holds
   * "why this turn was kept" rather than only a pattern. It is a fixed value
   * rather than the stopping plugin's label: labels are free text from admin
   * config, and the per-pattern quota keys on this column, so an editable label
   * would mean an editable quota bucket. The stopping plugin, mode and set are
   * already broken out in the aggregate counters.
   */
  public const REASON_GUARDRAIL_STOP = 'guardrail_stop';

  /**
   * How many days a flagged turn is kept before it is pruned.
   */
  public const RETENTION_DAYS = 90;

  /**
   * Maximum stored characters of a question or an answer.
   *
   * Enough to see what was attempted and how the model responded, without
   * letting one turn write an unbounded row. Must not be lower than
   * GuardrailSignalDetector::REFUSAL_SAMPLE_LENGTH, because the chat controller
   * uses this length as the accumulation cap for a flagged turn and still has
   * to be able to classify a refusal from the same buffer.
   */
  public const MAX_TEXT_LENGTH = 2000;

  /**
   * Roughly how many flagged turns are stored per pattern per UTC day.
   *
   * Deliberately per PATTERN, not per day overall. A single day-wide quota is
   * steerable by the attacker it exists to record: 200 throwaway
   * "ignore all previous instructions" hits would fill it and every later
   * flagged turn that day - including a novel attack under another pattern -
   * would be dropped. Per-pattern, saturating one cannot blind another.
   *
   * A quota, not a hard ceiling: the count-then-insert is not atomic, so
   * concurrent requests on this unauthenticated endpoint can overshoot by up to
   * the number of PHP workers. That is accepted rather than locked - the point
   * is to bound growth, and a few extra rows does not defeat it. The page and
   * README say "about" for the same reason.
   *
   * Public because the report page discloses the real number rather than a copy
   * of it.
   */
  public const MAX_ROWS_PER_PATTERN_PER_DAY = 60;

  /**
   * Maximum stored pattern-name length, matching the column width.
   */
  protected const MAX_PATTERN_LENGTH = 64;

  /**
   * How many flagged turns the report lists by default.
   */
  public const DEFAULT_LIST_LIMIT = 50;

  /**
   * Hard row ceiling for one export, so a response cannot grow unbounded.
   *
   * Sized in bytes, not rows: MAX_TEXT_LENGTH counts CHARACTERS, so a question
   * padded with four-byte characters is 8KB and a row up to ~16KB. The export
   * holds the rows, a mapped copy, and the encoded string in memory at once, so
   * the ceiling is deliberately well under what an attacker could otherwise
   * inflate a single admin request to. The payload reports when it truncates.
   */
  public const MAX_EXPORT_ROWS = 500;

  /**
   * Whether the retention prune has already run for this request.
   *
   * @var bool
   */
  protected bool $pruned = FALSE;

  /**
   * Constructs the suspect-turn log.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to stamp and to expire rows.
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
   * Records a flagged turn, with the reason it was kept.
   *
   * @param string $reason
   *   Why the turn was kept: an injection pattern name from
   *   GuardrailSignalDetector, or self::REASON_GUARDRAIL_STOP. Stored in the
   *   column still named `pattern`, which is left alone rather than migrated
   *   for a rename.
   * @param string $question
   *   The question as asked. Clamped before storage.
   * @param string $answer
   *   The answer as served, or as much of it as the controller captured. May be
   *   empty when the turn failed before the model produced anything, and only
   *   the shorter refusal sample for a turn kept for a guardrail stop alone.
   */
  public function record(string $reason, string $question, string $answer): void {
    try {
      // Expiry runs before the quota check, not after the insert: the quota
      // returns early below, and a saturated day - a campaign in progress - is
      // exactly when an expired row would otherwise sit here indefinitely.
      // Once per request, however many turns it serves.
      if (!$this->pruned) {
        $this->pruned = TRUE;
        $this->prune();
      }

      $reason = mb_substr($reason, 0, self::MAX_PATTERN_LENGTH);

      // At quota, the pattern's oldest row for the day is evicted rather than
      // the new turn dropped, so what is kept is always the most RECENT
      // attempts. Dropping instead would hand the attacker the choice of which
      // turns survive: pre-fill the quota with junk and every later attempt
      // that day goes unrecorded. The loop tolerates overshoot from concurrent
      // writers, and stops if there is nothing left to evict so it cannot spin.
      while ($this->countToday($reason) >= self::MAX_ROWS_PER_PATTERN_PER_DAY) {
        if (!$this->evictOldestToday($reason)) {
          return;
        }
      }

      $this->database->insert(self::TABLE)
        ->fields([
          'created' => $this->time->getRequestTime(),
          'pattern' => $reason,
          'question' => $this->clamp($question),
          'answer' => $this->clamp($answer),
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      // Deliberately NOT $e->getMessage(): Drupal's database layer appends the
      // failing statement's bound arguments to its exception message
      // (\Drupal\Core\Database\ExceptionHandler::handleExecutionException), and
      // for this table those arguments are the question and the answer. Logging
      // the message would copy conversation text into dblog, which is readable
      // with "access site reports" - a far weaker permission than the one
      // gating this store. The class of the failure is enough to diagnose it.
      $this->warn('Beacon could not record a flagged chat turn (@type).', [
        '@type' => get_class($e),
      ]);
    }
  }

  /**
   * Deletes flagged turns that have outlived the retention window.
   *
   * Called from hook_cron so the 90-day promise holds on a site where no
   * further flagged turn is ever recorded - ::record() prunes too, but a store
   * that stops being written would otherwise keep its last rows forever.
   */
  public function pruneExpired(): void {
    try {
      $this->prune();
    }
    catch (\Throwable $e) {
      $this->warn('Beacon flagged-turn log could not be pruned (@type).', [
        '@type' => get_class($e),
      ]);
    }
  }

  /**
   * Reads the most recent flagged turns, newest first.
   *
   * @param int $limit
   *   How many rows to return.
   *
   * @return array[]
   *   Each row as an array with created, pattern, question and answer keys.
   *   Empty when the store is unreadable.
   */
  public function getRecent(int $limit = self::DEFAULT_LIST_LIMIT): array {
    try {
      $rows = $this->database->select(self::TABLE, 's')
        ->fields('s', ['created', 'pattern', 'question', 'answer'])
        ->condition('created', $this->retentionCutoff(), '>=')
        ->orderBy('created', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, max($limit, 0))
        ->execute();

      return array_map(static fn($row) => (array) $row, $rows->fetchAll());
    }
    catch (\Throwable $e) {
      $this->warn('Beacon flagged-turn log could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }
  }

  /**
   * Reads one page of flagged turns, newest first.
   *
   * The report pages rather than showing a fixed most-recent slice: the quota
   * allows roughly 60 rows per pattern per day, so a bot probing for a week can
   * leave far more than one screenful, and an operator reviewing an incident
   * needs to reach the older ones without falling back to the JSON export.
   * Each page still reads a bounded number of rows, so the page load does not
   * grow with the size of the store.
   *
   * @param int $per_page
   *   How many rows one page holds.
   *
   * @return array[]
   *   Each row as an array with created, pattern, question and answer keys.
   *   Empty when the store is unreadable.
   */
  public function getPage(int $per_page = self::DEFAULT_LIST_LIMIT): array {
    try {
      $rows = $this->database->select(self::TABLE, 's')
        ->extend(PagerSelectExtender::class)
        ->limit(max($per_page, 1))
        ->fields('s', ['created', 'pattern', 'question', 'answer'])
        ->condition('created', $this->retentionCutoff(), '>=')
        ->orderBy('created', 'DESC')
        ->orderBy('id', 'DESC')
        ->execute();

      return array_map(static fn($row) => (array) $row, $rows->fetchAll());
    }
    catch (\Throwable $e) {
      $this->warn('Beacon flagged-turn log page could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }
  }

  /**
   * Counts the flagged turns held within the retention window.
   *
   * @return int
   *   The row count, or 0 when the store is unreadable.
   */
  public function countStored(): int {
    try {
      return (int) $this->database->select(self::TABLE, 's')
        ->condition('created', $this->retentionCutoff(), '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Throwable $e) {
      $this->warn('Beacon flagged-turn log could not be counted: @message', [
        '@message' => $e->getMessage(),
      ]);

      return 0;
    }
  }

  /**
   * Counts one pattern's rows already stored for the current UTC day.
   *
   * @param string $pattern
   *   The pattern name to count.
   *
   * @return int
   *   The row count.
   */
  protected function countToday(string $pattern): int {
    return (int) $this->database->select(self::TABLE, 's')
      ->condition('pattern', $pattern)
      ->condition('created', $this->startOfToday(), '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Deletes one pattern's oldest row for the current UTC day.
   *
   * The id is read first rather than deleting with an ORDER BY and a LIMIT,
   * which Drupal's delete builder does not express portably.
   *
   * @param string $pattern
   *   The pattern name to evict from.
   *
   * @return bool
   *   TRUE if a row was deleted, FALSE if there was nothing to evict.
   */
  protected function evictOldestToday(string $pattern): bool {
    $id = $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('pattern', $pattern)
      ->condition('created', $this->startOfToday(), '>=')
      ->orderBy('created')
      ->orderBy('id')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($id === FALSE || $id === NULL) {
      return FALSE;
    }

    $this->database->delete(self::TABLE)
      ->condition('id', (int) $id)
      ->execute();

    return TRUE;
  }

  /**
   * The timestamp of the current UTC day's midnight.
   *
   * Unix timestamps are UTC-based, so subtracting the remainder gives UTC
   * midnight without consulting a timezone.
   *
   * @return int
   *   The day's starting timestamp.
   */
  protected function startOfToday(): int {
    $now = $this->time->getRequestTime();

    return $now - ($now % 86400);
  }

  /**
   * Deletes flagged turns older than the retention window.
   */
  protected function prune(): void {
    $this->database->delete(self::TABLE)
      ->condition('created', $this->retentionCutoff(), '<')
      ->execute();
  }

  /**
   * The oldest timestamp still inside the retention window.
   *
   * Reads apply this as well as the prune, so a row that has outlived the
   * window is never shown or exported even if no write has pruned it yet.
   *
   * @return int
   *   The cutoff timestamp.
   */
  protected function retentionCutoff(): int {
    return $this->time->getRequestTime() - (self::RETENTION_DAYS * 86400);
  }

  /**
   * Clamps stored text to the column's usable length.
   *
   * @param string $text
   *   The text to clamp.
   *
   * @return string
   *   At most self::MAX_TEXT_LENGTH characters.
   */
  protected function clamp(string $text): string {
    return mb_substr($text, 0, self::MAX_TEXT_LENGTH);
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
      // Logging goes to the same database this store does, so an outage can
      // take the log write down with it. Losing a warning is acceptable;
      // breaking a chat turn to report one is not.
    }
  }

}
