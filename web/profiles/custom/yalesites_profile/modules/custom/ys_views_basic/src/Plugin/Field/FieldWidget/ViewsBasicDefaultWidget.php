<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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

    /*
     * We need to check if we are in layout builder or not as the arrays are
     * different between layout builder and the global block editor.
     *
     * This flag is set in ys_views_basic.module.
     *
     */
    $inLayoutBuilder = ($formState->getCompleteForm() &&
    isset($formState->getCompleteForm()['#in_layout_builder'])) ? $formState->getCompleteForm()['#in_layout_builder'] : FALSE;

    // Pass this to massageFormValues and the Ajax callbacks.
    if ($inLayoutBuilder) {
      $form['#in_layout_builder'] = $inLayoutBuilder;
    }

    $entity_list = $this->viewsBasicManager->entityTypeList();
    $entityValue = array_key_first($entity_list);
    $decodedParams = json_decode($items[$delta]->params, TRUE);
    if (!empty($decodedParams['filters']['types'][0])) {
      $entityValue = $decodedParams['filters']['types'][0];
    }

    // Gets the value of the selected entity for Ajax callbacks.
    // Via: https://www.drupal.org/project/drupal/issues/2758631
    if ($formState->isRebuilding()) {
      $allValues = $formState->getValues();
      $entityValue = $inLayoutBuilder ? $allValues['settings']['block_form']['group_user_selection']['entity_and_view_mode']['entity_types'] : $allValues['entity_types'];
    }

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
        'data-drupal-ck-style-fence' => '',
      ],
      '#weight' => 10,
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

    $form['group_user_selection']['filter_options'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'grouped-items',
        ],
      ],
    ];

    $form['group_user_selection']['entity_specific'] = [
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
          'type' => 'none',
        ],
      ],
    ];

    // Gets the view mode options based on Ajax callbacks or initial load.
    $viewModeOptions = $this->viewsBasicManager->viewModeList($entityValue);

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

    $form['group_user_selection']['filter_and_sort']['terms_include'] = [
      '#title' => $this->t('Include content that uses the following terms'),
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->getAllTags(),
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('terms_include', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Exclude content that uses the following terms'),
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->getAllTags(),
      '#multiple' => TRUE,
      '#chosen' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('terms_exclude', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['filter_and_sort']['term_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match Content That Has'),
      // Set operator: "+" is "OR" and "," is "AND".
      '#options' => [
        '+' => $this->t('Can have any term listed in tags and categories'),
        ',' => $this->t('Must have all terms listed in tags and categories'),
      ],
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('operator', $items[$delta]->params) : '+',
      '#attributes' => [
        'class'     => [
          'term-operator-item',
        ],
      ],

    ];

    // Gets the view mode options based on Ajax callbacks or initial load.
    $sortOptions = $this->viewsBasicManager->sortByList($entityValue);

    $form['group_user_selection']['filter_and_sort']['sort_by'] = [
      '#type' => 'select',
      '#description' => $this->t('Items marked "Pin to the beginning of list" will precede the selected sort.'),
      '#options' => $sortOptions,
      '#title' => $this->t('Sorting by'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('sort_by', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-sort-by">',
      '#suffix' => '</div>',
    ];

    $entityTypesAjaxArray = $inLayoutBuilder ? ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]' : ':input[name="entity_types"]';

    $form['group_user_selection']['entity_specific']['event_time_period'] = [
      '#type' => 'radios',
      '#title' => $this->t('Event Time Period'),
      '#options' => [
        'future' => $this->t('Future Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-future.svg" alt="Future Events icon showing a calendar with a future-pointing arrow to the right.">',
        'past' => $this->t('Past Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-past.svg" alt="Past Events icon showing a calendar with a past-pointing arrow to the left.">',
        'all' => $this->t('All Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-all.svg" alt="All Events icon showing a calendar.">',
      ],
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('event_time_period', $items[$delta]->params) : 'future',
      '#states' => [
        'visible' => [
          $entityTypesAjaxArray => [
            'value' => 'event',
          ],
        ],
      ],
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

    $displayAjaxArray = $inLayoutBuilder ? ':input[name="settings[block_form][group_user_selection][options][display]"]' : ':input[name="display"]';

    $form['group_user_selection']['options']['limit'] = [
      '#title' => $limitTitle,
      '#type' => 'number',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('limit', $items[$delta]->params) : 10,
      '#min' => 1,
      '#required' => TRUE,
      '#states' => [
        'invisible' => [
          $displayAjaxArray => [
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
    $inLayoutBuilder = $form['#in_layout_builder'] ?? FALSE;

    $termsIncludeAjaxArray = $inLayoutBuilder ? [
      'settings',
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'terms_include',
    ] : [
      'group_user_selection',
      'filter_and_sort',
      'terms_include',
    ];

    $termsExcludeAjaxArray = $inLayoutBuilder ? [
      'settings',
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'terms_exclude',
    ] : [
      'group_user_selection',
      'filter_and_sort',
      'terms_exclude',
    ];

    $sortByAjaxArray = $inLayoutBuilder ? [
      'settings',
      'block_form',
      'group_user_selection',
      'filter_and_sort',
      'sort_by',
    ] : [
      'group_user_selection',
      'filter_and_sort',
      'sort_by',
    ];

    $displayAjaxArray = $inLayoutBuilder ? [
      'settings',
      'block_form',
      'group_user_selection',
      'options',
      'display',
    ] : [
      'group_user_selection',
      'options',
      'display',
    ];

    $limitAjaxArray = $inLayoutBuilder ? [
      'settings',
      'block_form',
      'group_user_selection',
      'options',
      'limit',
    ] : [
      'group_user_selection',
      'options',
      'limit',
    ];

    $terms_include = ($form_state->getValue($termsIncludeAjaxArray)) ?? NULL;
    $terms_exclude = ($form_state->getValue($termsExcludeAjaxArray)) ?? NULL;

    foreach ($values as &$value) {
      $paramData = [
        "view_mode" => $form['group_user_selection']['entity_and_view_mode']['view_mode']['#value'],
        "filters" => [
          "types" => [
            $form['group_user_selection']['entity_and_view_mode']['entity_types']['#value'],
          ],
          "terms_include" => $terms_include,
          "terms_exclude" => $terms_exclude,
          "event_time_period" => $form['group_user_selection']['entity_specific']['event_time_period']['#value'],
        ],
        "operator" => $form['group_user_selection']['filter_and_sort']['term_operator']['#value'],
        "sort_by" => $form_state->getValue($sortByAjaxArray),
        "display" => $form_state->getValue($displayAjaxArray),
        "limit" => (int) $form_state->getValue($limitAjaxArray),
      ];
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Ajax callback to return only view modes for the specified content type.
   */
  public function updateOtherSettings(array &$form, FormStateInterface $form_state) {
    $inLayoutBuilder = $form['#in_layout_builder'] ?? FALSE;

    $viewModeAjaxArray = $inLayoutBuilder ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : $form['group_user_selection']['entity_and_view_mode']['view_mode'];
    $sortByAjaxArray = $inLayoutBuilder ? $form['settings']['block_form']['group_user_selection']['filter_and_sort']['sort_by'] : $form['group_user_selection']['filter_and_sort']['sort_by'];

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#edit-view-mode', $viewModeAjaxArray));
    $selector = '.views-basic--view-mode[name="group_user_selection[entity_and_view_mode][view_mode]"]:first';
    $response->addCommand(new InvokeCommand($selector, 'prop', [['checked' => TRUE]]));
    $response->addCommand(new ReplaceCommand('#edit-sort-by', $sortByAjaxArray));
    return $response;
  }

  /**
   * Ajax callback to update the limit field.
   */
  public function updateLimitField(array &$form, FormStateInterface $form_state) {
    $inLayoutBuilder = $form['#in_layout_builder'] ?? FALSE;

    $displayValue = $inLayoutBuilder ? $form_state->getValue(
      ['settings', 'block_form', 'group_user_selection', 'options', 'display']
    ) : ['group_user_selection', 'options', 'display'];
    $response = new AjaxResponse();
    if ($displayValue != 'all') {
      $limitAjaxArray = $inLayoutBuilder ? $form['settings']['block_form']['group_user_selection']['options']['limit'] : $form['group_user_selection']['options']['limit'];
      $response->addCommand(new ReplaceCommand('#edit-limit', $limitAjaxArray));
    }
    return $response;
  }

}
