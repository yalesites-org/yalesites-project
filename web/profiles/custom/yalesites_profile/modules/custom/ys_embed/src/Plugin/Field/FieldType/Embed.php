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
 * space for storing a variety of values. Some embed sources only require a URL
 * while others require a series of parameters.
 *
 * @FieldType(
 *   id = "embed",
 *   label = @Translation("Embed"),
 *   description = @Translation("Embed codes and metadata."),
 *   default_widget = "embed_default",
 *   default_formatter = "embed_formatter"
 * )
 */
class Embed extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // Field stores raw embed code from user input.
    $properties['input'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Embed Code or URL input'))
      ->setRequired(TRUE);
    // Provider reffers to the matching EmbedSource plugin.
    $properties['embed_source'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source ID'));
    // Title setting used on iframes and other elements.
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'));
    // Params is a space to store any related metadata.
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
        'input' => [
          'default' => '',
          'description' => 'The embed code or URL for the embedded element.',
          'not null' => FALSE,
          'size' => 'big',
          'type' => 'text',
        ],
        'embed_source' => [
          'default' => '',
          'description' => 'The embed_source id matching the user input',
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
          'type' => 'blob',
        ],
      ],
      'indexes' => [
        'input' => ['input'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $code = $this->get('input')->getValue();
    return $code === NULL || $code === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'input';
  }

}
