<?php

namespace Drupal\ys_core\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface as StorageDefinition;

/**
 * Plugin implementation of the 'alt_text_override' field type.
 *
 * @FieldType(
 *   id = "alt_text_override",
 *   label = @Translation("Alt Text Override"),
 *   description = @Translation("Overrides Media entity alt text"),
 *   category = @Translation("Custom"),
 *   default_widget = "alt_text_override_default_widget",
 *   default_formatter = "alt_text_override_default_formatter"
 * )
 */
class AltTextOverride extends FieldItemBase {

  /**
   * Field type properties definition.
   */
  public static function propertyDefinitions(StorageDefinition $storage) {

    $properties = [];

    $properties['decorative'] = DataDefinition::create('integer')
      ->setLabel(t('Decorative'));
    $properties['value'] = DataDefinition::create('string')
      ->setLabel('Alt Text Override');

    return $properties;
  }

  /**
   * Field type schema definition.
   */
  public static function schema(StorageDefinition $storage) {

    $columns = [];
    $columns['decorative'] = [
      'not null' => FALSE,
      'type' => 'int',
      'length' => 1,
    ];

    $columns['value'] = [
      'not null' => FALSE,
      'type' => 'varchar',
      'length' => 255,
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
      empty($this->get('value')->getValue())
        && empty($this->get('decorative')->getValue());

    return $isEmpty;
  }

}
