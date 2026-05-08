<?php

namespace Drupal\ys_dcn_field\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'dcn_field_default' widget.
 *
 * @FieldWidget(
 *   id = "dcn_field_default",
 *   label = @Translation("DCN Field"),
 *   field_types = {
 *     "dcn_field"
 *   }
 * )
 */
class DcnFieldDefaultWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a DcnFieldDefaultWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $field_settings = $this->getFieldSettings();
    $vocabulary = $field_settings['dcn_type_vocabulary'] ?? 'dcn_types';

    // Load taxonomy terms for the select list.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadTree($vocabulary, 0, NULL, TRUE);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->getName();
    }

    // Wrap in a container to display fields inline.
    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'dcn-field-widget-inline';

    $element['dcn_type_target_id'] = [
      '#type' => 'select',
      '#title' => $this->t('DCN Type'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $item->dcn_type_target_id ?? NULL,
      '#required' => $element['#required'],
      '#prefix' => '<div class="dcn-field-item dcn-type">',
      '#suffix' => '</div>',
    ];

    $element['dcn_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('DCN Identifier'),
      '#default_value' => $item->dcn_identifier ?? NULL,
      '#size' => 60,
      '#maxlength' => 255,
      '#required' => $element['#required'],
      '#placeholder' => $this->t('e.g., 978-0-306-40615-7'),
      '#prefix' => '<div class="dcn-field-item dcn-identifier">',
      '#suffix' => '</div>',
    ];

    // Attach CSS library.
    $element['#attached']['library'][] = 'ys_dcn_field/dcn_field_widget';

    return $element;
  }

}
