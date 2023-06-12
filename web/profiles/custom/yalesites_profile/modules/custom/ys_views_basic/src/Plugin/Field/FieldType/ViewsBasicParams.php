<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface as StorageDefinition;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'views_basic_params' field type.
 *
 * @FieldType(
 *   id = "views_basic_params",
 *   label = @Translation("Views Basic Params"),
 *   description = @Translation("Stores parameters to pass to Views"),
 *   category = @Translation("Custom"),
 *   default_widget = "views_basic_default_widget",
 *   default_formatter = "views_basic_default_formatter"
 * )
 */
class ViewsBasicParams extends FieldItemBase {

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

    $isEmpty =
      empty($this->get('params')->getValue());

    return $isEmpty;
  }

}
