<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\search_api\processor\AiMetadataProperties;
use Drupal\ys_beacon\Service\AiMetadataManager;

/**
 * Tests that the processor only indexes AI metadata it actually has.
 *
 * This pins the contract the ai_description sanitisation in AiMetadataManager
 * relies on: an empty value must add no field value at all, so a media item
 * whose migrated description was suppressed takes exactly the same path as one
 * that never had a description. If this guard were ever relaxed to a NULL check
 * the suppressed empty string would start being indexed again, and every test
 * in AiMetadataManagerTest would still pass.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\search_api\processor\AiMetadataProperties
 */
class AiMetadataPropertiesTest extends UnitTestCase {

  /**
   * An empty description contributes no value to the index field.
   *
   * @covers ::addFieldValues
   * @covers ::addMetadataValue
   */
  public function testEmptyDescriptionAddsNoFieldValue(): void {
    $description = $this->createMock(FieldInterface::class);
    $description->method('getPropertyPath')->willReturn('ys_beacon_ai_description');
    $description->expects($this->never())->method('addValue');

    $tags = $this->createMock(FieldInterface::class);
    $tags->method('getPropertyPath')->willReturn('ys_beacon_ai_tags');
    $tags->expects($this->once())->method('addValue')->with('supplier, gateway');

    $this->runProcessor(['ai_description' => '', 'ai_tags' => 'supplier, gateway'], [$description, $tags]);
  }

  /**
   * A real description is added to the index field.
   *
   * @covers ::addFieldValues
   * @covers ::addMetadataValue
   */
  public function testRealDescriptionIsAddedToTheField(): void {
    $description = $this->createMock(FieldInterface::class);
    $description->method('getPropertyPath')->willReturn('ys_beacon_ai_description');
    $description->expects($this->once())->method('addValue')->with('Reference guide for suppliers.');

    $tags = $this->createMock(FieldInterface::class);
    $tags->method('getPropertyPath')->willReturn('ys_beacon_ai_tags');
    $tags->expects($this->never())->method('addValue');

    $this->runProcessor(['ai_description' => 'Reference guide for suppliers.', 'ai_tags' => ''], [$description, $tags]);
  }

  /**
   * Runs addFieldValues() with a stubbed metadata manager and index fields.
   *
   * @param array $metadata
   *   The values AiMetadataManager::getAiMetadata() should return.
   * @param \Drupal\search_api\Item\FieldInterface[] $fields
   *   The index fields present on the item.
   */
  private function runProcessor(array $metadata, array $fields): void {
    $entity = $this->createMock(ContentEntityInterface::class);

    $original = $this->createMock(ComplexDataInterface::class);
    $original->method('getValue')->willReturn($entity);

    $item = $this->createMock(ItemInterface::class);
    $item->method('getOriginalObject')->willReturn($original);
    $item->method('getFields')->with(FALSE)->willReturn($fields);

    $manager = $this->createMock(AiMetadataManager::class);
    $manager->method('getAiMetadata')->with($entity)->willReturn($metadata);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('filterForPropertyPath')
      ->willReturnCallback(fn (array $candidates, $datasource_id, $property_path) => array_filter(
        $candidates,
        fn (FieldInterface $field) => $field->getPropertyPath() === $property_path
      ));

    $processor = new AiMetadataProperties([], 'ys_beacon_ai_metadata', []);
    $processor->setFieldsHelper($fieldsHelper);

    $injected = new \ReflectionProperty($processor, 'aiMetadataManager');
    $injected->setAccessible(TRUE);
    $injected->setValue($processor, $manager);

    $processor->addFieldValues($item);
  }

}
