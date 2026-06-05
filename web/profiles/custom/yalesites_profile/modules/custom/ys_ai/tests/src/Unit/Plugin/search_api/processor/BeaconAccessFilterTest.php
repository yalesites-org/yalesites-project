<?php

namespace Drupal\Tests\ys_ai\Unit\Plugin\search_api\processor;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Plugin\search_api\processor\BeaconAccessFilter;

/**
 * @coversDefaultClass \Drupal\ys_ai\Plugin\search_api\processor\BeaconAccessFilter
 *
 * @group yalesites
 */
class BeaconAccessFilterTest extends UnitTestCase {

  /**
   * A public, published, non-excluded node stays in the index.
   *
   * @covers ::alterIndexedItems
   */
  public function testKeepsPublicViewableNode(): void {
    $items = [
      'node/1' => $this->item($this->node(TRUE, FALSE)),
    ];
    $this->processor()->alterIndexedItems($items);
    $this->assertArrayHasKey('node/1', $items);
  }

  /**
   * A node an anonymous user cannot view is removed.
   *
   * Covers unpublished and CAS-protected content, since both are denied to
   * anonymous users by the ys_node_access grants.
   *
   * @covers ::alterIndexedItems
   */
  public function testExcludesNodeAnonymousCannotView(): void {
    $items = [
      'node/1' => $this->item($this->node(FALSE, FALSE)),
    ];
    $this->processor()->alterIndexedItems($items);
    $this->assertArrayNotHasKey('node/1', $items);
  }

  /**
   * A node flagged to be excluded from AI indexing is removed.
   *
   * @covers ::alterIndexedItems
   */
  public function testExcludesAiFlaggedNode(): void {
    $items = [
      'node/1' => $this->item($this->node(TRUE, TRUE)),
    ];
    $this->processor()->alterIndexedItems($items);
    $this->assertArrayNotHasKey('node/1', $items);
  }

  /**
   * Items whose original object is not a node are left untouched.
   *
   * @covers ::alterIndexedItems
   */
  public function testIgnoresNonNodeItems(): void {
    $complex = $this->createMock(ComplexDataInterface::class);
    $complex->method('getValue')->willReturn(new \stdClass());
    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($complex);

    $items = ['path_alias/1' => $item];
    $this->processor()->alterIndexedItems($items);
    $this->assertArrayHasKey('path_alias/1', $items);
  }

  /**
   * Builds the processor under test.
   */
  protected function processor(): BeaconAccessFilter {
    return new BeaconAccessFilter([], 'ys_beacon_access_filter', []);
  }

  /**
   * Wraps a node in a mocked Search API item.
   */
  protected function item(NodeInterface $node): ItemInterface {
    $complex = $this->createMock(ComplexDataInterface::class);
    $complex->method('getValue')->willReturn($node);
    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($complex);
    return $item;
  }

  /**
   * Builds a node mock with the given anonymous view access and exclude flag.
   *
   * @param bool $anonymous_access
   *   What $node->access('view', $anonymous) returns.
   * @param bool $excluded
   *   The value of the field_ai_exclude field.
   */
  protected function node(bool $anonymous_access, bool $excluded): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->willReturn($anonymous_access);
    $node->method('hasField')->willReturn(TRUE);

    $field = new class($excluded) {
      /**
       * The field's primitive value, as read via $node->get(...)->value.
       *
       * @var bool
       */
      public $value;

      public function __construct(bool $value) {
        $this->value = $value;
      }

    };
    $node->method('get')->willReturn($field);

    return $node;
  }

}
