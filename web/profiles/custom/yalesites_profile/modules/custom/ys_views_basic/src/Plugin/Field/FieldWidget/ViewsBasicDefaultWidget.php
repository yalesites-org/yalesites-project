<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'views_basic_default' widget.
 *
 * @FieldWidget(
 *   id = "views_basic_default_widget",
 *   label = @Translation("Views basic default widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class ViewsBasicDefaultWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $viewsBasicManager;

  /**
   * Constructs a ViewsBasicDefaultWidget object.
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
   * @param \Drupal\ys_views_basic\ViewsBasicManager $views_basic_manager
   *   The ViewsBasic management service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    ViewsBasicManager $views_basic_manager
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->viewsBasicManager = $views_basic_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('ys_views_basic.views_basic_manager')
    );
  }

  /**
   * Define the form for the field type.
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    Array $element,
    Array &$form,
    FormStateInterface $formState
  ) {

    $element['group_params'] = [
      '#type' => 'fieldgroup',
      '#attributes' => [
        'class' => [
          'views-basic--params',
        ],
      ],
    ];

    $form['group_user_selection'] = [
      '#type' => 'fieldgroup',
      '#attributes' => [
        'class' => [
          'views-basic--group-user-selection',
        ],
      ],
    ];

    $form['group_user_selection']['entity_types'] = [
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->entityTypeList(),
      '#title' => $this->t('Display'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('types', $items[$delta]->params) : NULL,
      '#wrapper_attributes' => [
        'class' => [
          'views-basic--user-selection',
          'views-basic--entity-types',
        ],
      ],
    ];

    $form['group_user_selection']['view_mode'] = [
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->viewModeList(),
      '#title' => $this->t('as'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('view_mode', $items[$delta]->params) : NULL,
      '#wrapper_attributes' => [
        'class' => [
          'views-basic--user-selection',
          'views-basic--view-mode',
        ],
      ],
    ];

    $element['group_params']['params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Params'),
      '#default_value' => $items[$delta]->params ?? NULL,
      '#empty_value' => '',
      '#attributes' => [
        'class'     => [
          'views-basic--params',
        ],
      ],
    ];

    $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic';

    return $element;
  }

  /**
   * Get data from user selection and save into params field.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $paramData = [
        "view_mode" => $form['group_user_selection']['view_mode']['#value'],
        "filters" => [
          "types" => [
            $form['group_user_selection']['entity_types']['#value'],
          ],
        ],
      ];
      $value['params'] = serialize($paramData);
    }
    return $values;
  }

}
