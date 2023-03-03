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

    $form['group_user_selection']['entity_and_view_mode'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'grouped-items',
        ],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode']['entity_types'] = [
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
      '#ajax' => [
        'callback' => [$this, 'updateViewModes'],
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating view modes...'),
        ],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode']['view_mode'] = [
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->viewModeList($content_type),
      '#title' => $this->t('as'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('view_mode', $items[$delta]->params) : NULL,
      '#wrapper_attributes' => [
        'class' => [
          'views-basic--user-selection',
          'views-basic--view-mode',
        ],
      ],
      '#validated' => 'true',
      '#prefix' => '<div id="edit-view-mode">',
      '#suffix' => '</div>',
    ];

    // @todo add validation for only one term.
    // More info: https://www.drupal.org/project/drupal/issues/2951134
    $form['group_user_selection']['tags'] = [
      '#title' => $this->t('Filtered by tag'),
      '#description' => $this->t('At this time, only one term is supported. If multiple terms are added, only the last one will be used.'),
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('tags', $items[$delta]->params) : NULL,
      '#selection_settings' => [
        'target_bundles' => ['tags'],
      ],
    ];

    $form['group_user_selection']['sort_by'] = [
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->sortByList($content_type),
      '#title' => $this->t('Sorting by'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('sort_by', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-sort-by">',
      '#suffix' => '</div>',
    ];

    $form['group_user_selection']['limit'] = [
      '#title' => $this->t('Items to display'),
      '#description' => $this->t('Enter 0 to show all items.'),
      '#type' => 'number',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('limit', $items[$delta]->params) : 0,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['group_user_selection']['pager'] = [
      '#title' => $this->t('Enable Pager'),
      '#type' => 'checkbox',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('pager', $items[$delta]->params) : 0,
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
    $tags = ($form_state->getValue(['group_user_selection', 'tags']))
      ? $form_state->getValue(['group_user_selection', 'tags'])
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
        "limit" => (int) $form_state->getValue(['group_user_selection', 'limit']),
        "sort_by" => $form_state->getValue(['group_user_selection', 'sort_by']),
        "pager" => $form_state->getValue(['group_user_selection', 'pager']),
      ];
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Ajax callback to return only view modes for the specified content type.
   */
  public function updateViewModes(array &$form, FormStateInterface $form_state) {
    if ($selectedValue = $form_state->getValue(
      ['group_user_selection', 'entity_and_view_mode', 'entity_types']
      )) {
      $form['group_user_selection']['entity_and_view_mode']['view_mode']['#options'] = $this->viewsBasicManager->viewModeList($selectedValue);
      $form['group_user_selection']['sort_by']['#options'] = $this->viewsBasicManager->sortByList($selectedValue);
    }

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#edit-view-mode', $form['group_user_selection']['entity_and_view_mode']['view_mode']));
    $response->addCommand(new ReplaceCommand('#edit-sort-by', $form['group_user_selection']['sort_by']));
    return $response;
  }

}
