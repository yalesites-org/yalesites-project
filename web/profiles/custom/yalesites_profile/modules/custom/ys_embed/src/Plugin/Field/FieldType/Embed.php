<?php

namespace Drupal\ys_embed\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'embed' field type.
 *
 * This field type is useful for storing embed codes and related metadata. The
 * field defines an overloaded database table so that future embed types have a
 * space for storing a variety of values. Not all services will require all of
 * the field values, as many may only need a URL.
 *
 * @todo Add field settings and form to limit field to specific providers.
 *
 * @FieldType(
 *   id = "embed",
 *   label = @Translation("Embed"),
 *   description = @Translation("Embed codes and metadata."),
 *   default_widget = "embed_default",
 *   default_formatter = "embed"
 * )
 */
class Embed extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Field stores raw embed code from user input.
    $properties['embed_code'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Embed Code'))
      ->setRequired(TRUE);
    $properties['provider'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider ID'))
      ->setComputed(TRUE);
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'));
    $properties['params'] = DataDefinition::create('any')
      ->setLabel(new TranslatableMarkup('Parameters'));
    return $properties;
  }

  /**
   * Implements hook_field_schema().
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'embed_code' => [
          'default' => '',
          'description' => 'The source embed code for the embedded element.',
          'not null' => FALSE,
          'size' => 'big',
          'type' => 'text',
        ],
        'provider' => [
          'default' => '',
          'description' => 'The provider id matching the user input',
          'length' => 255,
          'not null' => FALSE,
          'sortable' => TRUE,
          'type' => 'varchar',
        ],
        'title' => [
          'default' => '',
          'description' => 'The title attribute for the rendered element.',
          'length' => 255,
          'not null' => FALSE,
          'sortable' => TRUE,
          'type' => 'varchar',
        ],
        'params' => [
          'default' => '',
          'description' => 'Additional metadata and settings.',
          'not null' => FALSE,
          'serialize' => TRUE,
          'size' => 'big',
          'type' => 'text',
        ],
      ],
      'indexes' => [
        'embed_code' => ['embed_code'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $code = $this->get('embed_code')->getValue();
    return $code === NULL || $code === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'embed_code';
  }

}
