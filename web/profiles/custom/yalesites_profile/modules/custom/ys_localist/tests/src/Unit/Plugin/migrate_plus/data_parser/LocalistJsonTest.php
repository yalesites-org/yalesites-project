<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\migrate_plus\data_parser;

use Drupal\Tests\UnitTestCase;
use Drupal\migrate_plus\DataFetcherPluginInterface;
use Drupal\migrate_plus\DataFetcherPluginManager;
use Drupal\ys_localist\Plugin\migrate_plus\data_parser\LocalistJson;

/**
 * Unit tests for the LocalistJson data parser plugin.
 *
 * The Localist API is never hit for real here: the data fetcher plugin is
 * mocked and driven with canned JSON, matching the paged shape of the real
 * Localist events endpoint (`{"page": {"total": N}, "events": [...]}`).
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\migrate_plus\data_parser\LocalistJson
 *
 * @group yalesites
 * @group ys_localist
 */
class LocalistJsonTest extends UnitTestCase {

  /**
   * The mocked data fetcher plugin.
   *
   * @var \Drupal\migrate_plus\DataFetcherPluginInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $dataFetcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dataFetcher = $this->createMock(DataFetcherPluginInterface::class);
  }

  /**
   * Builds the plugin under test and gives it access to getSourceData().
   *
   * @param string|int $item_selector
   *   The configured item selector.
   */
  protected function createParser($item_selector = 'events'): LocalistJson {
    $configuration = [
      'urls' => ['https://events.yale.edu/api/2/events'],
      'item_selector' => $item_selector,
      'data_fetcher_plugin' => 'http',
    ];

    $parser = $this->getMockBuilder(LocalistJson::class)
      ->setConstructorArgs([
        $configuration,
        'localist_json',
        [],
        $this->createMock(DataFetcherPluginManager::class),
      ])
      ->onlyMethods(['getDataFetcherPlugin'])
      ->getMock();
    $parser->method('getDataFetcherPlugin')->willReturn($this->dataFetcher);

    return $parser;
  }

  /**
   * Invokes the protected getSourceData() method via reflection.
   */
  protected function getSourceData(LocalistJson $parser, string $url, $item_selector = ''): array {
    $method = new \ReflectionMethod($parser, 'getSourceData');
    $method->setAccessible(TRUE);
    return $method->invoke($parser, $url, $item_selector);
  }

  /**
   * Instances aggregate across pages, keyed by their parent event id.
   *
   * Across a two-page fetch, event instances accumulate under their parent
   * event while "localist_data" ends up holding the last page's copy of the
   * event; an all-day event (no "end") gets a synthetic 23h59m duration.
   *
   * @covers ::getSourceData
   */
  public function testGetSourceDataAggregatesInstancesAcrossPagesByParentEventId() {
    $url = 'https://events.yale.edu/api/2/events';

    // The initial fetch (bare $url) is only used to read the page count;
    // its "events" content is discarded once the paging loop begins.
    $countCheck = '{"page": {"total": 2}, "events": []}';

    $page1 = json_encode([
      'events' => [
        [
          'event' => [
            'id' => 100,
            'title' => 'Event A',
            'event_instances' => [
              [
                'event_instance' => [
                  'id' => 1,
                  'event_id' => 100,
                  'start' => '2026-08-01T10:00:00-04:00',
                  'end' => '2026-08-01T11:00:00-04:00',
                ],
              ],
            ],
          ],
        ],
        [
          'event' => [
            'id' => 200,
            'title' => 'Event B (all day)',
            'event_instances' => [
              [
                'event_instance' => [
                  'id' => 2,
                  'event_id' => 200,
                  'start' => '2026-08-02T00:00:00-04:00',
                  'end' => NULL,
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    // Event 100 reappears on page 2 with updated metadata and a second
    // instance -- characterizes that instances accumulate across pages
    // while "localist_data" ends up holding the last page's copy seen.
    $page2 = json_encode([
      'events' => [
        [
          'event' => [
            'id' => 100,
            'title' => 'Event A (page 2 title)',
            'event_instances' => [
              [
                'event_instance' => [
                  'id' => 3,
                  'event_id' => 100,
                  'start' => '2026-09-01T10:00:00-04:00',
                  'end' => '2026-09-01T11:00:00-04:00',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    // Each page is fetched from the base URL with its own "&page=N" suffix.
    $this->dataFetcher->method('getResponseContent')->willReturnMap([
      [$url, $countCheck],
      ["$url&page=1", $page1],
      ["$url&page=2", $page2],
    ]);

    $parser = $this->createParser();
    $result = $this->getSourceData($parser, $url);

    $this->assertCount(2, $result);

    // Event 100: instances accumulated from both pages, metadata from the
    // last page processed.
    $this->assertSame('Event A (page 2 title)', $result[100]['localist_data']['title']);
    $this->assertCount(2, $result[100]['instances']);
    $this->assertSame('America/New_York', $result[100]['instances'][0]['timezone']);
    $this->assertSame(60, $result[100]['instances'][0]['duration']);
    $this->assertSame(60, $result[100]['instances'][1]['duration']);

    // Event 200: all-day event (no "end") gets a synthetic 23h59m duration.
    $instance = $result[200]['instances'][0];
    $this->assertSame(1439, $instance['duration']);
    $this->assertSame($instance['value'] + 86340, $instance['end_value']);
  }

  /**
   * Each page is fetched from the original base URL, not a compounded one.
   *
   * GetSourceData() appends "&page=N" to the base URL for each page rather
   * than reassigning $url in place, so page 2 is fetched from "...&page=2"
   * (not "...&page=1&page=2").
   *
   * @covers ::getSourceData
   */
  public function testGetSourceDataShouldFetchEachPageFromTheBaseUrl() {
    $url = 'https://events.yale.edu/api/2/events';
    $countCheck = '{"page": {"total": 2}, "events": []}';
    $emptyPage = '{"events": []}';

    $requested = [];
    $this->dataFetcher->method('getResponseContent')
      ->willReturnCallback(function ($requestedUrl) use ($url, $countCheck, $emptyPage, &$requested) {
        $requested[] = $requestedUrl;
        return $requestedUrl === $url ? $countCheck : $emptyPage;
      });

    $parser = $this->createParser();
    $this->getSourceData($parser, $url);

    $this->assertSame([$url, "$url&page=1", "$url&page=2"], $requested);
  }

  /**
   * @covers ::getSourceData
   */
  public function testGetSourceDataReturnsEmptyArrayWhenNoPages() {
    $url = 'https://events.yale.edu/api/2/events';
    $this->dataFetcher->method('getResponseContent')
      ->with($url)
      ->willReturn('{"page": {"total": 0}, "events": []}');

    $parser = $this->createParser();
    $result = $this->getSourceData($parser, $url);

    $this->assertSame([], $result);
  }

  /**
   * @covers ::getSourceData
   */
  public function testGetSourceDataUsesSelectByDepthForIntegerItemSelector() {
    $url = 'https://events.yale.edu/api/2/events';

    // With an integer item_selector, getSourceData() takes the
    // backwards-compatibility branch and returns via selectByDepth()
    // instead of the per-page event/instance aggregation.
    $this->dataFetcher->method('getResponseContent')->willReturnMap([
      [$url, '{"page": {"total": 1}, "events": []}'],
      ["$url&page=1", '{"events": [{"id": 1}]}'],
    ]);

    $parser = $this->createParser(0);
    $result = $this->getSourceData($parser, $url);

    // selectByDepth(0) returns every array found at depth 0 -- here, just
    // the top-level "events" list itself.
    $this->assertSame([[['id' => 1]]], $result);
  }

}
