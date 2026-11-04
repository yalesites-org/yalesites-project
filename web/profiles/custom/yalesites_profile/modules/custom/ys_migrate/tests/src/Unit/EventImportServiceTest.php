<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\EventImportService;
use Drupal\ys_migrate\Service\TaxonomyResolverService;

/**
 * Unit tests for EventImportService.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Service\EventImportService
 * @group ys_migrate
 * @group yalesites
 */
class EventImportServiceTest extends UnitTestCase {

  /**
   * The taxonomy resolver mock.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $taxonomyResolver;

  /**
   * Storage mocks keyed by entity type id.
   *
   * Any entity type without its own entry (field_config,
   * entity_form_display) gets $configStorage, whose load() finds nothing, so
   * the service falls back to its documented defaults.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface[]|\PHPUnit\Framework\MockObject\MockObject[]
   */
  protected $storages = [];

  /**
   * The storage mock for config the service reads but tests rarely vary.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configStorage;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The service under test, reading times in America/New_York.
   *
   * @var \Drupal\ys_migrate\Service\EventImportService
   */
  protected $eventImport;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->taxonomyResolver = $this->createMock(TaxonomyResolverService::class);
    $this->taxonomyResolver->method('parseCommaSeparatedValues')
      ->willReturnCallback(function ($value) {
        return $value === '' ? [] : array_map('trim', array_filter(explode(',', $value)));
      });
    $this->taxonomyResolver->method('findOrCreateTerm')
      ->willReturnCallback(function ($name, $vocabulary) {
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($name === 'CSV import' && $vocabulary === 'event_sources' ? 99 : 0);
        return $term;
      });

    foreach (['node', 'path_alias'] as $entity_type) {
      $this->storages[$entity_type] = $this->createMock(EntityStorageInterface::class);
    }
    $this->configStorage = $this->createMock(EntityStorageInterface::class);

