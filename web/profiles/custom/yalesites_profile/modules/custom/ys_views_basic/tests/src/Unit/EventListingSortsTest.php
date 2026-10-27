<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the sorts an events listing is given.
 *
 * Events sharing a start date must come back in a fixed order (node ID
 * ascending), or the order changes between loads and a pager can repeat or
 * skip an event (yalesites-org/YaleSites-Internal#1969).
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class EventListingSortsTest extends UnitTestCase {

  /**
   * The event date sort in the given direction.
   */
  protected function dateSort(string $direction): array {
    return [
      'id' => 'field_event_date_value',
      'table' => 'node__field_event_date',
      'field' => 'field_event_date_value',
      'order' => $direction,
    ];
  }

  /**
   * The node ID tie-break that ends every event sort.
   */
  protected function nidTieBreak(): array {
    return [
      'id' => 'nid',
      'table' => 'node_field_data',
      'field' => 'nid',
      'order' => 'ASC',
    ];
  }

  /**
   * Both event sort directions end with node ID ascending.
   *
   * @covers ::eventSorts
   */
  public function testEventSortsEndWithNidAscending(): void {
    foreach (['ASC', 'DESC'] as $direction) {
      $this->assertSame(
        [$this->dateSort($direction), $this->nidTieBreak()],
        ViewsBasicManager::eventSorts($direction, FALSE),
        $direction,
      );
    }
  }

  /**
   * Pinned events still come first, and the tie-break stays last.
   *
   * @covers ::eventSorts
   */
  public function testPinnedEventSortsKeepStickyFirst(): void {
    $sticky = [
      'id' => 'sticky',
      'table' => 'node_field_data',
      'field' => 'sticky',
      'order' => 'desc',
    ];
    $this->assertSame(
      [$sticky, $this->dateSort('ASC'), $this->nidTieBreak()],
      ViewsBasicManager::eventSorts('ASC', TRUE),
    );
  }

}
