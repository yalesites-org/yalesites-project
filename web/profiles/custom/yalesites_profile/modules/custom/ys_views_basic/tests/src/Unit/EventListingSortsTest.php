<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the sorts an events listing is given.
 *
 * Events sharing a start date list by title A to Z, then node ID ascending,
 * in both date directions. Without a fixed order the listing changes between
 * loads and a pager can repeat or skip an event
 * (yalesites-org/YaleSites-Internal#1969).
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
   * The title tie-break that follows the event date.
   */
  protected function titleTieBreak(): array {
    return [
      'id' => 'title',
      'table' => 'node_field_data',
      'field' => 'title',
      'order' => 'ASC',
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
   * Both event sort directions end with title, then node ID, ascending.
   *
   * @covers ::eventSorts
   */
  public function testEventSortsEndWithTitleThenNidAscending(): void {
    foreach (['ASC', 'DESC'] as $direction) {
      $this->assertSame(
        [$this->dateSort($direction), $this->titleTieBreak(), $this->nidTieBreak()],
        ViewsBasicManager::eventSorts($direction, FALSE),
        $direction,
      );
    }
  }

  /**
   * Pinned events still come first, and the tie-breaks stay last.
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
      [$sticky, $this->dateSort('ASC'), $this->titleTieBreak(), $this->nidTieBreak()],
      ViewsBasicManager::eventSorts('ASC', TRUE),
    );
  }

}
