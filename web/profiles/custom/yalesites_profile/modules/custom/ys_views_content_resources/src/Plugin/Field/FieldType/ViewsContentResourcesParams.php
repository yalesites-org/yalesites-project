<?php

namespace Drupal\ys_views_content_resources\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface as StorageDefinition;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'views_content_resources_params' field type.
 *
 * @FieldType(
 *   id = "views_content_resources_params",
 *   label = @Translation("Views Content Resources Params"),
 *   description = @Translation("Stores parameters to pass to Views"),
 *   category = @Translation("Custom"),
 *   default_widget = "views_content_resources_default_widget",
 *   default_formatter = "views_content_resources_default_formatter",
 *   cardinality = 1,
 * )
 */
class ViewsContentResourcesParams extends FieldItemBase {

  /**
   * Field type properties definition.
   */
  public static function propertyDefinitions(StorageDefinition $storage) {
    $properties = [];

    $properties['params'] = DataDefinition::create('string')
      ->setLabel(t('Parameters'));

    return $properties;
  }

  /**
   * Field type schema definition.
   */
  public static function schema(StorageDefinition $storage) {
    $columns = [];
    $columns['params'] = [
      'default' => '',
      'description' => 'Parameter data to be sent to Views',
      'not null' => FALSE,
      'serialize' => TRUE,
      'size' => 'big',
      'type' => 'blob',
    ];

    return [
      'columns' => $columns,
      'indexes' => [],
    ];
  }

  /**
   * Define when the field type is empty.
   */
  public function isEmpty() {
    $isEmpty = empty($this->get('params')->getValue());

    return $isEmpty;
  }

}
