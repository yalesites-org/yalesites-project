<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_localist\LocalistManager;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Unit tests for the event location shown in the event meta block.
 *
 * @coversDefaultClass \Drupal\ys_localist\MetaFieldsManager
 *
 * @group yalesites
 * @group ys_localist
 */
class MetaFieldsManagerLocationTest extends UnitTestCase {

  /**
   * Builds an event node mock with the given field values.
   */
  protected function event(?string $localistId, ?string $room, ?array $details): NodeInterface {
    $values = [
      'field_localist_id' => $localistId,
      'field_event_room' => $room,
      'field_event_location_details' => $details,
    ];
    $lists = [];
    foreach ($values as $name => $value) {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn($value === NULL);
      $list->method('getString')->willReturn(is_string($value) ? $value : '');
      $item = NULL;
      if (is_array($value)) {
        $item = $this->createMock(FieldItemInterface::class);
        $item->method('getValue')->willReturn($value);
      }
      $list->method('first')->willReturn($item);
      $lists[$name] = $list;
    }
    $node = $this->createMock(NodeInterface::class);
    $node->method('get')->willReturnCallback(fn($name) => $lists[$name]);
    return $node;
  }

  /**
   * Calls getLocation() on a manager with mocked dependencies.
   */
  protected function location(NodeInterface $node): array {
    $manager = new MetaFieldsManager(
      $this->createMock(DateFormatter::class),
      $this->createMock(EntityTypeManager::class),
      $this->createMock(LocalistManager::class),
    );
    return $manager->getLocation($node);
  }

  /**
   * @covers ::getLocation
   */
  public function testRoomShowsOnlyOnLocalistEvents(): void {
    $this->assertSame('Room 5', $this->location($this->event('123', 'Room 5', NULL))['room']);
    $this->assertNull($this->location($this->event(NULL, 'Room 5', NULL))['room']);
    $this->assertNull($this->location($this->event('123', '', NULL))['room']);
  }

  /**
   * @covers ::getLocation
   */
  public function testLocationDetailsRenderThroughTheirTextFormat(): void {
    $details = ['value' => '<p>Enter by the side door.</p>', 'format' => 'restricted_html'];
    foreach (['123', NULL] as $localistId) {
      $this->assertSame([
        '#type' => 'processed_text',
        '#text' => '<p>Enter by the side door.</p>',
        '#format' => 'restricted_html',
      ], $this->location($this->event($localistId, NULL, $details))['location_details']);
    }
  }

  /**
   * @covers ::getLocation
   */
  public function testBlankLocationDetailsAreOmitted(): void {
    $this->assertNull($this->location($this->event(NULL, NULL, NULL))['location_details']);
    $blank = ['value' => "  \n", 'format' => 'restricted_html'];
    $this->assertNull($this->location($this->event(NULL, NULL, $blank))['location_details']);
  }

}
