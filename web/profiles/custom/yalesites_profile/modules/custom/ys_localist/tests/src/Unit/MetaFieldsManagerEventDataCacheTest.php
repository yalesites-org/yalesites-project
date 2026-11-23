<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_localist\LocalistManager;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Unit tests for per-request caching of event data.
 *
 * @coversDefaultClass \Drupal\ys_localist\MetaFieldsManager
 *
 * @group yalesites
 * @group ys_localist
 */
class MetaFieldsManagerEventDataCacheTest extends UnitTestCase {

  /**
   * Builds a manager whose buildEventData() is counted.
   */
  protected function manager(int $expectedBuilds): MetaFieldsManager {
    $manager = $this->getMockBuilder(MetaFieldsManager::class)
      ->setConstructorArgs([
        $this->createMock(DateFormatter::class),
        $this->createMock(EntityTypeManager::class),
        $this->createMock(LocalistManager::class),
      ])
      ->onlyMethods(['buildEventData'])
      ->getMock();
    $manager->expects($this->exactly($expectedBuilds))
      ->method('buildEventData')
      ->willReturn(['title' => 'Event']);
    return $manager;
  }

  /**
   * Builds a node mock with the given ID.
   */
  protected function node(?int $nid): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    return $node;
  }

  /**
   * @covers ::getEventData
   */
  public function testSecondCallForSameNodeBuildsOnce(): void {
    $manager = $this->manager(1);
    $node = $this->node(5);
    $this->assertSame(['title' => 'Event'], $manager->getEventData($node));
    $this->assertSame(['title' => 'Event'], $manager->getEventData($node));
  }

  /**
   * @covers ::getEventData
   */
  public function testDifferentNodesBuildSeparately(): void {
    $manager = $this->manager(2);
    $manager->getEventData($this->node(5));
    $manager->getEventData($this->node(6));
  }

  /**
   * @covers ::getEventData
   */
  public function testUnsavedNodeIsNotCached(): void {
    $manager = $this->manager(2);
    $node = $this->node(NULL);
    $manager->getEventData($node);
    $manager->getEventData($node);
  }

}