    // Nothing uses a URL alias yet unless a test says otherwise.
    $this->storages['path_alias']->method('getQuery')->willReturnCallback(fn () => $this->mockQuery([]));

    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);

    $this->eventImport = $this->buildService('America/New_York');
  }

  /**
   * Builds the service with the site timezone set to the given zone.
   */
  protected function buildService(string $timezone): EventImportService {
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(42);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(fn ($entity_type) => $this->storages[$entity_type] ?? $this->configStorage);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('ys_migrate')->willReturn($this->loggerChannel);

    $service = new EventImportService(
      $currentUser,
      $this->taxonomyResolver,
      $entityTypeManager,
      $loggerFactory,
      $this->getConfigFactoryStub(['system.date' => ['timezone.default' => $timezone]])
    );
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

  /**
   * Builds a query mock that returns the given ids from execute().
   */
  protected function mockQuery(array $ids, ?array &$conditions = NULL): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnCallback(function ($field, $value) use (&$conditions, $query) {
      $conditions[$field] = $value;
      return $query;
    });
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Makes findTerm() find the given terms, and nothing else.
   *
   * @param array $terms
   *   Term ids keyed by vocabulary, then by term name.
   */
  protected function knownTerms(array $terms): void {
    $this->taxonomyResolver->method('findTerm')
      ->willReturnCallback(function ($name, $vocabulary) use ($terms) {
        if (!isset($terms[$vocabulary][$name])) {
          return NULL;
        }
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($terms[$vocabulary][$name]);
        return $term;
      });
  }

  /**
   * Captures the values the next node create() receives.
   */
  protected function captureNodeValues(?array &$captured): void {
    $node = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('create')
      ->willReturnCallback(function ($values) use (&$captured, $node) {
        $captured = $values;
        return $node;
      });
  }

  /**
   * The UNIX timestamp of a wall-clock time in the given timezone.
   */
  protected function ts(string $datetime, string $timezone = 'America/New_York'): int {
    return (new \DateTimeImmutable($datetime, new \DateTimeZone($timezone)))->getTimestamp();
  }

  /**
   * A full CSV row is trimmed and reshaped into the expected event data.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataWithFullRow() {
    $this->knownTerms([
      'event_category' => ['Lecture' => 11],
      'tags' => ['Research' => 12, 'Climate' => 13],
      'audience' => ['Faculty' => 14],
      'custom_vocab' => ['Sustainability' => 15],
      'event_type' => ['In-person' => 16],
    ]);

    $result = $this->eventImport->prepareEventData([
      'title' => '  Climate Symposium  ',
      'start' => ' 2026-03-04 14:00 ',
      'end' => '2026-03-04 16:30',
      'all day' => 'No',
      'description' => ' <p>Talks.</p> ',
      'location details' => " Kroon Hall\n195 Prospect Street ",
      'event experience' => 'In-person',
      'event website' => ' https://example.com/symposium ',
      'event website text' => ' Register ',
      'ticket cost' => ' Free ',
      'ticket registration url' => 'https://example.com/register',
      'stream url' => 'https://example.com/stream',
      'event category' => 'Lecture',
      'tags' => 'Research, Climate',
      'audience' => 'Faculty',
      'custom vocab' => 'Sustainability',
      'teaser title' => ' Symposium ',
      'teaser text' => ' Short teaser. ',
      'cas login required' => 'Yes',
      'url alias' => 'events/climate-symposium',
      'event id' => ' 1234 ',
    ]);

    $this->assertSame('Climate Symposium', $result['title']);
    $this->assertSame($this->ts('2026-03-04 14:00'), $result['start']);
    $this->assertSame($this->ts('2026-03-04 16:30'), $result['end']);
    $this->assertFalse($result['all_day']);
    $this->assertSame('2026-03-04 14:00', $result['start_text']);
    $this->assertSame('2026-03-04 16:30', $result['end_text']);
    $this->assertSame('<p>Talks.</p>', $result['description']);
    $this->assertFalse($result['description_has_media']);
    $this->assertSame("Kroon Hall\n195 Prospect Street", $result['location_details']);
    $this->assertSame(['In-person'], $result['experience']);
    $this->assertSame('https://example.com/symposium', $result['event_website']);
    $this->assertSame('Register', $result['event_website_text']);
    $this->assertSame('Free', $result['ticket_cost']);
    $this->assertSame('https://example.com/register', $result['ticket_registration_url']);
    $this->assertSame('https://example.com/stream', $result['stream_url']);
    $this->assertSame(['Lecture'], $result['category']);
    $this->assertSame(['Research', 'Climate'], $result['tags']);
    $this->assertSame(['Faculty'], $result['audience']);
    $this->assertSame(['Sustainability'], $result['custom_vocab']);
    $this->assertSame('Symposium', $result['teaser_title']);
    $this->assertSame('Short teaser.', $result['teaser_text']);
    $this->assertTrue($result['login_required']);
    $this->assertSame('/events/climate-symposium', $result['url_alias']);
    $this->assertSame('1234', $result['event_id']);
  }

  /**
   * Every recognised CSV column is actually read into the prepared data.
   *
   * A column added to CsvValidatorService but never read here is validated,
   * listed as recognised, and given an example in the sample CSV -- and then
   * silently dropped.
   *
   * @covers ::prepareEventData
   */
  public function testEveryExpectedColumnIsReadIntoPreparedData() {
    // Each recognised column and the prepared-data key it lands in.
    $lands_in = [
      'title' => 'title',
      'start' => 'start',
      'end' => 'end',
      'all day' => 'all_day',
      'description' => 'description',
      'location details' => 'location_details',
      'event experience' => 'experience',
      'event website' => 'event_website',
      'event website text' => 'event_website_text',
      'ticket cost' => 'ticket_cost',
      'ticket registration url' => 'ticket_registration_url',
      'stream url' => 'stream_url',
      'event category' => 'category',
      'tags' => 'tags',
      'audience' => 'audience',
      'custom vocab' => 'custom_vocab',
      'teaser title' => 'teaser_title',
      'teaser text' => 'teaser_text',
      'cas login required' => 'login_required',
      'url alias' => 'url_alias',
      'event id' => 'event_id',
    ];

    $this->assertEqualsCanonicalizing(
      array_keys(CsvValidatorService::EXPECTED_EVENT_COLUMNS),
      array_keys($lands_in),
      'Every recognised column must name the prepared-data key it lands in.'
    );

    $prepared = $this->eventImport->prepareEventData(['start' => '2026-03-04 14:00']);
    // These are derived from other cells rather than read from a column.
    unset($prepared['start_text'], $prepared['end_text'], $prepared['description_has_media']);

    $this->assertEqualsCanonicalizing(
      array_values($lands_in),
      array_keys($prepared),
      'Every mapped key must be produced by prepareEventData().'
    );
  }

  /**
   * A row with only the required cells yields empty values for the rest.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataWithRequiredCellsOnly() {
    $result = $this->eventImport->prepareEventData([
      'title' => 'Bare Event',
      'start' => '2026-03-04 14:00',
    ]);

    $this->assertSame('', $result['description']);
    $this->assertSame('', $result['location_details']);
    $this->assertSame([], $result['experience']);
    $this->assertSame('', $result['event_website']);
    $this->assertSame('', $result['event_website_text']);
    $this->assertSame('', $result['ticket_cost']);
    $this->assertSame([], $result['category']);
    $this->assertFalse($result['login_required']);
    $this->assertSame('', $result['url_alias']);
    $this->assertSame('', $result['event_id']);
  }

  /**
   * Start is required.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataRequiresStart() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Start is required.');

    $this->eventImport->prepareEventData(['title' => 'No Start', 'start' => ' ']);
  }

  /**
   * Start, End and All Day combine into one date, read in the site timezone.
   *
   * @param array $cells
   *   The Start, End and All Day cells.
   * @param string $start
   *   The expected start, as Y-m-d H:i in America/New_York.
   * @param string $end
   *   The expected end, as Y-m-d H:i in America/New_York.
   * @param bool $all_day
   *   Whether the event is expected to be all day.
   *
   * @covers ::prepareEventData
   *
   * @dataProvider eventDatesProvider
   */
  public function testPrepareEventDataReadsDates(array $cells, string $start, string $end, bool $all_day) {
    $result = $this->eventImport->prepareEventData(['title' => 'Dated'] + $cells);

    $this->assertSame($this->ts($start), $result['start'], 'Start');
    $this->assertSame($this->ts($end), $result['end'], 'End');
    $this->assertSame($all_day, $result['all_day']);
  }

  /**
   * Date cells the importer reads, and what they mean.
   */
  public static function eventDatesProvider(): array {
    return [
      '24-hour times' => [
        ['start' => '2026-03-04 14:00', 'end' => '2026-03-04 16:30'],
        '2026-03-04 14:00', '2026-03-04 16:30', FALSE,
      ],
      'US dates with 12-hour times' => [
        ['start' => '3/4/2026 2:00 PM', 'end' => '03/04/2026 4:30 pm'],
        '2026-03-04 14:00', '2026-03-04 16:30', FALSE,
      ],
      'ISO 8601 with seconds' => [
        ['start' => '2026-03-04T14:00:00', 'end' => '2026-03-04T16:30:00'],
        '2026-03-04 14:00', '2026-03-04 16:30', FALSE,
      ],
      'End as a time only is on the same day' => [
        ['start' => '2026-03-04 2pm', 'end' => '4:30 p.m.'],
        '2026-03-04 14:00', '2026-03-04 16:30', FALSE,
      ],
      'midnight and noon in 12-hour form' => [
        ['start' => '2026-03-04 12:00 AM', 'end' => '2026-03-04 12:00 PM'],
        '2026-03-04 00:00', '2026-03-04 12:00', FALSE,
      ],
      'no End is a point in time' => [
        ['start' => '2026-03-04 14:00', 'end' => ''],
        '2026-03-04 14:00', '2026-03-04 14:00', FALSE,
      ],
      'spanning midnight' => [
        ['start' => '2026-03-04 22:00', 'end' => '2026-03-05 01:00'],
        '2026-03-04 22:00', '2026-03-05 01:00', FALSE,
      ],
      'a date alone is all day' => [
        ['start' => '2026-03-04'],
        '2026-03-04 00:00', '2026-03-04 23:59', TRUE,
      ],
      'all day over several days' => [
        ['start' => '2026-03-04', 'end' => '2026-03-06'],
        '2026-03-04 00:00', '2026-03-06 23:59', TRUE,
      ],
      'All Day Yes ignores the times' => [
        ['start' => '2026-03-04 14:00', 'end' => '2026-03-04 16:00', 'all day' => 'Yes'],
        '2026-03-04 00:00', '2026-03-04 23:59', TRUE,
      ],
      'summer time' => [
        ['start' => '2026-07-04 21:00', 'end' => '2026-07-04 22:00'],
        '2026-07-04 21:00', '2026-07-04 22:00', FALSE,
      ],
    ];
  }

  /**
   * Times are read in the site's configured timezone, not the server's.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataUsesSiteTimezone() {
    $result = $this->buildService('America/Los_Angeles')->prepareEventData([
      'title' => 'Pacific',
      'start' => '2026-03-04 14:00',
    ]);

    $this->assertSame($this->ts('2026-03-04 14:00', 'America/Los_Angeles'), $result['start']);
    $this->assertSame('2026-03-04 14:00', $result['start_text']);
  }

  /**
   * Date cells the importer must reject, and why.
   *
   * @param array $cells
   *   The Start, End and All Day cells.
   * @param string $message
   *   The expected error message.
   *
   * @covers ::prepareEventData
   *
   * @dataProvider badEventDatesProvider
   */
  public function testPrepareEventDataRejectsBadDates(array $cells, string $message) {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    $this->eventImport->prepareEventData(['title' => 'Bad Dates'] + $cells);
  }

  /**
   * Bad date cells and the message each produces.
   */
  public static function badEventDatesProvider(): array {
    return [
      'unreadable Start' => [
        ['start' => 'next Tuesday'],
        'Start "next Tuesday" is not a valid date and time.',
      ],
      'impossible date' => [
        ['start' => '2026-02-31 10:00'],
        'Start "2026-02-31 10:00" is not a valid date and time.',
      ],
      'hour out of range' => [
        ['start' => '2026-03-04 25:00'],
        'Start "2026-03-04 25:00" does not have a valid time.',
      ],
      'bare hour without am or pm' => [
        ['start' => '2026-03-04 14'],
        'Start "2026-03-04 14" does not have a valid time.',
      ],
      'Start with no date' => [
        ['start' => '14:00'],
        'Start "14:00" needs a date as well as a time.',
      ],
      'All Day No without a time' => [
        ['start' => '2026-03-04', 'all day' => 'No'],
        'Start "2026-03-04" needs a time, such as 2026-03-04 14:00, unless All Day is Yes.',
      ],
      'End without a time after a timed Start' => [
        ['start' => '2026-03-04 14:00', 'end' => '2026-03-05'],
        'End "2026-03-05" needs a time, since Start has one.',
      ],
      'End before Start' => [
        ['start' => '2026-03-04 14:00', 'end' => '2026-03-04 13:00'],
        'End "2026-03-04 13:00" is before Start "2026-03-04 14:00".',
      ],
      'all-day End before Start' => [
        ['start' => '2026-03-06', 'end' => '2026-03-04'],
        'End "2026-03-04" is before Start "2026-03-06".',
      ],
      'unrecognised All Day' => [
        ['start' => '2026-03-04', 'all day' => 'maybe'],
        'All Day "maybe" is not a yes/no value.',
      ],
    ];
  }

  /**
   * Unknown terms fail the row instead of being created.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataRejectsUnknownTerms() {
    $this->knownTerms(['event_type' => ['In-person' => 16]]);
    $this->taxonomyResolver->expects($this->never())->method('findOrCreateTerm');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Event Category has no existing term named "Lectures".');

    $this->eventImport->prepareEventData([
      'title' => 'Typo',
      'start' => '2026-03-04 14:00',
      'event category' => 'Lectures',
    ]);
  }

  /**
   * Each taxonomy column is looked up in the event bundle's own vocabulary.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataLooksUpEachColumnInItsOwnVocabulary() {
    $lookups = [];
    $this->taxonomyResolver->method('findTerm')
      ->willReturnCallback(function ($name, $vocabulary) use (&$lookups) {
        $lookups[$name] = $vocabulary;
        return $this->createMock(TermInterface::class);
      });

    $this->eventImport->prepareEventData([
      'title' => 'Vocabularies',
      'start' => '2026-03-04 14:00',
      'event category' => 'A',
      'tags' => 'B',
      'audience' => 'C',
      'custom vocab' => 'D',
      'event experience' => 'E',
    ]);

    // assertEquals, since the order the columns are read in does not matter.
    $this->assertEquals([
      'A' => 'event_category',
      'B' => 'tags',
      'C' => 'audience',
      'D' => 'custom_vocab',
      'E' => 'event_type',
    ], $lookups);
  }

  /**
   * Event Experience is a single-value field.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataTakesOneEventExperience() {
    $this->knownTerms(['event_type' => ['In-person' => 16, 'Online' => 17]]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Event Experience takes one value, such as In-person, Online or Hybrid, not "In-person, Online".');

    $this->eventImport->prepareEventData([
      'title' => 'Both',
      'start' => '2026-03-04 14:00',
      'event experience' => 'In-person, Online',
    ]);
  }

  /**
   * Event Website accepts a site path and gets link text when it has none.
   *
   * @param array $cells
   *   The Event Website and Event Website Text cells.
   * @param string $uri
   *   The expected link URI.
   * @param string $text
   *   The expected link text.
   *
   * @covers ::prepareEventData
   *
   * @dataProvider eventWebsiteProvider
   */
  public function testPrepareEventDataReadsEventWebsite(array $cells, string $uri, string $text) {
    $result = $this->eventImport->prepareEventData(['title' => 'Linked', 'start' => '2026-03-04 14:00'] + $cells);

    $this->assertSame($uri, $result['event_website']);
    $this->assertSame($text, $result['event_website_text']);
  }

  /**
   * Event Website cells and the link they make.
   */
  public static function eventWebsiteProvider(): array {
    return [
      'full address with text' => [
        ['event website' => 'https://example.com/rsvp', 'event website text' => 'RSVP'],
        'https://example.com/rsvp', 'RSVP',
      ],
      // The event page uses the text as the button label, with no fallback.
      'full address without text' => [
        ['event website' => 'https://example.com/rsvp'],
        'https://example.com/rsvp', 'Event Website',
      ],
      'path on this site' => [
        ['event website' => '/events/register'],
        'internal:/events/register', 'Event Website',
      ],
      'neither' => [
        [],
        '', '',
      ],
    ];
  }

  /**
   * Link and plain-text cells the importer must reject, and why.
   *
   * @param array $cells
   *   The cells to add to an otherwise valid row.
   * @param string $message
   *   The expected error message.
   *
   * @covers ::prepareEventData
   *
   * @dataProvider badCellProvider
   */
  public function testPrepareEventDataRejectsBadCells(array $cells, string $message) {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    $this->eventImport->prepareEventData(['title' => 'Bad Cell', 'start' => '2026-03-04 14:00'] + $cells);
  }

  /**
   * Bad cells and the message each produces.
   */
  public static function badCellProvider(): array {
    return [
      'script in Event Website' => [
        ['event website' => 'javascript:alert(1)'],
        'Event Website "javascript:alert(1)" is not a valid http or https URL.',
      ],
      'protocol-relative Event Website' => [
        ['event website' => '//example.com/rsvp'],
        'Event Website "//example.com/rsvp" is not a valid http or https URL.',
      ],
      'text without an Event Website' => [
        ['event website text' => 'Register'],
        'Event Website Text needs an Event Website to link to.',
      ],
      'Ticket Registration URL' => [
        ['ticket registration url' => 'example.com/register'],
        'Ticket Registration URL "example.com/register" is not a valid http or https URL.',
      ],
      'Stream URL' => [
        ['stream url' => 'ftp://example.com/stream'],
        'Stream URL "ftp://example.com/stream" is not a valid http or https URL.',
      ],
      'Ticket Cost too long' => [
        ['ticket cost' => str_repeat('x', 256)],
        'Ticket Cost is 256 characters; the limit is 255.',
      ],
      'Event ID too long' => [
        ['event id' => str_repeat('1', 300)],
        'Event ID is 300 characters; the limit is 255.',
      ],
      'URL Alias as a web address' => [
        ['url alias' => 'https://example.com/events/a'],
        'URL Alias "https://example.com/events/a" must be a path such as /events/my-event, not a full web address.',
      ],
      'Teaser Text too long' => [
        ['teaser text' => str_repeat('x', 151)],
        'Teaser Text is 151 characters; the limit is 150.',
      ],
    ];
  }

  /**
   * Images and D7 tokens in the Description are flagged; tokens are removed.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataFlagsDescriptionMedia() {
    $result = $this->eventImport->prepareEventData([
      'title' => 'Pictured',
      'start' => '2026-03-04 14:00',
      'description' => '<p>Before</p>[[{"type":"media","fid":"12"}]]<p>After</p>',
    ]);

    $this->assertSame('<p>Before</p><p>After</p>', $result['description']);
    $this->assertTrue($result['description_has_media']);
  }

  /**
   * A plain-text Description becomes paragraphs.
   *
   * @covers ::prepareEventData
   */
  public function testPrepareEventDataWrapsPlainTextDescription() {
    $result = $this->eventImport->prepareEventData([
      'title' => 'Plain',
      'start' => '2026-03-04 14:00',
      'description' => "First line\nsame paragraph.\n\nSecond & last.",
    ]);

    $this->assertSame("<p>First line<br>\nsame paragraph.</p>\n<p>Second &amp; last.</p>", $result['description']);
  }

  /**
   * An existing event is matched on title and start.
   *
   * @covers ::findExistingEvent
   */
  public function testFindExistingEventMatchesTitleAndStart() {
    $node = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([7], $conditions));
    $this->storages['node']->method('load')->with(7)->willReturn($node);
    $this->taxonomyResolver->expects($this->never())->method('findTerm');

    $this->assertSame($node, $this->eventImport->findExistingEvent('Symposium', 1772650800, '1234'));
    $this->assertSame([
      'type' => 'event',
      'title' => 'Symposium',
      'field_event_date.value' => 1772650800,
    ], $conditions);
  }

  /**
   * An Event ID matches an event an earlier CSV import created from it.
   *
   * @covers ::findExistingEvent
   */
  public function testFindExistingEventMatchesImportedEventId() {
    $this->knownTerms(['event_sources' => ['CSV import' => 99]]);
    $node = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturnOnConsecutiveCalls(
      $this->mockQuery([]),
      $this->mockQuery([8], $conditions)
    );
    $this->storages['node']->method('load')->with(8)->willReturn($node);

    $this->assertSame($node, $this->eventImport->findExistingEvent('Renamed', 1772650800, '1234'));
    // Only the CSV import source is searched: Localist and Campus Groups store
    // their own ids in the same field.
    $this->assertSame([
      'type' => 'event',
      'field_event_source' => 99,
      'field_event_id' => '1234',
    ], $conditions);
  }

  /**
   * With no CSV import source yet, no event can share an imported id.
   *
   * @covers ::findExistingEvent
   */
  public function testFindExistingEventSkipsIdCheckBeforeFirstImport() {
    $this->storages['node']->expects($this->once())->method('getQuery')->willReturn($this->mockQuery([]));
    $this->storages['node']->expects($this->never())->method('load');

    $this->assertNull($this->eventImport->findExistingEvent('New', 1772650800, '1234'));
  }

  /**
   * Prepared data is mapped onto the event bundle's real field names.
   *
   * @covers ::createEventNode
   */
  public function testCreateEventNodeMapsFields() {
    $this->knownTerms([
      'event_category' => ['Lecture' => 11],
      'tags' => ['Research' => 12],
      'audience' => ['Faculty' => 13],
      'custom_vocab' => ['Sustainability' => 14],
      'event_type' => ['In-person' => 16],
    ]);
    $this->captureNodeValues($captured);

    $this->eventImport->createEventNode([
      'title' => 'Climate Symposium',
      'start' => 1772650800,
      'end' => 1772659800,
      'description' => '<p>Talks.</p>',
      'location_details' => "Kroon Hall\n195 Prospect Street",
      'experience' => ['In-person'],
      'event_website' => 'https://example.com/symposium',
      'event_website_text' => 'Register',
      'ticket_cost' => 'Free',
      'ticket_registration_url' => 'https://example.com/register',
      'stream_url' => 'https://example.com/stream',
      'category' => ['Lecture'],
      'tags' => ['Research'],
      'audience' => ['Faculty'],
      'custom_vocab' => ['Sustainability'],
      'teaser_title' => 'Symposium',
      'teaser_text' => 'Short teaser.',
      'login_required' => TRUE,
      'url_alias' => '/events/climate-symposium',
      'event_id' => '1234',
    ] + $this->emptyData());

    $this->assertSame('event', $captured['type']);
    $this->assertSame('Climate Symposium', $captured['title']);
    $this->assertSame(
      ['value' => 1772650800, 'end_value' => 1772659800, 'duration' => 150],
      $captured['field_event_date']
    );
    $this->assertSame([11], $captured['field_category']);
    $this->assertSame([12], $captured['field_tags']);
    $this->assertSame([13], $captured['field_audience']);
    $this->assertSame([14], $captured['field_custom_vocab']);
    $this->assertSame([16], $captured['field_localist_event_experience']);
    $this->assertSame(
      ['uri' => 'https://example.com/symposium', 'title' => 'Register'],
      $captured['field_event_cta']
    );
    $this->assertSame('Free', $captured['field_ticket_cost']);
    $this->assertSame(['uri' => 'https://example.com/register'], $captured['field_ticket_registration_url']);
    $this->assertSame(['uri' => 'https://example.com/stream'], $captured['field_stream_url']);
    $this->assertSame('Symposium', $captured['field_teaser_title']);
    $this->assertSame(1, $captured['field_login_required']);
    $this->assertSame(42, $captured['uid']);

    // Imported events are marked so they can be told apart from synced ones.
    $this->assertSame([99], $captured['field_event_source']);
    $this->assertSame('1234', $captured['field_event_id']);
    // A Localist ID would make the event page treat it as a Localist event.
    $this->assertArrayNotHasKey('field_localist_id', $captured);

    // The events workflow publishes new events by default, so draft has to
    // be asked for. Setting 'status' instead is silently overridden.
    $this->assertSame('draft', $captured['moderation_state']);
    $this->assertArrayNotHasKey('status', $captured);

    // Long-text fields must carry an explicit format the field allows, or the
    // widget renders disabled on the node form.
    $this->assertSame(['value' => '<p>Talks.</p>', 'format' => 'basic_html'], $captured['field_event_description']);
    $this->assertSame(
      ['value' => "Kroon Hall\n195 Prospect Street", 'format' => 'restricted_html'],
      $captured['field_event_location_details']
    );
    $this->assertSame(['value' => 'Short teaser.', 'format' => 'heading_html'], $captured['field_teaser_text']);

    // Pathauto would otherwise replace the alias with one from its pattern.
    $this->assertSame(['alias' => '/events/climate-symposium', 'pathauto' => 0], $captured['path']);

    // The import brings in no images; editors add a teaser image afterwards.
    $this->assertArrayNotHasKey('field_teaser_media', $captured);
  }

  /**
   * An all-day date is stored as Smart Date stores one: midnight to 23:59.
   *
   * @covers ::createEventNode
   */
  public function testCreateEventNodeStoresAllDayDuration() {
    $this->captureNodeValues($captured);

    $data = $this->eventImport->prepareEventData(['title' => 'All Day', 'start' => '2026-03-04']);
    $this->eventImport->createEventNode($data);

    $this->assertSame([
      'value' => $this->ts('2026-03-04 00:00'),
      'end_value' => $this->ts('2026-03-04 23:59'),
      'duration' => 1439,
    ], $captured['field_event_date']);
  }

  /**
   * Empty optional values are omitted rather than written as empty fields.
   *
   * @covers ::createEventNode
   */
  public function testCreateEventNodeOmitsEmptyOptionalFields() {
    $this->captureNodeValues($captured);

    $this->eventImport->createEventNode(['title' => 'Bare Event', 'start' => 1772650800, 'end' => 1772650800] + $this->emptyData());

    foreach ([
      'field_event_description',
      'field_event_location_details',
      'field_event_cta',
      'field_ticket_cost',
      'field_ticket_registration_url',
      'field_stream_url',
      'field_teaser_title',
      'field_teaser_text',
      'path',
      'field_event_id',
    ] as $field) {
      $this->assertArrayNotHasKey($field, $captured);
    }
    $this->assertSame(0, $captured['field_event_date']['duration']);
    // Every imported event is marked, with or without an old id.
    $this->assertSame([99], $captured['field_event_source']);
  }

  /**
   * A save failure is logged and re-thrown with its real reason.
   *
   * @covers ::createEventNode
   */
  public function testCreateEventNodeRethrowsSaveFailure() {
    $node = $this->createMock(NodeInterface::class);
    $node->method('save')->willThrowException(new \Exception('Database went away'));
    $this->storages['node']->method('create')->willReturn($node);

    $this->loggerChannel->expects($this->once())->method('error');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Database went away');

    $this->eventImport->createEventNode(['title' => 'Doomed', 'start' => 1772650800, 'end' => 1772650800] + $this->emptyData());
  }

  /**
   * Import counts creations and lists events whose Description lost images.
   *
   * @covers ::processImport
   */
  public function testProcessImportFlagsEventsNeedingImages() {
    $this->captureNodeValues($captured);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->eventImport->processImport([
      ['title' => 'Text Only', 'start' => '2026-03-04 14:00', 'description' => '<p>Words.</p>'],
      ['title' => 'Had Image', 'start' => '2026-03-05 14:00', 'description' => '<p>Words.</p><img src="/a.jpg">'],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertSame(0, $result['skipped']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(['Had Image'], $result['needs_images']);
  }

  /**
   * A duplicate is skipped, even when its URL Alias is (by itself) in use.
   *
   * Re-running a file must report its rows as duplicates, not as alias
   * clashes with the events the first run created.
   *
   * @covers ::processImport
   */
  public function testProcessImportSkipsDuplicatesBeforeCheckingAliases() {
    $existing = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([9]));
    $this->storages['node']->method('load')->willReturn($existing);
    $this->storages['node']->expects($this->never())->method('create');

    // Replace the default "no alias in use" query.
    $this->storages['path_alias'] = $this->createMock(EntityStorageInterface::class);
    $this->storages['path_alias']->method('getQuery')->willReturn($this->mockQuery([3]));

    $result = $this->eventImport->processImport([
      ['title' => 'Already Here', 'start' => '2026-03-04 14:00', 'url alias' => '/events/already-here'],
    ], TRUE);

    $this->assertSame(0, $result['created']);
    $this->assertSame(1, $result['skipped']);
    $this->assertSame([], $result['errors']);
  }

  /**
   * A bad cell fails only its own row and reports the real CSV line number.
   *
   * @covers ::processImport
   */
  public function testProcessImportReportsRowErrorsWithoutStoppingTheRun() {
    $this->captureNodeValues($captured);
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->eventImport->processImport([
      ['title' => 'Good', 'start' => '2026-03-04 14:00', '_row_number' => 2],
      ['title' => 'Backwards', 'start' => '2026-03-04 14:00', 'end' => '2026-03-04 13:00', '_row_number' => 7],
      ['title' => 'Also Good', 'start' => '2026-03-05', '_row_number' => 8],
    ], FALSE);

    $this->assertSame(2, $result['created']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 7: End "2026-03-04 13:00" is before Start', $this->text($result['errors'][0]));
  }

  /**
   * Preview creates nothing, not even the Event Source term.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportCreatesNothing() {
    $existing = $this->createMock(NodeInterface::class);
    $this->storages['node']->method('getQuery')->willReturnOnConsecutiveCalls(
      $this->mockQuery([]),
      $this->mockQuery([9])
    );
    $this->storages['node']->method('load')->willReturn($existing);
    $this->storages['node']->expects($this->never())->method('create');
    $this->taxonomyResolver->expects($this->never())->method('findOrCreateTerm');

    $result = $this->eventImport->previewImport([
      ['title' => 'New One', 'start' => '2026-03-04 14:00'],
      ['title' => 'Already Here', 'start' => '2026-03-04 14:00'],
    ], TRUE);

    $this->assertCount(1, $result['valid_events']);
    $this->assertSame('New One', $result['valid_events'][0]['title']);
    $this->assertSame(['Already Here'], $result['duplicates']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(2, $result['total']);
  }

  /**
   * Inside one file, a repeated title and start or Event ID is a duplicate.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportKeysDuplicatesOnTitleStartAndEventId() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->eventImport->previewImport([
      ['title' => 'Weekly Talk', 'start' => '2026-03-04 14:00', 'event id' => '1'],
      ['title' => 'Weekly Talk', 'start' => '3/4/2026 2:00 PM'],
      ['title' => 'Weekly Talk', 'start' => '2026-03-11 14:00', 'event id' => '2'],
      ['title' => 'Weekly Talk (moved)', 'start' => '2026-03-12 14:00', 'event id' => '2'],
    ], TRUE);

    $this->assertCount(2, $result['valid_events']);
    $this->assertSame(['Weekly Talk', 'Weekly Talk (moved)'], $result['duplicates']);
  }

  /**
   * A URL Alias used by two rows is an error at preview, as at import.
   *
   * @covers ::previewImport
   */
  public function testPreviewImportReportsAliasRepeatedWithinTheFile() {
    $this->storages['node']->method('getQuery')->willReturn($this->mockQuery([]));

    $result = $this->eventImport->previewImport([
      ['title' => 'First', 'start' => '2026-03-04 14:00', 'url alias' => '/events/same'],
      ['title' => 'Second', 'start' => '2026-03-05 14:00', 'url alias' => 'events/same', '_row_number' => 3],
    ], TRUE);

    $this->assertCount(1, $result['valid_events']);
    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('Row 3: URL Alias "/events/same" is used by more than one row.', $this->text($result['errors'][0]));
  }

  /**
   * The plain text of a row error, which is escaped markup.
   */
  protected function text($message): string {
    return html_entity_decode((string) $message, ENT_QUOTES);
  }

  /**
   * Empty prepared values, for tests that only care about a few keys.
   */
  protected function emptyData(): array {
    return [
      'all_day' => FALSE,
      'description' => '',
      'description_has_media' => FALSE,
      'location_details' => '',
      'experience' => [],
      'event_website' => '',
      'event_website_text' => '',
      'ticket_cost' => '',
      'ticket_registration_url' => '',
      'stream_url' => '',
      'category' => [],
      'tags' => [],
      'audience' => [],
      'custom_vocab' => [],
      'teaser_title' => '',
      'teaser_text' => '',
      'login_required' => FALSE,
      'url_alias' => '',
      'event_id' => '',
    ];
  }

}
