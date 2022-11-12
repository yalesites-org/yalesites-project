<?php

namespace Drupal\ys_embed\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'embed' field type.
 *
 * @FieldType(
 *   id = "embed",
 *   label = @Translation("Embed"),
 *   description = @Translation("Fill me in..."),
 *   default_widget = "embed_default",
 *   default_formatter = "embed"
 * )
 */
class Embed extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'class' => NULL,
      'height' => NULL,
      'width' => NULL,
      'scrolling' => NULL,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['embed_code'] = DataDefinition::create('string')
      ->setLabel(t('Embed Code'));
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(t('Title text'));
      $properties['description'] = DataDefinition::create('string')
      ->setLabel(t('Description text'));
    $properties['width'] = DataDefinition::create('string')
      ->setLabel(t('Width'));
    $properties['height'] = DataDefinition::create('string')
      ->setLabel(t('Height'));
    $properties['class'] = DataDefinition::create('string')
      ->setLabel(t('CSS class'));
    $properties['scrolling'] = DataDefinition::create('string')
      ->setLabel(t('Scrolling'));
    return $properties;
  }

  /**
   * Implements hook_field_schema().
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'embed_code' => [
          'description' => 'The source embed code for the embedded element.',
          'type' => 'varchar',
          'length' => 2048,
          'not null' => FALSE,
          'sortable' => TRUE,
          'default' => '',
        ],
        'provider' => [
          'description' => 'The provider id matching the user input',
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
          'sortable' => TRUE,
          'default' => '',
        ],
        'title' => [
          'description' => 'The title attribute for the rendered element.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
          'sortable' => TRUE,
          'default' => '',
        ],
        'description' => [
          'description' => 'The a longform description of the content.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
          'sortable' => TRUE,
          'default' => '',
        ],
        'class' => [
          'description' => 'Add a CSS class attribute. Multiple classes should be separated by spaces.',
          'type' => 'varchar',
          'length' => '255',
          'not null' => FALSE,
          'default' => '',
        ],
        'width' => [
          'description' => 'The rendered element width.',
          'type' => 'varchar',
          'length' => 7,
          'not null' => FALSE,
          'default' => '600',
        ],
        'height' => [
          'description' => 'The rendered element height.',
          'type' => 'varchar',
          'length' => 7,
          'not null' => FALSE,
          'default' => '800',
        ],
        'scrolling' => [
          'description' => 'Scrollbars help users reach all framed content.',
          'type' => 'varchar',
          'length' => 4,
          'not null' => TRUE,
          'default' => 'auto',
        ],
      ],
      'indexes' => [
        'embed_code' => ['embed_code'],
      ],
    ];
  }

  /**
   * Global field settings for iframe field.
   *
   * In contenttype-field-settings "Manage fields" -> "Edit"
   * admin/structure/types/manage/CONTENTTYPE/fields/node.CONTENTTYPE.FIELDNAME.
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = [];
    $settings = $this->getSettings() + self::defaultFieldSettings();
    $element['class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSS Class'),
      '#default_value' => $settings['class'],
    ];
    $element['scrolling'] = [
      '#type' => 'radios',
      '#title' => $this->t('Scrolling'),
      '#default_value' => $settings['scrolling'],
      '#options' => [
        'auto' => $this->t('Automatic'),
        'no' => $this->t('Disabled'),
        'yes' => $this->t('Enabled'),
      ],
    ];
    return $element;
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
