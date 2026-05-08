<?php

namespace Drupal\ys_dcn_field\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;

/**
 * Plugin implementation of the 'dcn_field' field type.
 *
 * @FieldType(
 *   id = "dcn_field",
 *   label = @Translation("Document Control Number"),
 *   description = @Translation("Stores a DCN type (taxonomy reference) and identifier (text)."),
 *   default_widget = "dcn_field_default",
 *   default_formatter = "dcn_field_default",
 *   category = @Translation("Reference"),
 * )
 */
class DcnFieldItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    // The DCN type as an entity reference to taxonomy term.
    $properties['dcn_type_target_id'] = DataReferenceTargetDefinition::create('integer')
      ->setLabel(t('DCN Type'))
      ->setRequired(TRUE);

    // The DCN identifier as plain text.
    $properties['dcn_identifier'] = DataDefinition::create('string')
      ->setLabel(t('DCN Identifier'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'dcn_type_target_id' => [
          'description' => 'The ID of the target taxonomy term entity.',
          'type' => 'int',
          'unsigned' => TRUE,
        ],
        'dcn_identifier' => [
          'description' => 'The DCN identifier value.',
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
      'indexes' => [
        'dcn_type_target_id' => ['dcn_type_target_id'],
      ],
      'foreign keys' => [
        'dcn_type_target_id' => [
          'table' => 'taxonomy_term_data',
          'columns' => ['dcn_type_target_id' => 'tid'],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'dcn_type_vocabulary' => 'dcn_types',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);

    $vocabularies = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    $vocab_options = [];
    foreach ($vocabularies as $vid => $vocabulary) {
      $vocab_options[$vid] = $vocabulary->label();
    }

    $element['dcn_type_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('DCN Type Vocabulary'),
      '#description' => $this->t('Select the taxonomy vocabulary to use for DCN types.'),
      '#options' => $vocab_options,
      '#default_value' => $this->getSetting('dcn_type_vocabulary'),
      '#required' => TRUE,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $dcn_type = $this->get('dcn_type_target_id')->getValue();
    $dcn_identifier = $this->get('dcn_identifier')->getValue();

    return empty($dcn_type) && empty($dcn_identifier);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();

    // Add constraint to ensure both fields are filled or both are empty.
    $constraint_manager = \Drupal::typedDataManager()
      ->getValidationConstraintManager();

    $constraints[] = $constraint_manager->create('ComplexData', [
      'dcn_type_target_id' => [
        'NotBlank' => [],
      ],
      'dcn_identifier' => [
        'NotBlank' => [],
        'Length' => ['max' => 255],
      ],
    ]);

    return $constraints;
  }

  /**
   * Gets the DCN type term entity.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The DCN type term entity, or NULL if not set.
   */
  public function getDcnType() {
    $target_id = $this->get('dcn_type_target_id')->getValue();
    if (!empty($target_id)) {
      return \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->load($target_id);
    }
    return NULL;
  }

}
