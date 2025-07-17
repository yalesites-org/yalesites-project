<?php

namespace Drupal\ys_views_content_resources\Plugin\Field\FieldWidget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_views_content_resources\ViewsContentResourcesManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'views_content_resources_default' widget.
 *
 * @FieldWidget(
 *   id = "views_content_resources_default_widget",
 *   label = @Translation("Views Content Resources default widget"),
 *   field_types = {
 *     "views_content_resources_params"
 *   }
 * )
 */
class ViewsContentResourcesDefaultWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_content_resources\ViewsContentResourcesManager
   */
  protected $viewsContentResourcesManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\ys_views_content_resources\ViewsContentResourcesManager $views_content_resources_manager
   *   The ViewsBasic management service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    ViewsContentResourcesManager $views_content_resources_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->viewsContentResourcesManager = $views_content_resources_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('ys_views_content_resources.views_content_resources_manager'),
      $container->get('entity_type.manager')
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
    FormStateInterface $formState,
  ) {
    $decodedParams = json_decode($items[$delta]->params, TRUE);
    if (!empty($decodedParams['filters']['types'][0])) {
      $entityValue = $decodedParams['filters']['types'][0];
    }

    $formSelectors = $this->viewsContentResourcesManager->getFormSelectors($formState, NULL, $entityValue);
    $form['#form_selectors'] = $formSelectors;

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
          'views-basic--entity-view-mode',
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

    // Gets the view mode options based on Ajax callbacks or initial load.
    // In situations where the currently selected view mode does not exist
    // in the new content type, we default to the first item.
    $viewModeOptions = $this->viewsContentResourcesManager->viewModeList('resource');
    $viewModeValue = ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('entity_and_view_mode', $items[$delta]->params) : key($viewModeOptions);
    $viewModeDefault = array_key_first($viewModeOptions);

    if (!array_key_exists($viewModeValue, $viewModeOptions)) {
      $viewModeValue = $viewModeDefault;
    }

    $form['group_user_selection']['entity_and_view_mode']['entity_and_view_mode'] = [
      '#type' => 'radios',
      '#options' => $viewModeOptions,
      '#title' => $this->t('Show resources as'),
      '#tree' => TRUE,
      '#default_value' => $viewModeValue,
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

    $custom_vocab_label = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab')->label();
    $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'] = [
      '#type' => 'checkboxes',
      '#options' => [
        'show_search_filter' => $this->t('Show Search'),
        'show_year_filter' => $this->t('Show Year'),
        'show_category_filter' => $this->t('Show Category'),
        'show_custom_vocab_filter' => $this->t('Show @vocab', ['@vocab' => $custom_vocab_label]),
        'show_audience_filter' => $this->t('Show Audience'),
      ],
      '#title' => $this->t('Exposed Filter Options'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('exposed_filter_options', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['entity_and_view_mode']['category_filter_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category Filter Label'),
      '#description' => $this->t("Enter a custom label for the <strong>Category Filter</strong>. This label will be displayed to users as the filter's name. If left blank, the default label <strong>Category</strong> will be used."),
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('category_filter_label', $items[$delta]->params) : NULL,
      '#states' => [
        'visible' => [$formSelectors['show_category_filter_selector'] => ['checked' => TRUE]],
      ],
    ];

    $vocabulary_id = 'resource_category';
    $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Category Parent Term'),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsContentResourcesManager->getTaxonomyParents($vocabulary_id),
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('category_included_terms', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-category-included-terms">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [$formSelectors['show_category_filter_selector'] => ['checked' => TRUE]],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by @vocab Parent Term', ['@vocab' => $custom_vocab_label]),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsContentResourcesManager->getTaxonomyParents('custom_vocab'),
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('custom_vocab_included_terms', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-custom-vocab-included-terms">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [$formSelectors['show_custom_vocab_filter_selector'] => ['checked' => TRUE]],
      ],
    ];

    $form['group_user_selection']['filter_and_sort']['terms_include'] = [
      '#title' => $this->t('Include content that uses the following tags or categories'),
      '#type' => 'select',
      '#options' => $this->viewsContentResourcesManager->getAllTags(),
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('terms_include', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Exclude content that uses the following tags or categories'),
      '#type' => 'select',
      '#options' => $this->viewsContentResourcesManager->getAllTags(),
      '#multiple' => TRUE,
      '#chosen' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('terms_exclude', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['filter_and_sort']['term_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match Content That Has'),
      // Set operator: "+" is "OR" and "," is "AND".
      '#options' => [
        '+' => $this->t('Can have any term listed in include/exclude terms'),
        ',' => $this->t('Must have all terms listed in include/exclude terms'),
      ],
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('operator', $items[$delta]->params) : '+',
      '#attributes' => [
        'class'     => [
          'term-operator-item',
        ],
      ],
    ];

    // Gets the view mode options based on Ajax callbacks or initial load.
    $sortOptions = $this->viewsContentResourcesManager->sortByList('resource');
    $sortBy = ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('sort_by', $items[$delta]->params) : NULL;

    $form['group_user_selection']['filter_and_sort']['sort_by'] = [
      '#type' => 'select',
      '#options' => $sortOptions,
      '#title' => $this->t('Sorting by'),
      '#tree' => TRUE,
      '#default_value' => $sortBy,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-sort-by">',
      '#suffix' => '</div>',
    ];

    $form['group_user_selection']['filter_and_sort']['pinned_to_top'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show pinned label'),
      '#description' => $this->t('Display a custom label at the top of items.'),
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('pinned_to_top', $items[$delta]->params) : FALSE,
    ];

    // If the saved value is NULL, still default it since it's required.
    $pin_label = (($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('pin_label', $items[$delta]->params) : ViewsContentResourcesManager::DEFAULT_PIN_LABEL) ?? ViewsContentResourcesManager::DEFAULT_PIN_LABEL;

    $form['group_user_selection']['filter_and_sort']['pin_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label to display for pinned items'),
      '#default_value' => $pin_label,
      '#states' => [
        'visible' => [$formSelectors['pinned_to_top_selector'] => ['checked' => TRUE]],
        'required' => [$formSelectors['pinned_to_top_selector'] => ['checked' => TRUE]],
      ],
    ];

    $displayValue = ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('display', $items[$delta]->params) : 'all';

    $form['group_user_selection']['options']['display'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of Items to Display'),
      '#default_value' => $displayValue,
      '#options' => [
        'all' => $this->t('Display all items'),
        'limit' => $this->t('Limit to'),
        'pager' => $this->t('Pagination after'),
      ],
    ];

    $limitTitle = $this->t('Items');

    if ($displayValue && $displayValue == 'pager') {
      $limitTitle = $this->t('Items per Page');
    }

    /*
     * Dynamic changes to this is handled in javascript due to issues with
     * callbacks and #states.
     */
    $form['group_user_selection']['options']['limit'] = [
      '#title' => $limitTitle,
      '#type' => 'number',
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('limit', $items[$delta]->params) : 10,
      '#min' => 1,
      '#required' => TRUE,
      '#prefix' => '<div id="edit-limit">',
      '#suffix' => '</div>',
    ];

    $form['group_user_selection']['options']['offset'] = [
      '#title' => 'Ignore Number of Results',
      '#description' => $this->t('Specify the number of results you want to ignore. If you enter "2", your view will omit the first two results that match the overall parameters you\'ve set in the view interface.'),
      '#type' => 'number',
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('offset', $items[$delta]->params) : 0,
      '#min' => 0,
      '#attributes' => [
        'placeholder' => 0,
      ],
    ];

    $form['group_user_selection']['options']['show_current_entity'] = [
      '#title' => $this->t('Include this content in view'),
      '#type' => 'checkbox',
      '#default_value' => ($items[$delta]->params) ? $this->viewsContentResourcesManager->getDefaultParamValue('show_current_entity', $items[$delta]->params) : 0,
    ];

    $element['group_params']['params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Params'),
      '#default_value' => $items[$delta]->params ?? NULL,
      '#empty_value' => '',
      '#attributes' => [
        'class' => [
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
    $formSelectors = $this->viewsContentResourcesManager->getFormSelectors($form_state);

    $terms_include = ($form_state->getValue($formSelectors['massage_terms_include_array'])) ?? NULL;
    $terms_exclude = ($form_state->getValue($formSelectors['massage_terms_exclude_array'])) ?? NULL;

    foreach ($values as &$value) {
      $paramData = [
        "view_mode" => $form['group_user_selection']['entity_and_view_mode']['entity_and_view_mode']['#value'],
        "filters" => [
          "terms_include" => $terms_include,
          "terms_exclude" => $terms_exclude,
        ],
        "exposed_filter_options" => $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options']['#value'],
        "category_filter_label" => $form['group_user_selection']['entity_and_view_mode']['category_filter_label']['#value'],
        "category_included_terms" => $form['group_user_selection']['entity_and_view_mode']['category_included_terms']['#value'],
        "custom_vocab_included_terms" => $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms']['#value'],
        "operator" => $form['group_user_selection']['filter_and_sort']['term_operator']['#value'],
        "sort_by" => $form_state->getValue($formSelectors['sort_by_array']),
        "display" => $form_state->getValue($formSelectors['display_array']),
        "limit" => (int) $form_state->getValue($formSelectors['limit_array']),
        "offset" => (int) $form_state->getValue($formSelectors['offset_array']),
        "show_current_entity" => $form['group_user_selection']['options']['show_current_entity']['#value'],
        "pinned_to_top" => $form_state->getValue($formSelectors['pinned_to_top_array']),
        "pin_label" => $form_state->getValue($formSelectors['pin_label_array']),
      ];
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Ajax callback to return only view modes for the specified content type.
   */
  public function updateOtherSettings(array &$form, FormStateInterface $form_state): AjaxResponse {
    $formSelectors = $this->viewsContentResourcesManager->getFormSelectors($form_state, $form);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#edit-view-mode', $formSelectors['view_mode_ajax']));
    $response->addCommand(new ReplaceCommand('#edit-sort-by', $formSelectors['sort_by_ajax']));
    $firstViewModeItem = $formSelectors['view_mode_input_selector'] . ':first';
    $response->addCommand(new InvokeCommand($firstViewModeItem, 'prop', [['checked' => TRUE]]));

    return $response;
  }

}
