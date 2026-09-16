<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\EventMetaBlock;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Tests the event meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\EventMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class EventMetaBlockTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The meta fields manager mock.
   *
   * @var \Drupal\ys_localist\MetaFieldsManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $metaFieldsManager;

  /**
   * Canned event field data returned by the meta fields manager mock.
   *
   * @var array
   */
  protected $eventFieldData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->metaFieldsManager = $this->createMock(MetaFieldsManager::class);

    $this->eventFieldData = [
      'title' => 'Fall Concert',
      'dates' => ['formatted_start_date' => 'Friday, May 3rd, 2024'],
      'ics' => 'https://example.com/event.ics',
      'canonical_url' => '/events/fall-concert',
      'ticket_url' => NULL,
      'ticket_cost' => NULL,
      'place_info' => [],
      'event_types' => [],
      'event_audience' => [],
      'event_topics' => [],
      'description' => 'A description.',
      'room' => NULL,
      'external_website_url' => NULL,
      'external_website_title' => NULL,
      'experience' => [],
      'localist_image_url' => NULL,
      'localist_image_alt' => NULL,
      'teaser_media' => [],
      'has_register' => FALSE,
      'cost_button_text' => 'Register',
      'localist_url' => NULL,
      'stream_url' => NULL,
      'stream_embed_code' => NULL,
      'event_source' => '',
      'event_featured_date' => NULL,
      'event_featured_index' => 0,
    ];
  }

  /**
   * Builds the block plugin under test.
   *
   * @return \Drupal\ys_layouts\Plugin\Block\EventMetaBlock
   *   The block plugin.
   */
  protected function buildBlock(): EventMetaBlock {
    return new EventMetaBlock([], 'event_meta_block', ['provider' => 'ys_layouts'], $this->routeMatch, $this->metaFieldsManager);
  }

  /**
   * With no node on the route, the block renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWithNoNode(): void {
    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $this->metaFieldsManager->expects($this->never())->method('getEventData');

    $build = $this->buildBlock()->build();

    $this->assertSame([], $build);
  }

  /**
   * An event node is rendered using the meta fields manager's event data.
   *
   * @covers ::build
   */
  public function testBuildRendersEventDataForEventNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);
    $this->metaFieldsManager->method('getEventData')->with($node)->willReturn($this->eventFieldData);

    $build = $this->buildBlock()->build();

    $this->assertSame('ys_event_meta_block', $build['#theme']);
    $this->assertSame('Fall Concert', $build['#event_title__heading']);
    $this->assertSame('/events/fall-concert', $build['#canonical_url']);
    $this->assertSame('https://example.com/event.ics', $build['#ics_url']);
  }

  /**
   * A non-event node should render nothing.
   *
   * @covers ::build
   */
  public function testBuildShouldReturnEmptyForNonEventNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);
    $this->metaFieldsManager->expects($this->never())->method('getEventData');

    $build = $this->buildBlock()->build();

    $this->assertSame([], $build);
  }

}
