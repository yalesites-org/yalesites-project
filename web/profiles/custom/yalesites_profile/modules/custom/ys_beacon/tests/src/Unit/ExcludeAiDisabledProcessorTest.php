<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\search_api\processor\ExcludeAiDisabled;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Proves the Search API processor drops non-indexable items before indexing.
 *
 * ExcludeAiDisabled is the write-path gate: Search API collects tracked items
 * and this processor removes any whose entity fails BeaconIndexability, so a
 * protected or AI-disabled entity is never embedded into the shared vector
 * database. This isolates the processor's own drop logic; the indexability
 * decision itself is covered by BeaconIndexabilityAccessTest and
 * BeaconIndexabilityTest.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\search_api\processor\ExcludeAiDisabled
 */
class ExcludeAiDisabledProcessorTest extends UnitTestCase {

  /**
   * Non-indexable items are removed; indexable items are kept.
   *
   * @covers ::alterIndexedItems
   */
  public function testNonIndexableItemsAreDropped(): void {
    $indexable = $this->createMock(EntityInterface::class);
    $notIndexable = $this->createMock(EntityInterface::class);

    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexable')
      ->willReturnCallback(fn ($entity) => $entity === $indexable);

    $items = [
      'keep' => $this->item($indexable),
      'drop' => $this->item($notIndexable),
    ];

    $this->processor($indexability)->alterIndexedItems($items);

    $this->assertArrayHasKey('keep', $items, 'The indexable item survives.');
    $this->assertArrayNotHasKey('drop', $items, 'The non-indexable item is removed before it reaches the backend.');
  }

  /**
   * An item with no original entity is left untouched (not an entity to gate).
   *
   * @covers ::alterIndexedItems
   */
  public function testItemWithoutEntityIsKept(): void {
    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->expects($this->never())->method('isIndexable');

    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn(NULL);
    $items = ['orphan' => $item];

    $this->processor($indexability)->alterIndexedItems($items);

    $this->assertArrayHasKey('orphan', $items);
  }

  /**
   * Wraps an entity in a Search API item, as the processor receives it.
   */
  private function item(EntityInterface $entity): ItemInterface {
    $original = $this->createMock(ComplexDataInterface::class);
    $original->method('getValue')->willReturn($entity);
    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($original);
    return $item;
  }

  /**
   * Builds the processor with the indexability service injected.
   */
  private function processor(BeaconIndexability $indexability): ExcludeAiDisabled {
    $processor = (new \ReflectionClass(ExcludeAiDisabled::class))->newInstanceWithoutConstructor();
    $property = new \ReflectionProperty(ExcludeAiDisabled::class, 'indexability');
    $property->setValue($processor, $indexability);
    return $processor;
  }

}
