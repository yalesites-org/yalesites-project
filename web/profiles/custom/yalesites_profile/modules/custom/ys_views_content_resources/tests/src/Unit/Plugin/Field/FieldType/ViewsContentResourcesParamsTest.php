<?php

namespace Drupal\Tests\ys_views_content_resources\Unit\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_content_resources\Plugin\Field\FieldType\ViewsContentResourcesParams;

/**
 * Unit tests for the ViewsContentResourcesParams field type's static methods.
 *
 * IsEmpty() needs a real typed-data property bag to exercise meaningfully;
 * see the Kernel test for that method.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\Field\FieldType\ViewsContentResourcesParams
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesParamsTest extends UnitTestCase {

  /**
   * PropertyDefinitions() declares a single string 'params' property.
   *
   * @covers ::propertyDefinitions
   */
  public function testPropertyDefinitionsDeclaresParamsProperty() {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $properties = ViewsContentResourcesParams::propertyDefinitions($storage);

    $this->assertSame(['params'], array_keys($properties));
    $this->assertSame('string', $properties['params']->getDataType());
  }

  /**
   * Schema() declares a single big blob 'params' column with no indexes.
   *
   * @covers ::schema
   */
  public function testSchemaDeclaresBlobColumn() {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $schema = ViewsContentResourcesParams::schema($storage);

    $this->assertArrayHasKey('params', $schema['columns']);
    $this->assertSame('blob', $schema['columns']['params']['type']);
    $this->assertSame('big', $schema['columns']['params']['size']);
    $this->assertTrue($schema['columns']['params']['serialize']);
    $this->assertFalse($schema['columns']['params']['not null']);
    $this->assertSame([], $schema['indexes']);
  }

}
