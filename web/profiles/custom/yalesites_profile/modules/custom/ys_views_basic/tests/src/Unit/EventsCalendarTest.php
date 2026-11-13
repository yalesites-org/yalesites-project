<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\ys_views_basic\Service\EventsCalendar;

/**
 * Unit tests for the EventsCalendar service.
 *
 * Characterizes the current behavior of calendar grid generation, per-day
 * event lookup, and event-node filtering, all exercised through the public
 * getCalendar() entry point. All date values are passed in as
 * fixed test data; time-period ("future"/"past") assertions use timestamps
 * far enough from the present (year 1990 / year 2100) that they do not
 * depend on when the suite is run.
 *
 * The recurring-event (smart_date_recur "rrule") branches of getCalendar() are
 * not covered -- see the module's test log for why.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Service\EventsCalendar
 * @group ys_views_basic
 * @group yalesites
 */
class EventsCalendarTest extends UnitTestCase {

  /**
   * The node storage mock, returned by the entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $nodeStorage;

  /**
   * The term storage mock, returned by the entity type manager.
   *
   * @var \Drupal\taxonomy\TermStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $termStorage;

  /**
   * The path alias manager mock.
   *
   * @var \Drupal\path_alias\AliasManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $aliasManager;

  /**
   * The service under test.
   *
   * @var \Drupal\ys_views_basic\Service\EventsCalendar
   */
  protected $eventsCalendar;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // DrupalDateTime (used throughout the service) reads the current
    // language from the container, so a minimal one is required.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $container = new ContainerBuilder();
    $container->set('language_manager', $languageManager);
    \Drupal::setContainer($container);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    // Category/audience/custom_vocab filtering loads descendant terms via
    // the taxonomy_term storage; default to "no further descendants" so a
    // filter's own term ID is the only one considered unless a test
    // configures loadTree() otherwise.
    $this->termStorage = $this->createMock('Drupal\taxonomy\TermStorageInterface');
    $this->termStorage->method('loadTree')->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $this->nodeStorage],
        ['taxonomy_term', $this->termStorage],
      ]);

    $this->aliasManager = $this->createMock(AliasManagerInterface::class);

    $this->eventsCalendar = new EventsCalendar($entityTypeManager, $this->aliasManager);

    $translation = $this->getStringTranslationStub();
    $this->eventsCalendar->setStringTranslation($translation);
  }

  /**
   * Builds a mock event node.
   *
   * @param array $options
   *   Options, all optional:
   *   - id: node ID.
   *   - title: node label.
   *   - event_dates: array of ['value' => int, 'end_value' => int] pairs, as
   *     returned by field_event_date->getValue().
   *   - category_tids, audience_tids, custom_vocab_tids, tag_tids: arrays of
   *     term IDs referenced by the corresponding field.
   *   - has_fields: field names for which hasField() should return TRUE.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock event node.
   */
  protected function createMockEventNode(array $options = []) {
    $options += [
      'id' => 1,
      'title' => 'Sample Event',
      'event_dates' => [],
      'category_tids' => [],
      'audience_tids' => [],
      'custom_vocab_tids' => [],
      'tag_tids' => [],
      'has_fields' => ['field_category', 'field_audience', 'field_custom_vocab', 'field_tags'],
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($options['id']);
    $node->method('label')->willReturn($options['title']);
    $node->method('hasField')->willReturnCallback(
      fn($field) => in_array($field, $options['has_fields'], TRUE)
    );

    $eventDateField = $this->createMock(FieldItemListInterface::class);
    $eventDateField->method('isEmpty')->willReturn(empty($options['event_dates']));
    $eventDateField->method('getValue')->willReturn($options['event_dates']);

    $referenceFields = [
      'field_category' => $options['category_tids'],
      'field_audience' => $options['audience_tids'],
      'field_custom_vocab' => $options['custom_vocab_tids'],
      'field_tags' => $options['tag_tids'],
    ];

    $fieldMap = ['field_event_date' => $eventDateField];
    foreach ($referenceFields as $fieldName => $tids) {
      $terms = array_map([$this, 'createMockTerm'], $tids);
      $fieldItemList = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $fieldItemList->method('referencedEntities')->willReturn($terms);
      $fieldMap[$fieldName] = $fieldItemList;
    }

    $node->method('get')->willReturnCallback(
      fn($field) => $fieldMap[$field] ?? $this->createMock(FieldItemListInterface::class)
    );

    // Non-recurring path: field_event_date?->rrule must resolve falsy.
    // NodeInterface is a plain interface mock with no magic __get, so the
    // property is assigned directly rather than routed through get().
    $node->field_event_date = (object) ['rrule' => NULL];

    return $node;
  }

  /**
   * Creates a mock taxonomy term entity for use in referencedEntities().
   *
   * @param int $tid
   *   The term ID.
   *
   * @return \Drupal\taxonomy\TermInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mock term.
   */
  protected function createMockTerm($tid) {
    $term = $this->createMock('Drupal\taxonomy\TermInterface');
    $term->method('id')->willReturn($tid);
    $term->method('label')->willReturn("Term $tid");
    return $term;
  }

  /**
   * Returns the events of one day's cell in a calendar grid.
   *
   * @param array $rows
   *   The grid returned by getCalendar().
   * @param string $day
   *   The two-digit day of the cell.
   * @param string $month
   *   The two-digit month of the cell.
   *
   * @return array
   *   The events of the matching cell.
   */
  protected function eventsForDay(array $rows, string $day, string $month): array {
    foreach ($rows as $row) {
      foreach ($row as $cell) {
        if ($cell['date']['day'] === $day && $cell['date']['month'] === $month) {
          return $cell['events'];
        }
      }
    }
    $this->fail("No cell found for $month/$day.");
  }

  /**
   * An event running 00:00 to 23:59 renders as "All Day".
   *
   * @covers ::getCalendar
   */
  public function testCalendarLabelsMidnightToElevenFiftyNineAsAllDay() {
    $node = $this->createMockEventNode([
      'id' => 1,
      'event_dates' => [[
        'value' => strtotime('2024-06-15 00:00:00'),
        'end_value' => strtotime('2024-06-15 23:59:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $node]);

    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '15', '06');

    $this->assertCount(1, $events);
    $this->assertSame('All Day', (string) $events[0]['time']);
  }

  /**
   * A normal, timed event renders its start and end times.
   *
   * @covers ::getCalendar
   */
  public function testCalendarLabelsTimedEventWithTimes() {
    $node = $this->createMockEventNode([
      'id' => 1,
      'event_dates' => [[
        'value' => strtotime('2024-06-15 09:00:00'),
        'end_value' => strtotime('2024-06-15 10:30:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $node]);

    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '15', '06');

    $this->assertCount(1, $events);
    $this->assertSame('9:00AM to 10:30AM', (string) $events[0]['time']);
  }

  /**
   * A day's event carries categories, tags, title, url, time, and timestamp.
   *
   * @covers ::getCalendar
   */
  public function testCalendarBuildsExpectedEventStructure() {
    $start = strtotime('2024-06-15 00:00:00');
    $node = $this->createMockEventNode([
      'id' => 42,
      'title' => 'Spring Concert',
      'category_tids' => [10],
      'tag_tids' => [20, 21],
      'event_dates' => [[
        'value' => $start,
        'end_value' => strtotime('2024-06-15 23:59:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([42 => $node]);
    $this->aliasManager->method('getAliasByPath')
      ->with('/node/42')
      ->willReturn('/events/spring-concert');

    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '15', '06');

    $this->assertCount(1, $events);
    $events[0]['time'] = (string) $events[0]['time'];
    $this->assertSame([
      'category' => 'Term 10',
      'title' => 'Spring Concert',
      'url' => '/events/spring-concert',
      'time' => 'All Day',
      'type' => ['Term 20', 'Term 21'],
      'timestamp' => $start,
    ], $events[0]);
  }

  /**
   * A day's cell holds only events overlapping that day, sorted by time.
   *
   * @covers ::getCalendar
   */
  public function testCalendarDayHoldsOnlyOverlappingEventsSortedByTime() {
    $nodeMorning = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Morning Talk',
      'event_dates' => [[
        'value' => strtotime('2024-06-15 09:00:00'),
        'end_value' => strtotime('2024-06-15 10:00:00'),
      ],
      ],
    ]);
    $nodeAfternoon = $this->createMockEventNode([
      'id' => 2,
      'title' => 'Afternoon Workshop',
      'event_dates' => [[
        'value' => strtotime('2024-06-15 13:00:00'),
        'end_value' => strtotime('2024-06-15 14:00:00'),
      ],
      ],
    ]);
    $nodeOtherDay = $this->createMockEventNode([
      'id' => 3,
      'title' => 'Next Day Event',
      'event_dates' => [[
        'value' => strtotime('2024-06-16 09:00:00'),
        'end_value' => strtotime('2024-06-16 10:00:00'),
      ],
      ],
    ]);
    $this->aliasManager->method('getAliasByPath')->willReturnCallback(
      fn($path) => $path
    );

    // Loaded in reverse chronological order to verify the usort() by
    // timestamp.
    $this->configureNodeStorageToReturn([
      2 => $nodeAfternoon,
      1 => $nodeMorning,
      3 => $nodeOtherDay,
    ]);

    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '15', '06');

    $this->assertCount(2, $events);
    $this->assertSame('Morning Talk', $events[0]['title']);
    $this->assertSame('Afternoon Workshop', $events[1]['title']);
    $this->assertSame('9:00AM to 10:00AM', (string) $events[0]['time']);
  }

  /**
   * A multi-day event is labelled "Multi-day Event" on each day it spans.
   *
   * @covers ::getCalendar
   */
  public function testCalendarLabelsMultiDayEvents() {
    $node = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Conference',
      'event_dates' => [[
        'value' => strtotime('2024-06-15 09:00:00'),
        'end_value' => strtotime('2024-06-17 17:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $node]);

    $rows = $this->eventsCalendar->getCalendar('06', '2024');
    $events = $this->eventsForDay($rows, '16', '06');

    $this->assertCount(1, $events);
    $this->assertSame('Multi-day Event', (string) $events[0]['time']);
    // The days outside the span stay empty.
    $this->assertSame([], $this->eventsForDay($rows, '18', '06'));
  }

  /**
   * Nodes whose event date field is empty appear on no day.
   *
   * @covers ::getCalendar
   */
  public function testCalendarSkipsNodesWithEmptyEventDate() {
    $node = $this->createMockEventNode(['id' => 1, 'event_dates' => []]);
    $this->configureNodeStorageToReturn([1 => $node]);

    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '15', '06');

    $this->assertSame([], $events);
  }

  /**
   * Each cell pads its day and carries that day's events.
   *
   * @covers ::getCalendar
   */
  public function testCalendarCellPadsDayAndIncludesEvents() {
    $node = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Talk',
      'event_dates' => [[
        'value' => strtotime('2024-06-05 09:00:00'),
        'end_value' => strtotime('2024-06-05 10:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $node]);

    // Looking the cell up by the zero-padded day '05' proves the padding.
    $events = $this->eventsForDay($this->eventsCalendar->getCalendar('06', '2024'), '05', '06');

    $this->assertCount(1, $events);
    $this->assertSame('Talk', $events[0]['title']);
  }

  /**
   * Configures the mocked node storage to return the given nodes.
   *
   * @param array $nodes
   *   The node mocks that getCalendar() should "find".
   */
  protected function configureNodeStorageToReturn(array $nodes) {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($nodes) ?: []);

    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->method('loadMultiple')->willReturn($nodes);
  }

  /**
   * GetCalendar() returns a 7-column grid, padded with adjacent-month days.
   *
   * June 2024 starts on a Saturday and ends on a Sunday, so the grid pads
   * with the tail end of May at the start and the start of July at the end.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarProducesPaddedMonthGrid() {
    $this->configureNodeStorageToReturn([]);

    $rows = $this->eventsCalendar->getCalendar('06', '2024');

    // June 2024 has 30 days; padding of 6 at each end gives 42 cells across
    // 6 rows of 7.
    $this->assertCount(6, $rows);
    foreach ($rows as $row) {
      $this->assertCount(7, $row);
    }

    // First cell pads in from May.
    $this->assertSame(['day' => '26', 'month' => '05', 'year' => '2024'], $rows[0][0]['date']);
    // First day of June lands in the first row.
    $this->assertSame(['day' => '01', 'month' => '06', 'year' => '2024'], $rows[0][6]['date']);
    // Last day of June (the 30th, a Sunday) is the first cell of the last row.
    $this->assertSame(['day' => '30', 'month' => '06', 'year' => '2024'], $rows[5][0]['date']);
    // The rest of the last row pads in from July.
    $this->assertSame(['day' => '06', 'month' => '07', 'year' => '2024'], $rows[5][6]['date']);
  }

  /**
   * GetCalendar() applies a title search filter when 3+ characters given.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarFiltersByTitleSearch() {
    $matching = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Spring Concert',
      'event_dates' => [[
        'value' => strtotime('2024-06-10 09:00:00'),
        'end_value' => strtotime('2024-06-10 10:00:00'),
      ],
      ],
    ]);
    $nonMatching = $this->createMockEventNode([
      'id' => 2,
      'title' => 'Board Meeting',
      'event_dates' => [[
        'value' => strtotime('2024-06-10 09:00:00'),
        'end_value' => strtotime('2024-06-10 10:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $matching, 2 => $nonMatching]);

    $rows = $this->eventsCalendar->getCalendar('06', '2024', ['search' => 'concert']);

    $allEvents = array_merge(...array_map(fn($row) => array_merge(...array_map(fn($cell) => $cell['events'], $row)), $rows));
    $this->assertCount(1, $allEvents);
    $this->assertSame('Spring Concert', $allEvents[0]['title']);
  }

  /**
   * A search term under 3 characters is ignored (no filtering applied).
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarIgnoresShortSearchTerms() {
    $node = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Spring Concert',
      'event_dates' => [[
        'value' => strtotime('2024-06-10 09:00:00'),
        'end_value' => strtotime('2024-06-10 10:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $node]);

    $rows = $this->eventsCalendar->getCalendar('06', '2024', ['search' => 'zz']);

    $allEvents = array_merge(...array_map(fn($row) => array_merge(...array_map(fn($cell) => $cell['events'], $row)), $rows));
    $this->assertCount(1, $allEvents);
  }

  /**
   * GetCalendar() filters events by category, matching term descendants.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarFiltersByCategory() {
    $inCategory = $this->createMockEventNode([
      'id' => 1,
      'title' => 'In Category',
      'event_dates' => [[
        'value' => strtotime('2024-06-10 09:00:00'),
        'end_value' => strtotime('2024-06-10 10:00:00'),
      ],
      ],
      'category_tids' => [10],
    ]);
    $outOfCategory = $this->createMockEventNode([
      'id' => 2,
      'title' => 'Out of Category',
      'event_dates' => [[
        'value' => strtotime('2024-06-10 09:00:00'),
        'end_value' => strtotime('2024-06-10 10:00:00'),
      ],
      ],
      'category_tids' => [99],
    ]);
    $this->configureNodeStorageToReturn([1 => $inCategory, 2 => $outOfCategory]);

    $rows = $this->eventsCalendar->getCalendar('06', '2024', [
      'category_included_terms' => [10],
    ]);

    $allEvents = array_merge(...array_map(fn($row) => array_merge(...array_map(fn($cell) => $cell['events'], $row)), $rows));
    $this->assertCount(1, $allEvents);
    $this->assertSame('In Category', $allEvents[0]['title']);
  }

  /**
   * GetCalendar() 'future' time period excludes an event that has ended.
   *
   * The event is dated June 1990 -- a single, narrow-range day that is
   * unambiguously in the past relative to any real clock the suite could
   * run on, without depending on time(). Viewing that same (1990) month
   * means the event would otherwise render on this grid, so its absence
   * here is due to the time-period filter, not a day/month mismatch.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarFutureTimePeriodExcludesEndedEvent() {
    $pastEvent = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Long-past Event',
      'event_dates' => [[
        'value' => strtotime('1990-06-10 09:00:00'),
        'end_value' => strtotime('1990-06-10 10:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $pastEvent]);

    $rows = $this->eventsCalendar->getCalendar('06', '1990', [
      'event_time_period' => 'future',
    ]);

    $allEvents = array_merge(...array_map(fn($row) => array_merge(...array_map(fn($cell) => $cell['events'], $row)), $rows));
    $this->assertSame([], $allEvents);
  }

  /**
   * GetCalendar() 'past' time period keeps an event that has already ended.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarPastTimePeriodKeepsEndedEvent() {
    $pastEvent = $this->createMockEventNode([
      'id' => 1,
      'title' => 'Long-past Event',
      'event_dates' => [[
        'value' => strtotime('1990-06-10 09:00:00'),
        'end_value' => strtotime('1990-06-10 10:00:00'),
      ],
      ],
    ]);
    $this->configureNodeStorageToReturn([1 => $pastEvent]);

    $rows = $this->eventsCalendar->getCalendar('06', '1990', [
      'event_time_period' => 'past',
    ]);

    $allEvents = array_merge(...array_map(fn($row) => array_merge(...array_map(fn($cell) => $cell['events'], $row)), $rows));
    $this->assertCount(1, $allEvents);
    $this->assertSame('Long-past Event', $allEvents[0]['title']);
  }

  /**
   * GetCalendar() queries published event nodes overlapping the month.
   *
   * @covers ::getCalendar
   */
  public function testGetCalendarQueriesPublishedEventsForMonth() {
    $node = $this->createMockEventNode(['id' => 7]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $conditions = [];
    $query->method('condition')->willReturnCallback(function (...$args) use ($query, &$conditions) {
      $conditions[] = $args;
      return $query;
    });
    $query->method('execute')->willReturn([7]);

    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->expects($this->once())
      ->method('loadMultiple')
      ->with([7])
      ->willReturn([7 => $node]);

    $this->eventsCalendar->getCalendar('06', '2024');

    $this->assertSame(['type', 'event'], [$conditions[0][0], $conditions[0][1]]);
    $this->assertSame(['status', 1], [$conditions[1][0], $conditions[1][1]]);
  }

  /**
   * Trailing overflow cells belong to the month after the viewed one.
   *
   * Regression coverage for yalesites-org/YaleSites-Internal#1379: the trailing
   * overflow cells in a month grid's last row were tagged with the wrong month
   * because the next month was derived from the last day of the current month
   * via DrupalDateTime::modify('+1 month'), which overflows for any 31-day
   * month whose successor is shorter (e.g. 2026-08-31 + 1 month lands on
   * 2026-10-01, not September). The fix anchors the next month on the first of
   * the month, which is safe in every month.
   *
   * @covers ::getCalendar
   *
   * @dataProvider trailingOverflowProvider
   */
  public function testTrailingOverflowMonth(string $month, string $year, string $expectedMonth, string $expectedYear): void {
    $this->configureNodeStorageToReturn([]);

    $calendar = $this->eventsCalendar->getCalendar($month, $year);
    $lastRow = end($calendar);

    // Trailing overflow cells are the last-row cells that fall outside the
    // viewed month.
    $overflow = array_values(array_filter(
      $lastRow,
      fn(array $cell): bool => $cell['date']['month'] !== $month
    ));

    // Independently derive how many trailing overflow cells this month should
    // have (0 when the month ends on a Saturday) using a plain DateTime, so the
    // assertions hold for any month instead of depending on the fixture only
    // listing months whose last day is not a Saturday.
    $lastWeekday = (int) (new \DateTime("$year-$month-01"))
      ->modify('last day of this month')
      ->format('w');
    $this->assertCount(
      6 - $lastWeekday,
      $overflow,
      "Unexpected number of trailing overflow cells for $month/$year."
    );

    foreach ($overflow as $cell) {
      $this->assertSame(
        $expectedMonth,
        $cell['date']['month'],
        "Trailing overflow cell for $month/$year should belong to month $expectedMonth, got {$cell['date']['month']}."
      );
      $this->assertSame(
        $expectedYear,
        $cell['date']['year'],
        "Trailing overflow cell for $month/$year should belong to year $expectedYear, got {$cell['date']['year']}."
      );
    }
  }

  /**
   * Provides viewed months and the month/year the trailing overflow belongs to.
   *
   * @return array
   *   Each case: [viewed month, viewed year, expected overflow month, year].
   */
  public static function trailingOverflowProvider(): array {
    return [
      // Regression cases: 31-day month whose successor is shorter. Before the
      // fix these overflowed two months ahead.
      'August -> September' => ['08', '2026', '09', '2026'],
      'January -> February' => ['01', '2025', '02', '2025'],
      'March -> April' => ['03', '2026', '04', '2026'],
      'May -> June' => ['05', '2026', '06', '2026'],
      'October -> November' => ['10', '2025', '11', '2025'],

      // Controls that must stay correct after the fix.
      // December rolls the year over.
      'December -> January (year rollover)' => ['12', '2026', '01', '2027'],
      // July's successor (August) also has 31 days: never overflowed.
      'July -> August' => ['07', '2026', '08', '2026'],
      // February has fewer than 31 days: never overflowed.
      'February -> March' => ['02', '2025', '03', '2025'],
    ];
  }

}
