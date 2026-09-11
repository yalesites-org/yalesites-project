<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\ys_beacon\Service\SuspectTurnLog;
use Psr\Log\LoggerInterface;

/**
 * Tests the log of turns flagged as suspected injection attempts.
 *
 * Exercises the service directly against its own table, matching
 * GuardrailTelemetryTest, so the behavior is verified without standing up the
 * module's full AI/search dependency graph.
 *
 * The store records that a turn was flagged and why - never what was said - so
 * the first test here is the schema one: there is no column a question or an
 * answer could be written into. The bounds that remain (retention, the per-day
 * quota) are covered as behavior rather than left as documentation.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\SuspectTurnLog
 */
class SuspectTurnLogTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger passed to the service under test.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load the install file (the module itself is not enabled here, so its
    // contrib dependencies are not required) and create the flagged-turn table
    // from its hook_schema definition.
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
    $this->database = $this->container->get('database');
    $schema = ys_beacon_schema()[SuspectTurnLog::TABLE];
    $this->database->schema()->createTable(SuspectTurnLog::TABLE, $schema);

    $this->logger = $this->createMock(LoggerInterface::class);
  }

  /**
   * The timestamp of noon UTC on a given date.
   *
   * The single place the pin is expressed, so assertions share it with
   * ::timeOn() instead of restating the expression - the pinned hour is
   * load-bearing for every timestamp assertion below.
   *
   * @param string $date
   *   A date in YYYY-MM-DD form.
   *
   * @return int
   *   The timestamp.
   */
  protected function noonOn(string $date): int {
    return (int) strtotime($date . ' 12:00:00 UTC');
  }

  /**
   * Builds a time service pinned to noon UTC on a given date.
   *
   * @param string $date
   *   A date in YYYY-MM-DD form.
   *
   * @return \Drupal\Component\Datetime\TimeInterface
   *   The pinned time service.
   */
  protected function timeOn(string $date): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($this->noonOn($date));

    return $time;
  }

  /**
   * Builds the service with the request time pinned to a given UTC date.
   *
   * @param string $date
   *   A date in YYYY-MM-DD form.
   *
   * @return \Drupal\ys_beacon\Service\SuspectTurnLog
   *   The service, stamping rows on that day.
   */
  protected function logOn(string $date): SuspectTurnLog {
    return new SuspectTurnLog(
      $this->database,
      $this->timeOn($date),
      $this->logger
    );
  }

  /**
   * Builds the service pinned to an exact UTC moment.
   *
   * The day-bucket helpers above pin noon, which cannot exercise a boundary.
   *
   * @param string $datetime
   *   Any strtotime-parsable moment, interpreted as UTC.
   *
   * @return object
   *   The service, with ::countToday() exposed.
   */
  protected function logAtMoment(string $datetime): object {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')
      ->willReturn((int) strtotime($datetime . ' UTC'));

    return new class($this->database, $time, $this->logger) extends SuspectTurnLog {

      /**
       * Exposes the per-day quota count so the UTC boundary can be asserted.
       */
      public function todayCount(string $pattern = 'jailbreak'): int {
        return $this->countToday($pattern);
      }

    };
  }

  /**
   * Counts every row in the table, regardless of the retention window.
   *
   * @return int
   *   The raw row count.
   */
  protected function rawRowCount(): int {
    return (int) $this->database->select(SuspectTurnLog::TABLE, 's')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Every row's id, oldest insert first.
   *
   * Rows written on the same pinned day share a timestamp, so the serial id is
   * what distinguishes them - and the eviction order the service documents
   * ("created, then id") makes the lowest id the oldest. Read here rather than
   * added to the service's returned fields, which nothing in the report needs.
   *
   * @return int[]
   *   The ids.
   */
  protected function rawIds(): array {
    $ids = $this->database->select(SuspectTurnLog::TABLE, 's')
      ->fields('s', ['id'])
      ->orderBy('id')
      ->execute()
      ->fetchCol();

    return array_map('intval', $ids);
  }

  /**
   * Every row's created timestamp, newest first.
   *
   * @param \Drupal\ys_beacon\Service\SuspectTurnLog $log
   *   The reader to page through.
   * @param int $limit
   *   How many rows to read.
   *
   * @return int[]
   *   The timestamps.
   */
  protected function createdTimes(SuspectTurnLog $log, int $limit = SuspectTurnLog::DEFAULT_LIST_LIMIT): array {
    return array_map('intval', array_column($log->getRecent($limit), 'created'));
  }

  /**
   * The table's columns are exactly the three that hold no conversation text.
   *
   * The load-bearing guarantee: Beacon records that a turn was flagged and why,
   * never what was said. Asserted as an ALLOWLIST of the whole field set, the
   * way GuardrailTelemetryTest::testTableStoresCountsOnly() holds the counter
   * table - a denylist of today's two text column names would let a third
   * (answer_excerpt, question_hash) through unnoticed.
   *
   * @covers ::record
   */
  public function testTableHasNoConversationTextColumns(): void {
    $this->assertSame(
      ['id', 'created', 'pattern'],
      array_keys(ys_beacon_schema()[SuspectTurnLog::TABLE]['fields'])
    );
  }

  /**
   * A flagged turn is stored as when it happened and why it was kept.
   *
   * Covers both reason kinds - a detector pattern name and the fixed
   * guardrail-stop constant - since they travel the same insert.
   *
   * @covers ::record
   * @covers ::getRecent
   */
  public function testStoresWhenAndWhyOnly(): void {
    $log = $this->logOn('2026-07-28');
    $log->record('ignore_instructions');
    $log->record(SuspectTurnLog::REASON_GUARDRAIL_STOP);

    $rows = $log->getRecent();
    $this->assertCount(2, $rows);
    $this->assertSame(
      [SuspectTurnLog::REASON_GUARDRAIL_STOP, 'ignore_instructions'],
      array_column($rows, 'pattern')
    );
    $this->assertSame($this->noonOn('2026-07-28'), (int) $rows[0]['created']);
    // Asserted as the exact key set so a re-added text column fails here.
    $this->assertSame(['created', 'pattern'], array_keys($rows[0]));
  }

  /**
   * An over-long reason is clamped to the column width rather than throwing.
   *
   * The reason is the only caller-supplied value left, so the one bound on it
   * is worth holding: a pattern name longer than the column would otherwise
   * fail the insert and lose the row.
   *
   * @covers ::record
   */
  public function testClampsAnOverLongReason(): void {
    $log = $this->logOn('2026-07-28');
    $log->record(str_repeat('p', SuspectTurnLog::MAX_PATTERN_LENGTH * 2));

    $rows = $log->getRecent();
    $this->assertCount(1, $rows);
    $this->assertSame(SuspectTurnLog::MAX_PATTERN_LENGTH, mb_strlen($rows[0]['pattern']));
  }

  /**
   * Newest flagged turns are listed first, and the limit is honored.
   *
   * @covers ::getRecent
   */
  public function testListsNewestFirstAndHonorsTheLimit(): void {
    $this->logOn('2026-07-26')->record('jailbreak');
    $this->logOn('2026-07-27')->record('jailbreak');
    $this->logOn('2026-07-28')->record('jailbreak');

    $reader = $this->logOn('2026-07-28');
    $this->assertSame([
      $this->noonOn('2026-07-28'),
      $this->noonOn('2026-07-27'),
      $this->noonOn('2026-07-26'),
    ], $this->createdTimes($reader));

    $this->assertSame([
      $this->noonOn('2026-07-28'),
      $this->noonOn('2026-07-27'),
    ], $this->createdTimes($this->logOn('2026-07-28'), 2));
  }

  /**
   * Rows past the retention window are deleted on the next write.
   *
   * @covers ::record
   */
  public function testPrunesTurnsBeyondRetention(): void {
    $this->logOn('2026-01-01')->record('jailbreak');
    $this->assertSame(1, $this->rawRowCount());

    // More than RETENTION_DAYS later, so the January row is outside the window.
    $this->logOn('2026-07-28')->record('jailbreak');

    $this->assertSame(1, $this->rawRowCount());
    $this->assertSame(
      [$this->noonOn('2026-07-28')],
      $this->createdTimes($this->logOn('2026-07-28'))
    );
  }

  /**
   * An expired row is never returned, even before a write has pruned it.
   *
   * @covers ::getRecent
   * @covers ::countStored
   */
  public function testHidesExpiredTurnsBeforeTheyArePruned(): void {
    $this->logOn('2026-01-01')->record('jailbreak');

    // No write has happened since, so the row is still on disk.
    $this->assertSame(1, $this->rawRowCount());

    $reader = $this->logOn('2026-07-28');
    $this->assertSame([], $reader->getRecent());
    $this->assertSame(0, $reader->countStored());
  }

  /**
   * A pattern's daily quota holds, keeping the most recent attempts.
   *
   * Eviction rather than dropping matters: if the newest turn were dropped, an
   * attacker could pre-fill the quota with junk and go unrecorded afterwards.
   * With the text gone the rows are told apart by their serial ids, and the
   * service evicts by "created, then id" - so surviving the flood means the
   * lowest ids are the ones that went.
   *
   * @covers ::record
   */
  public function testCapsStoredTurnsPerPatternKeepingTheNewest(): void {
    $log = $this->logOn('2026-07-28');
    $overshoot = 5;
    $total = SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY + $overshoot;
    for ($i = 0; $i < $total; $i++) {
      $log->record('jailbreak');
    }

    $this->assertSame(SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY, $this->rawRowCount());

    $ids = $this->rawIds();
    // Ids are 1..$total in insert order, so the newest is $total and the first
    // $overshoot are the ones eviction should have taken.
    $this->assertSame($total, max($ids), 'The most recent attempt survived.');
    $this->assertSame($overshoot + 1, min($ids), 'The oldest attempts were the ones evicted.');
  }

  /**
   * Saturating one pattern must not blind another.
   *
   * The quota is per pattern precisely so a flood of cheap hits under one
   * pattern cannot stop a novel attack under a different one being recorded.
   *
   * @covers ::record
   */
  public function testOnePatternCannotBlindAnother(): void {
    $log = $this->logOn('2026-07-28');
    for ($i = 0; $i < SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY + 10; $i++) {
      $log->record('jailbreak');
    }

    $log->record('reveal_prompt');

    $patterns = array_column($log->getRecent(500), 'pattern');
    $this->assertContains('reveal_prompt', $patterns);
  }

  /**
   * The quota does not stop the next day being recorded.
   *
   * @covers ::record
   */
  public function testTheDailyCapResetsTheNextDay(): void {
    $first = $this->logOn('2026-07-27');
    for ($i = 0; $i < SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY; $i++) {
      $first->record('jailbreak');
    }

    $this->logOn('2026-07-28')->record('jailbreak');

    $this->assertSame(SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY + 1, $this->rawRowCount());
    $this->assertSame(
      [$this->noonOn('2026-07-28')],
      $this->createdTimes($this->logOn('2026-07-28'), 1)
    );
  }

  /**
   * Only turns inside the retention window are counted.
   *
   * @covers ::countStored
   */
  public function testCountsOnlyTurnsInsideTheWindow(): void {
    $this->logOn('2026-07-27')->record('jailbreak');
    $this->logOn('2026-07-28')->record('jailbreak');

    $this->assertSame(2, $this->logOn('2026-07-28')->countStored());
  }

  /**
   * A missing table must not break the chat turn that triggered the write.
   *
   * @covers ::record
   * @covers ::getRecent
   * @covers ::countStored
   */
  public function testDegradesSafelyWithoutTheTable(): void {
    $this->database->schema()->dropTable(SuspectTurnLog::TABLE);
    $log = $this->logOn('2026-07-28');

    $log->record('jailbreak');

    $this->assertSame([], $log->getRecent());
    $this->assertSame(0, $log->countStored());
  }

  /**
   * The retention edge keeps day 89 and deletes day 91.
   *
   * The other retention tests use a gap of months, which would pass even with
   * an off-by-one (or a wildly wrong) cutoff. 2026-04-30 is exactly 89 days
   * before 2026-07-28 and 2026-04-28 exactly 91, both pinned to noon so the
   * comparison cannot straddle a bucket.
   *
   * @covers ::record
   * @covers ::getRecent
   */
  public function testRetentionEdgeKeepsTheLastDayInsideTheWindow(): void {
    $this->logOn('2026-04-28')->record('jailbreak');
    $this->logOn('2026-04-30')->record('jailbreak');

    // Any write prunes, so this is what applies the cutoff.
    $this->logOn('2026-07-28')->record('jailbreak');

    $created = $this->createdTimes($this->logOn('2026-07-28'));
    $this->assertContains($this->noonOn('2026-04-30'), $created);
    $this->assertNotContains($this->noonOn('2026-04-28'), $created);
    $this->assertSame(2, $this->rawRowCount());
  }

  /**
   * The per-day quota rolls over at UTC midnight, not on a 24-hour window.
   *
   * Pins the `$now - ($now % 86400)` floor: a naive "now minus 86400" would
   * still count the 23:59 row a minute later and wrongly consume the new day's
   * quota.
   *
   * @covers ::record
   */
  public function testTheDailyQuotaRollsOverAtUtcMidnight(): void {
    $this->logAtMoment('2026-07-27 23:59:00')->record('jailbreak');

    $this->assertSame(1, $this->logAtMoment('2026-07-27 23:59:30')->todayCount());
    $this->assertSame(0, $this->logAtMoment('2026-07-28 00:01:00')->todayCount());
  }

  /**
   * Cron expires rows on a site where no further turn is ever flagged.
   *
   * ::record() also prunes, but a store that stops being written would keep its
   * last rows forever - and the report page promises they are deleted.
   *
   * @covers ::pruneExpired
   */
  public function testPruneExpiredDeletesWithoutAnyWrite(): void {
    $this->logOn('2026-01-01')->record('jailbreak');
    $this->assertSame(1, $this->rawRowCount());

    $this->logOn('2026-07-28')->pruneExpired();

    $this->assertSame(0, $this->rawRowCount());
  }

  /**
   * Cron pruning must not throw when the table is absent.
   *
   * @covers ::pruneExpired
   */
  public function testPruneExpiredDegradesSafelyWithoutTheTable(): void {
    $this->database->schema()->dropTable(SuspectTurnLog::TABLE);

    $this->logOn('2026-07-28')->pruneExpired();

    $this->assertTrue(TRUE, 'Pruning without the table did not throw.');
  }

  /**
   * A failed write is reported, and reports only the failure's type.
   *
   * The second half is the load-bearing half, and it is a privacy rule rather
   * than a style one. ::record()'s catch deliberately logs get_class($e), not
   * $e->getMessage(), because Drupal's database layer appends a failing
   * statement's bound arguments to its exception message - so the moment the
   * follow-up ticket re-adds a question column, a message-logging catch would
   * copy conversation text into dblog, which is readable with the far weaker
   * "access site reports". That guard is invisible in the diff of a future
   * "improve diagnostics" change unless a test holds it, so this asserts the
   * context carries the type alone.
   *
   * @covers ::record
   */
  public function testWriteFailureIsLoggedWithoutTheStatementMessage(): void {
    $this->database->schema()->dropTable(SuspectTurnLog::TABLE);

    $logged = [];
    $contexts = [];
    $this->logger->method('warning')
      ->willReturnCallback(function ($message, $context = []) use (&$logged, &$contexts): void {
        $logged[] = $message . ' ' . implode(' ', array_map('strval', $context));
        $contexts[] = $context;
      });

    $this->logOn('2026-07-28')->record('jailbreak');

    $this->assertNotEmpty($logged, 'The failure was logged.');
    foreach ($logged as $line) {
      $this->assertStringNotContainsString('@message', $line);
    }
    foreach ($contexts as $context) {
      $this->assertSame(['@type'], array_keys($context));
    }
  }

  /**
   * A failing logger must not escape either, since it shares the database.
   *
   * @covers ::record
   */
  public function testLoggingFailureDoesNotEscape(): void {
    $this->database->schema()->dropTable(SuspectTurnLog::TABLE);
    $this->logger->method('warning')
      ->willThrowException(new \RuntimeException('log storage is down'));

    $this->logOn('2026-07-28')->record('jailbreak');

    $this->assertTrue(TRUE, 'Recording a flagged turn did not throw.');
  }

}
