<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Entity\AiGuardrailModeEnum;
use Drupal\ai\Guardrail\Result\GuardrailResultInterface;
use Drupal\ys_beacon\Service\GuardrailTelemetry;
use Psr\Log\LoggerInterface;

/**
 * Tests aggregate guardrail-event counting.
 *
 * Exercises the telemetry service directly against its own table so the
 * behavior can be verified without standing up the module's full AI/search
 * dependency graph.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\GuardrailTelemetry
 */
class GuardrailTelemetryTest extends KernelTestBase {

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
    // contrib dependencies are not required) and create the telemetry table
    // from its hook_schema definition.
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
    $this->database = $this->container->get('database');
    $schema = ys_beacon_schema()[GuardrailTelemetry::TABLE];
    $this->database->schema()->createTable(GuardrailTelemetry::TABLE, $schema);

    $this->logger = $this->createMock(LoggerInterface::class);
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
    $time->method('getRequestTime')
      ->willReturn((int) strtotime($date . ' 12:00:00 UTC'));

    return $time;
  }

  /**
   * Builds the service with the request time pinned to a given UTC date.
   *
   * @param string $date
   *   A date in YYYY-MM-DD form.
   *
   * @return \Drupal\ys_beacon\Service\GuardrailTelemetry
   *   The service, recording into that day's bucket.
   */
  protected function telemetryOn(string $date): GuardrailTelemetry {
    return new GuardrailTelemetry(
      $this->database,
      $this->timeOn($date),
      $this->logger
    );
  }

  /**
   * Reads the whole table as an event_key => count map for a single day.
   *
   * @param string $date
   *   The day bucket to read.
   *
   * @return array
   *   Counts keyed by event key.
   */
  protected function countsOn(string $date): array {
    return $this->database->select(GuardrailTelemetry::TABLE, 't')
      ->fields('t', ['event_key', 'event_count'])
      ->condition('bucket_date', $date)
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Builds a guardrail result double.
   *
   * @param bool $stop
   *   Whether the result stops the turn.
   * @param string $label
   *   The guardrail's label.
   *
   * @return \Drupal\ai\Guardrail\Result\GuardrailResultInterface
   *   The result double.
   */
  protected function guardrailResult(bool $stop, string $label = 'Beacon output safety'): GuardrailResultInterface {
    $result = $this->createMock(GuardrailResultInterface::class);
    $result->method('stop')->willReturn($stop);
    $result->method('getGuardrailLabel')->willReturn($label);

    return $result;
  }

  /**
   * Each event type accumulates its own count.
   *
   * @covers ::recordRefusal
   * @covers ::recordZeroCitations
   * @covers ::recordTurn
   */
  public function testCountsEachEventTypeSeparately(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordTurn();
    $telemetry->recordRefusal();
    $telemetry->recordRefusal();
    $telemetry->recordZeroCitations();

    $counts = $this->countsOn('2026-07-28');
    $this->assertSame(1, (int) $counts[GuardrailTelemetry::EVENT_TURNS]);
    $this->assertSame(2, (int) $counts[GuardrailTelemetry::EVENT_REFUSAL]);
    $this->assertSame(1, (int) $counts[GuardrailTelemetry::EVENT_ZERO_CITATIONS]);
    $this->assertArrayNotHasKey(GuardrailTelemetry::EVENT_GUARDRAIL_STOP, $counts);
  }

  /**
   * Dimensions are counted alongside, not instead of, the event total.
   *
   * A single guardrail stop carries three breakdowns. Each must get its own
   * row while the event's own total goes up by exactly one, so the
   * per-event-type total stays correct however many breakdowns an occurrence
   * carries.
   *
   * @covers ::recordGuardrailResults
   */
  public function testDimensionsDoNotInflateTheEventTotal(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordGuardrailResults(
      [AiGuardrailModeEnum::PostGenerate->value => [$this->guardrailResult(TRUE)]],
      ['ys_beacon_output_safety']
    );

    $counts = $this->countsOn('2026-07-28');
    $this->assertSame(1, (int) $counts[GuardrailTelemetry::EVENT_GUARDRAIL_STOP]);
    $this->assertSame(1, (int) $counts['guardrail_stop.mode.post']);
    $this->assertSame(1, (int) $counts['guardrail_stop.plugin.beacon_output_safety']);
    $this->assertSame(1, (int) $counts['guardrail_stop.set.ys_beacon_output_safety']);
  }

  /**
   * Event keys are normalized so a label cannot break the key space.
   *
   * A guardrail label is free text typed by whoever configured the guardrail.
   *
   * @covers ::recordGuardrailResults
   */
  public function testNormalizesDimensionKeys(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordGuardrailResults(
      [
        AiGuardrailModeEnum::PostGenerate->value => [
          $this->guardrailResult(TRUE, 'Beacon "Output" Safety / v2!'),
        ],
      ],
      ['ys_beacon_output_safety']
    );

    $this->assertArrayHasKey(
      'guardrail_stop.plugin.beacon_output_safety_v2',
      $this->countsOn('2026-07-28')
    );
  }

  /**
   * Non-stopping results are not counted as guardrail stops.
   *
   * @covers ::recordGuardrailResults
   */
  public function testIgnoresGuardrailResultsThatDoNotStop(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordGuardrailResults(
      [AiGuardrailModeEnum::PreGenerate->value => [$this->guardrailResult(FALSE)]],
      ['ys_beacon_output_safety']
    );

    $this->assertSame([], $this->countsOn('2026-07-28'));
  }

  /**
   * With several sets active the set is not guessed.
   *
   * Contrib discards the stop-to-set association, so counting every active set
   * would over-report. The stop is still counted, with the set left ambiguous.
   *
   * @covers ::recordGuardrailResults
   */
  public function testMarksTheSetAmbiguousWhenSeveralAreActive(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordGuardrailResults(
      [AiGuardrailModeEnum::PostGenerate->value => [$this->guardrailResult(TRUE)]],
      ['set_one', 'set_two']
    );

    $counts = $this->countsOn('2026-07-28');
    $this->assertSame(1, (int) $counts[GuardrailTelemetry::EVENT_GUARDRAIL_STOP]);
    $this->assertSame(1, (int) $counts['guardrail_stop.set.ambiguous']);
    $this->assertArrayNotHasKey('guardrail_stop.set.set_one', $counts);
    $this->assertArrayNotHasKey('guardrail_stop.set.set_two', $counts);
  }

  /**
   * A streaming guardrail can count its own stop under the during mode.
   *
   * This is the only route by which a stop applied inside the response stream
   * can be counted, because the AI module never reports those to the caller.
   * It must aggregate under the same plugin key as a pre/post stop.
   *
   * @covers ::recordStreamingStop
   */
  public function testCountsStreamingStopsUnderTheDuringMode(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $telemetry->recordStreamingStop('Beacon output safety');

    $counts = $this->countsOn('2026-07-28');
    $this->assertSame(1, (int) $counts[GuardrailTelemetry::EVENT_GUARDRAIL_STOP]);
    $this->assertSame(1, (int) $counts['guardrail_stop.mode.during']);
    $this->assertSame(1, (int) $counts['guardrail_stop.plugin.beacon_output_safety']);
  }

  /**
   * Counts are bucketed per UTC day and reported per day and in total.
   *
   * @covers ::getReport
   */
  public function testBucketsCountsByDayAndTotalsTheWindow(): void {
    $this->telemetryOn('2026-07-27')->recordRefusal();
    $today = $this->telemetryOn('2026-07-28');
    $today->recordRefusal();
    $today->recordRefusal();

    $report = $today->getReport(30);

    $this->assertSame(3, $report['totals'][GuardrailTelemetry::EVENT_REFUSAL]);
    $this->assertSame(1, $report['days']['2026-07-27'][GuardrailTelemetry::EVENT_REFUSAL]);
    $this->assertSame(2, $report['days']['2026-07-28'][GuardrailTelemetry::EVENT_REFUSAL]);
  }

  /**
   * The report window excludes days older than the requested range.
   *
   * @covers ::getReport
   */
  public function testReportWindowExcludesOlderDays(): void {
    $this->telemetryOn('2026-07-01')->recordRefusal();
    $today = $this->telemetryOn('2026-07-28');
    $today->recordRefusal();

    $report = $today->getReport(7);

    $this->assertSame(1, $report['totals'][GuardrailTelemetry::EVENT_REFUSAL]);
    $this->assertArrayNotHasKey('2026-07-01', $report['days']);
  }

  /**
   * The report keeps event totals and dimensioned breakdowns apart.
   *
   * The presentation layer must not have to infer which keys are totals, or a
   * newly added event type would silently render as a breakdown row.
   *
   * @covers ::getReport
   */
  public function testReportSeparatesTotalsFromBreakdowns(): void {
    $telemetry = $this->telemetryOn('2026-07-28');
    $telemetry->recordInjectionPattern('jailbreak');

    $report = $telemetry->getReport(30);

    $this->assertSame(
      [GuardrailTelemetry::EVENT_INJECTION_PATTERN => 1],
      $report['totals']
    );
    $this->assertSame(
      ['injection_pattern.pattern.jailbreak' => 1],
      $report['breakdowns']
    );
  }

  /**
   * Buckets beyond the retention window are pruned as new days are recorded.
   *
   * @covers ::recordRefusal
   */
  public function testPrunesBucketsBeyondRetention(): void {
    $days_back = GuardrailTelemetry::RETENTION_DAYS + 5;
    $stale = gmdate(
      'Y-m-d',
      strtotime('2026-07-28 12:00:00 UTC') - ($days_back * 86400)
    );
    $this->telemetryOn($stale)->recordRefusal();
    $this->assertNotSame([], $this->countsOn($stale));

    // Recording on a fresh day inserts a new row, which triggers the prune.
    $this->telemetryOn('2026-07-28')->recordRefusal();

    $this->assertSame([], $this->countsOn($stale));
    $this->assertNotSame([], $this->countsOn('2026-07-28'));
  }

  /**
   * A racing insert is folded into an increment, losing no count.
   *
   * This is the mechanism the composite primary key exists for, and the one the
   * README singles out, so it is worth demonstrating rather than asserting. The
   * override reproduces the race deterministically: our UPDATE finds nothing,
   * another turn inserts the counter first, and our INSERT then violates the
   * key.
   *
   * @covers ::increment
   */
  public function testRecoversFromRacingInsertWithoutLosingCounts(): void {
    $telemetry = new class($this->database, $this->timeOn('2026-07-28'), $this->logger) extends GuardrailTelemetry {

      /**
       * How many times the increment was attempted.
       *
       * @var int
       */
      public int $attempts = 0;

      /**
       * {@inheritdoc}
       */
      protected function addOne(array $keys): int {
        $this->attempts++;
        if ($this->attempts === 1) {
          // Stand in for the racing turn: create the row, then report that
          // nothing was updated so the caller goes on to its own insert.
          $this->database->insert(GuardrailTelemetry::TABLE)
            ->fields($keys + ['event_count' => 1])
            ->execute();
          return 0;
        }

        return parent::addOne($keys);
      }

    };

    $telemetry->recordRefusal();

    $counts = $this->countsOn('2026-07-28');
    // One from the racing turn, one from ours: neither was lost or doubled.
    $this->assertSame(2, (int) $counts[GuardrailTelemetry::EVENT_REFUSAL]);
    // Proves the recovery branch ran rather than the plain update path.
    $this->assertSame(2, $telemetry->attempts);
  }

  /**
   * The table holds counts only, so no question or answer text can be stored.
   *
   * This is the structural half of the #1469 privacy constraint: there is no
   * column a transcript could be written into.
   */
  public function testTableStoresCountsOnly(): void {
    $fields = array_keys(ys_beacon_schema()[GuardrailTelemetry::TABLE]['fields']);

    $this->assertSame(['bucket_date', 'event_key', 'event_count'], $fields);
  }

  /**
   * Recording degrades quietly when the store is unavailable.
   *
   * A chat turn must never fail because telemetry could not be written.
   *
   * @covers ::recordRefusal
   */
  public function testRecordingDegradesSafelyWithoutTheTable(): void {
    $this->database->schema()->dropTable(GuardrailTelemetry::TABLE);
    $this->logger->expects($this->once())->method('warning');

    $this->telemetryOn('2026-07-28')->recordRefusal();
  }

  /**
   * A failing logger does not turn a telemetry failure into a broken turn.
   *
   * Logging writes to the same database the counters do, so an outage takes
   * both down at once. If the warning were unguarded, the store failure would
   * escape as an exception mid-response.
   *
   * @covers ::recordRefusal
   */
  public function testLoggingFailureDoesNotEscape(): void {
    $this->database->schema()->dropTable(GuardrailTelemetry::TABLE);
    $this->logger->method('warning')
      ->willThrowException(new \RuntimeException('The log store is down too.'));

    $this->telemetryOn('2026-07-28')->recordRefusal();

    $this->addToAssertionCount(1);
  }

  /**
   * The report degrades to an empty result when the store is unavailable.
   *
   * @covers ::getReport
   */
  public function testReportDegradesSafelyWithoutTheTable(): void {
    $this->database->schema()->dropTable(GuardrailTelemetry::TABLE);

    $report = $this->telemetryOn('2026-07-28')->getReport(30);

    $this->assertSame([], $report['totals']);
    $this->assertSame([], $report['breakdowns']);
    $this->assertSame([], $report['days']);
  }

  /**
   * The stop count is returned, so a caller need not re-parse the results.
   *
   * The chat controller uses this to decide whether a turn is worth keeping in
   * full, so a wrong count would silently store the wrong turns.
   *
   * @covers ::recordGuardrailResults
   */
  public function testReturnsTheNumberOfStopsCounted(): void {
    $telemetry = $this->telemetryOn('2026-07-28');

    $this->assertSame(0, $telemetry->recordGuardrailResults([], []));
    $this->assertSame(0, $telemetry->recordGuardrailResults([
      'pre_generate' => [$this->guardrailResult(FALSE)],
    ], ['set_a']));
    $this->assertSame(2, $telemetry->recordGuardrailResults([
      'pre_generate' => [$this->guardrailResult(TRUE), $this->guardrailResult(FALSE)],
      'post_generate' => [$this->guardrailResult(TRUE, 'Beacon output safety')],
    ], ['set_a']));
  }

  /**
   * The chart series is continuous, chronological and zero-filled.
   *
   * @covers ::getDailySeries
   */
  public function testDailySeriesFillsQuietDaysWithZero(): void {
    $this->telemetryOn('2026-07-26')->recordTurn();
    $this->telemetryOn('2026-07-28')->recordTurn();
    $this->telemetryOn('2026-07-28')->recordTurn();

    $series = $this->telemetryOn('2026-07-28')
      ->getDailySeries(GuardrailTelemetry::EVENT_TURNS, 3);

    $this->assertSame([
      '2026-07-26' => 1,
      '2026-07-27' => 0,
      '2026-07-28' => 2,
    ], $series);
  }

  /**
   * The series reads one event type and ignores breakdown keys.
   *
   * @covers ::getDailySeries
   */
  public function testDailySeriesIsolatesTheRequestedEvent(): void {
    $telemetry = $this->telemetryOn('2026-07-28');
    $telemetry->recordTurn();
    $telemetry->recordRefusal();
    $telemetry->recordInjectionPattern('jailbreak');

    $series = $telemetry->getDailySeries(GuardrailTelemetry::EVENT_TURNS, 1);
    $this->assertSame(['2026-07-28' => 1], $series);

    $refusals = $telemetry->getDailySeries(GuardrailTelemetry::EVENT_REFUSAL, 1);
    $this->assertSame(['2026-07-28' => 1], $refusals);
  }

  /**
   * A bucket dated ahead of today does not lengthen the series.
   *
   * @covers ::getDailySeries
   */
  public function testDailySeriesIgnoresBucketsAheadOfToday(): void {
    $this->telemetryOn('2026-08-05')->recordTurn();

    $series = $this->telemetryOn('2026-07-28')
      ->getDailySeries(GuardrailTelemetry::EVENT_TURNS, 2);

    $this->assertSame(['2026-07-27' => 0, '2026-07-28' => 0], $series);
  }

  /**
   * The series degrades to zeros when the store is unavailable.
   *
   * @covers ::getDailySeries
   */
  public function testDailySeriesDegradesSafelyWithoutTheTable(): void {
    $this->database->schema()->dropTable(GuardrailTelemetry::TABLE);

    $series = $this->telemetryOn('2026-07-28')
      ->getDailySeries(GuardrailTelemetry::EVENT_TURNS, 2);

    $this->assertSame(['2026-07-27' => 0, '2026-07-28' => 0], $series);
  }

}
