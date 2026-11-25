<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_localist\EventStructuredData;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Unit tests for the schema.org Event mapping.
 *
 * @coversDefaultClass \Drupal\ys_localist\EventStructuredData
 *
 * @group yalesites
 * @group ys_localist
 */
class EventStructuredDataTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected EventStructuredData $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->service = new EventStructuredData(
      $this->createMock(MetaFieldsManager::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );
  }

  /**
   * Builds getEventData()-shaped input.
   */
  protected function data(array $overrides = []): array {
    return $overrides + [
      'title' => 'Lecture',
      'canonical_url' => 'https://example.yale.edu/events/lecture',
      'event_featured_date' => [
        // 2026-12-01 14:00 UTC = 09:00 EST.
        'value' => 1796133600,
        'end_value' => 1796137200,
        'timezone' => 'America/New_York',
        'is_all_day' => FALSE,
      ],
      'description' => '<p>Hello&nbsp;<b>world</b></p><p>&nbsp;</p>',
      'experience' => [],
      'place_info' => [],
      'room' => NULL,
      'location_details' => NULL,
      'stream_url' => NULL,
      'ticket_url' => NULL,
      'ticket_cost' => NULL,
      'localist_image_url' => NULL,
    ];
  }

  /**
   * An in-person event with a Yale Location.
   *
   * @covers ::build
   */
  public function testInPerson(): void {
    $event = $this->service->build($this->data([
      'place_info' => [
        'name' => 'Sterling Library',
        'address' => '120 High St',
        'city' => 'New Haven',
        'state' => 'CT',
        'postal_code' => '06511',
        'country_code' => 'US',
        'latitude' => '41.3',
        'longitude' => '-72.9',
      ],
      'room' => 'Room 2',
    ]), ['format' => ['inperson'], 'image_url' => 'https://example.yale.edu/i.jpg']);

    $this->assertSame('Event', $event['@type']);
    $this->assertSame('2026-12-01T09:00:00-05:00', $event['startDate']);
    $this->assertSame('2026-12-01T10:00:00-05:00', $event['endDate']);
    $this->assertSame('Hello world', $event['description']);
    $this->assertSame('https://example.yale.edu/i.jpg', $event['image']);
    $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $event['eventAttendanceMode']);
    $this->assertSame('https://schema.org/EventScheduled', $event['eventStatus']);
    $this->assertSame('Sterling Library, Room 2', $event['location']['name']);
    $this->assertSame('PostalAddress', $event['location']['address']['@type']);
    $this->assertSame(41.3, $event['location']['geo']['latitude']);
    $this->assertArrayNotHasKey('offers', $event);
  }

  /**
   * A virtual event gets a VirtualLocation and the online mode.
   *
   * @covers ::build
   */
  public function testVirtual(): void {
    $event = $this->service->build($this->data(['stream_url' => 'https://zoom.example/1']), ['format' => ['online']]);
    $this->assertSame('VirtualLocation', $event['location']['@type']);
    $this->assertSame('https://schema.org/OnlineEventAttendanceMode', $event['eventAttendanceMode']);
  }

  /**
   * A hybrid event (Localist experience) gets both locations.
   *
   * @covers ::build
   */
  public function testHybrid(): void {
    $event = $this->service->build($this->data([
      'stream_url' => 'https://zoom.example/1',
      'place_info' => ['name' => 'Hall'],
      'experience' => [['name' => 'Hybrid', 'url' => '']],
    ]));
    $this->assertSame('https://schema.org/MixedEventAttendanceMode', $event['eventAttendanceMode']);
    $this->assertCount(2, $event['location']);
    $this->assertSame('Place', $event['location'][0]['@type']);
    $this->assertSame('VirtualLocation', $event['location'][1]['@type']);
  }

  /**
   * With no format or experience, the mode comes from the locations.
   *
   * @covers ::build
   */
  public function testAttendanceModeInferred(): void {
    $event = $this->service->build($this->data([
      'stream_url' => 'https://zoom.example/1',
      'place_info' => ['name' => 'Hall'],
    ]));
    $this->assertSame('https://schema.org/MixedEventAttendanceMode', $event['eventAttendanceMode']);
  }

  /**
   * Location details alone become a Place with a text address.
   *
   * @covers ::build
   */
  public function testLocationDetailsOnly(): void {
    $event = $this->service->build($this->data([
      'location_details' => ['#text' => '<p>Meet at the <b>quad</b></p>'],
    ]));
    $this->assertSame(['@type' => 'Place', 'address' => 'Meet at the quad'], $event['location']);
  }

  /**
   * No location at all leaves location out.
   *
   * @covers ::build
   */
  public function testNoLocation(): void {
    $this->assertArrayNotHasKey('location', $this->service->build($this->data()));
  }

  /**
   * Status names map to schema.org statuses, ignoring case.
   *
   * @covers ::build
   * @dataProvider statusProvider
   */
  public function testStatus(string $name, string $expected): void {
    $event = $this->service->build($this->data(), ['status' => $name]);
    $this->assertSame('https://schema.org/' . $expected, $event['eventStatus']);
  }

  /**
   * Data provider for testStatus().
   */
  public static function statusProvider(): array {
    return [
      'canceled' => ['Canceled', 'EventCancelled'],
      'lowercase' => ['canceled', 'EventCancelled'],
      'postponed' => ['POSTPONED', 'EventPostponed'],
      'other' => ['Sold out', 'EventScheduled'],
      'empty' => ['', 'EventScheduled'],
    ];
  }

  /**
   * All-day events use the date only.
   *
   * @covers ::build
   */
  public function testAllDay(): void {
    $data = $this->data();
    // 2026-12-01 00:00 to 23:59 EST.
    $data['event_featured_date'] = [
      'value' => 1796101200,
      'end_value' => 1796187540,
      'timezone' => 'America/New_York',
      'is_all_day' => TRUE,
    ];
    $event = $this->service->build($data);
    $this->assertSame('2026-12-01', $event['startDate']);
    $this->assertSame('2026-12-01', $event['endDate']);
  }

  /**
   * Only the featured occurrence is output, never a schedule.
   *
   * @covers ::build
   */
  public function testRecurringOutputsFeaturedOnly(): void {
    $data = $this->data();
    $data['dates'] = [['value' => 1, 'end_value' => 2], $data['event_featured_date']];
    $event = $this->service->build($data);
    $this->assertSame('2026-12-01T09:00:00-05:00', $event['startDate']);
    $this->assertArrayNotHasKey('eventSchedule', $event);
    $this->assertArrayNotHasKey('subEvent', $event);
  }

  /**
   * An event with no date outputs nothing.
   *
   * @covers ::build
   */
  public function testNoDate(): void {
    $this->assertNull($this->service->build(['title' => 'x', 'event_featured_date' => NULL]));
  }

  /**
   * Offers need a URL; price only when numeric or Free; sold out flag.
   *
   * @covers ::build
   */
  public function testOffers(): void {
    $event = $this->service->build($this->data(['ticket_url' => 'https://t.example', 'ticket_cost' => '25']), ['status' => 'Sold out']);
    $this->assertSame(25, $event['offers']['price']);
    $this->assertSame('https://schema.org/SoldOut', $event['offers']['availability']);

    $free = $this->service->build($this->data(['ticket_url' => 'https://t.example', 'ticket_cost' => 'Free']));
    $this->assertSame(0, $free['offers']['price']);

    $text = $this->service->build($this->data(['ticket_url' => 'https://t.example', 'ticket_cost' => 'Sliding scale']));
    $this->assertArrayNotHasKey('price', $text['offers']);

    $none = $this->service->build($this->data(['ticket_cost' => '25']));
    $this->assertArrayNotHasKey('offers', $none);
  }

  /**
   * Description falls back to the teaser text.
   *
   * @covers ::build
   */
  public function testDescriptionFallback(): void {
    $event = $this->service->build($this->data(['description' => '']), ['teaser_text' => 'Short teaser']);
    $this->assertSame('Short teaser', $event['description']);
  }

  /**
   * Max age runs to the end of the featured date, or is permanent once past.
   *
   * @covers ::getMaxAge
   */
  public function testMaxAge(): void {
    $data = $this->data();
    $this->assertSame(3600, $this->service->getMaxAge($data, 1796133600));
    $this->assertSame(Cache::PERMANENT, $this->service->getMaxAge($data, 1796137201));
  }

}
