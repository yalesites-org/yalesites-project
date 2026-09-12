<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
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

  use LayoutBuilderEntityContextTestTrait;

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

    $this->setUpLayoutBuilderEntityContextContainer();

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
    $definition = [
      'provider' => 'ys_layouts',
      'admin_label' => 'Event Meta Block',
      'context_definitions' => $this->layoutBuilderEntityContextDefinitions(EventMetaBlock::class),
    ];

    return new EventMetaBlock([], 'event_meta_block', $definition, $this->routeMatch, $this->metaFieldsManager);
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

  /**
   * Builds an event node mock.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function mockEventNode(string $title) {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $node->method('label')->willReturn($title);

    return $node;
  }

  /**
   * The entity being rendered beats a route that names no node at all.
   *
   * This is the reported bug: /node/add/event (every new event's first save)
   * and a bulk operation from /admin/content carry no node on the route, so
   * the block rendered nothing and the event's heading was missing from what
   * Beacon indexes.
   *
   * @covers ::build
   */
  public function testBuildUsesRenderedEntityWhenRouteHasNoNode(): void {
    $node = $this->mockEventNode('Fall Concert');
    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $this->metaFieldsManager->expects($this->once())
      ->method('getEventData')
      ->with($node)
      ->willReturn($this->eventFieldData);

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $node);

    $build = $block->build();

    $this->assertSame('ys_event_meta_block', $build['#theme']);
    $this->assertSame('Fall Concert', $build['#event_title__heading']);
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the route.
   *
   * @covers ::build
   */
  public function testBuildPrefersRenderedEntityOverRouteNode(): void {
    $node = $this->mockEventNode('Fall Concert');
    $this->routeMatch->method('getParameter')->with('node')->willReturn($this->mockEventNode('Some Other Event'));
    $this->metaFieldsManager->expects($this->once())
      ->method('getEventData')
      ->with($node)
      ->willReturn($this->eventFieldData);

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $node);

    $this->assertSame('Fall Concert', $block->build()['#event_title__heading']);
  }

  /**
   * A context holding something other than a node falls through to the route.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type.
   *
   * @covers ::build
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testBuildIgnoresNonNodeEntityContext($value): void {
    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $this->metaFieldsManager->expects($this->never())->method('getEventData');

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $value);

    $this->assertSame([], $block->build());
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(EventMetaBlock::class);
  }

  /**
   * The context-assignment select is kept off the editor's block form.
   *
   * @covers ::buildConfigurationForm
   */
  public function testConfigurationFormHasNoContextAssignmentSelect(): void {
    $this->assertNoContextAssignmentSelect($this->buildBlock());
  }

  /**
   * An unsaved sample entity does not stand in for real content.
   *
   * Layout Builder's Defaults layout screen offers a generated sample entity
   * that core never saves (LayoutBuilderSampleEntityGenerator::get() calls
   * createWithSampleValues() and stashes it in a tempstore). MetaFieldsManager
   * derives a canonical URL from the node, which cannot work without an ID.
   * Before this block declared a context it rendered nothing there.
   *
   * @covers ::build
   */
  public function testBuildIgnoresUnsavedSampleEntity(): void {
    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $this->metaFieldsManager->expects($this->never())->method('getEventData');

    $sample = $this->mockEventNode('Sample Event');
    $sample->method('isNew')->willReturn(TRUE);

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $sample);

    $this->assertSame([], $block->build());
  }

}
