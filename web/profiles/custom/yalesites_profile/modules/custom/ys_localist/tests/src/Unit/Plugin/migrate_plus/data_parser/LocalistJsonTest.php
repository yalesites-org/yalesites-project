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
   * Current behavior: each page URL compounds every prior page's suffix.
   *
   * GetSourceData() reassigns its $url parameter in place each iteration of
   * the paging loop ("$url = "$url&page=$i";") instead of appending to the
   * original base URL. As a result, the URL fetched for page 2 is
   * "...&page=1&page=2" rather than "...&page=2", and it keeps growing with
   * every additional page. This still works against Localist's API today
   * (duplicate query keys resolve to the last one), but produces
   * ever-longer, redundant URLs and is fragile if that API behavior ever
   * changes. Paired with testGetSourceDataShouldFetchEachPageFromTheBaseUrl()
   * -- delete once the GAP is fixed.
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

    // getSourceData() reassigns $url in place each iteration ("$url =
    // "$url&page=$i") instead of appending to the original base URL, so the
    // fetched URL compounds every prior page suffix -- see
    // testGetSourceDataShouldFetchEachPageFromTheBaseUrl() below.
    $this->dataFetcher->method('getResponseContent')->willReturnMap([
      [$url, $countCheck],
      ["$url&page=1", $page1],
      ["$url&page=1&page=2", $page2],
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
   * GAP test: each page should be fetched from the original base URL.
   */
  public function testGetSourceDataShouldFetchEachPageFromTheBaseUrl() {
    $this->markTestSkipped('GAP: LocalistJson::getSourceData() reassigns $url in place each paging iteration ("$url = "$url&page=$i";"), so the URL fetched for page N compounds every prior page\'s "&page=" suffix instead of appending to the original base URL -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_localist.md');
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
