<?php

namespace Drupal\Tests\ys_views_content_resources\Kernel\Plugin\Field\FieldType;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Kernel tests for the ViewsContentResourcesParams field type's isEmpty().
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\Field\FieldType\ViewsContentResourcesParams
 * @group ys_views_content_resources
 * @group yalesites
 */
class ViewsContentResourcesParamsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'views',
    'ys_views_basic',
    'path_alias',
    'ys_views_content_resources',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test');

    FieldStorageConfig::create([
      'field_name' => 'field_vcr_params',
      'entity_type' => 'entity_test',
      'type' => 'views_content_resources_params',
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_vcr_params',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Resource view params',
    ])->save();
  }

  /**
   * Creates an entity_test entity carrying the field.
   */
  protected function createTestEntity(array $values = []) {
    return \Drupal::entityTypeManager()->getStorage('entity_test')->create($values);
  }

  /**
   * IsEmpty() is TRUE when no params value has been set.
   *
   * @covers ::isEmpty
   */
  public function testIsEmptyWithNoValue() {
    $entity = $this->createTestEntity();
    $this->assertTrue($entity->field_vcr_params->isEmpty());
  }

  /**
   * IsEmpty() is FALSE once a non-empty params string is stored.
   *
   * @covers ::isEmpty
   */
  public function testIsEmptyWithStoredValue() {
    $entity = $this->createTestEntity([
      'field_vcr_params' => ['params' => json_encode(['view_mode' => 'card'])],
    ]);
    $this->assertFalse($entity->field_vcr_params->isEmpty());
  }

  /**
   * IsEmpty() is TRUE for an explicitly empty string value.
   *
   * @covers ::isEmpty
   */
  public function testIsEmptyWithEmptyStringValue() {
    $entity = $this->createTestEntity([
      'field_vcr_params' => ['params' => ''],
    ]);
    $this->assertTrue($entity->field_vcr_params->isEmpty());
  }

}
