<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\InvokeCommand;

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

    $entity_list = $this->viewsBasicManager->entityTypeList();
    $content_type = ($items[$delta]->params) ? json_decode($items[$delta]->params, TRUE)['filters']['types'][0] : array_key_first($entity_list);

    // Gets the value of the selected entity for Ajax callbacks.
    // Via: https://www.drupal.org/project/drupal/issues/2758631
    $entityValue = $formState->getValue(
      ['group_user_selection', 'entity_and_view_mode', 'entity_types']
    );

    $element['group_params'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'views-basic--params',
        ],
      ],
    ];

    $form['group_user_selection'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'views-basic--group-user-selection',
        ],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'grouped-items',
        ],
      ],
    ];

    $form['group_user_selection']['filter_and_sort'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'grouped-items',
        ],
      ],
    ];

    $form['group_user_selection']['options'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'grouped-items',
        ],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode']['entity_types'] = [
      '#type' => 'radios',
      '#options' => $this->viewsBasicManager->entityTypeList(),
      '#title' => $this->t('I Want To Show'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('types', $items[$delta]->params) : NULL,
      '#wrapper_attributes' => [
        'class' => [
          'views-basic--user-selection',
          'views-basic--entity-types',
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'updateOtherSettings'],
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating other settings...'),
        ],
      ],
    ];

    // Gets the view mode options based on Ajax callbacks or initial load.
    $viewModeOptions = ($entityValue)
      ? $this->viewsBasicManager->viewModeList($entityValue)
      : $this->viewsBasicManager->viewModeList($content_type);

    $form['group_user_selection']['entity_and_view_mode']['view_mode'] = [
      '#type' => 'radios',
      '#options' => $viewModeOptions,
      '#title' => $this->t('As'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('view_mode', $items[$delta]->params) : key($viewModeOptions),
      '#attributes' => [
        'class' => [
          'views-basic--view-mode',
        ],
      ],
      '#wrapper_attributes' => [
        'class' => [
          'views-basic--user-selection',
        ],
      ],
      '#validated' => 'true',
      '#prefix' => '<div id="edit-view-mode">',
      '#suffix' => '</div>',
    ];

    // @todo add validation for only one term.
    // More info: https://www.drupal.org/project/drupal/issues/2951134
    $form['group_user_selection']['filter_and_sort']['tags'] = [
      '#title' => $this->t('Tagged as'),
      '#description' => $this->t('At this time, only one term is supported. If multiple terms are added, only the last one will be used.'),
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('tags', $items[$delta]->params) : NULL,
      '#selection_settings' => [
        'target_bundles' => ['tags'],
      ],
    ];

    // Gets the view mode options based on Ajax callbacks or initial load.
    $sortOptions = ($entityValue)
      ? $this->viewsBasicManager->sortByList($entityValue)
      : $this->viewsBasicManager->sortByList($content_type);

    $form['group_user_selection']['filter_and_sort']['sort_by'] = [
      '#type' => 'select',
      '#options' => $sortOptions,
      '#title' => $this->t('Sorting by'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('sort_by', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-sort-by">',
      '#suffix' => '</div>',
    ];

    $form['group_user_selection']['options']['display'] = [
      '#type' => 'select',
      '#title' => $this
        ->t('Number of Items to Display'),
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('display', $items[$delta]->params) : 'all',
      '#options' => [
        'all' => $this->t('Display all items'),
        'limit' => $this->t('Limit to'),
        'pager' => $this->t('Pagination after'),
      ],
      '#ajax' => [
        'callback' => [$this, 'updateLimitField'],
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating limit field...'),
        ],
      ],
    ];

    // This section calculates the title for the limit field based on display.
    $numItemsValue = $formState->getValue(
      ['group_user_selection', 'options', 'display']
    );

    $limitTitle = $this->t('Items');

    if ($numItemsValue) {
      if ($numItemsValue == 'pager') {
        $limitTitle = $this->t('Items per Page');
      }
    }

    $form['group_user_selection']['options']['limit'] = [
      '#title' => $limitTitle,
      '#type' => 'number',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('limit', $items[$delta]->params) : 10,
      '#min' => 1,
      '#required' => TRUE,
      '#states' => [
        'invisible' => [
          ':input[name="group_user_selection[options][display]"]' => [
            'value' => 'all',
          ],
        ],
      ],
      '#prefix' => '<div id="edit-limit">',
      '#suffix' => '</div>',
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
    $tags = ($form_state->getValue(
        ['group_user_selection', 'filter_and_sort', 'tags']
      ))
      ? $form_state->getValue(
          ['group_user_selection', 'filter_and_sort', 'tags']
        )
      : NULL;
    foreach ($values as &$value) {
      $paramData = [
        "view_mode" => $form['group_user_selection']['entity_and_view_mode']['view_mode']['#value'],
        "filters" => [
          "types" => [
            $form['group_user_selection']['entity_and_view_mode']['entity_types']['#value'],
          ],
          "tags" => [
            $tags,
          ],
        ],
        "sort_by" => $form_state->getValue(
          ['group_user_selection', 'filter_and_sort', 'sort_by']
        ),
        "display" => $form_state->getValue(
          ['group_user_selection', 'options', 'display']
        ),
        "limit" => (int) $form_state->getValue(
          ['group_user_selection', 'options', 'limit']
        ),
      ];
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Ajax callback to return only view modes for the specified content type.
   */
  public function updateOtherSettings(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#edit-view-mode', $form['group_user_selection']['entity_and_view_mode']['view_mode']));
    $selector = '.views-basic--view-mode[name="group_user_selection[entity_and_view_mode][view_mode]"]:first';
    $response->addCommand(new InvokeCommand($selector, 'prop', [['checked' => TRUE]]));
    $response->addCommand(new ReplaceCommand('#edit-sort-by', $form['group_user_selection']['filter_and_sort']['sort_by']));
    return $response;
  }

  /**
   * Ajax callback to update the limit field.
   */
  public function updateLimitField(array &$form, FormStateInterface $form_state) {
    $displayValue = $form_state->getValue(
      ['group_user_selection', 'options', 'display']
    );
    $response = new AjaxResponse();
    if ($displayValue != 'all') {
      $response->addCommand(new ReplaceCommand('#edit-limit', $form['group_user_selection']['options']['limit']));
    }
    return $response;
  }

}
