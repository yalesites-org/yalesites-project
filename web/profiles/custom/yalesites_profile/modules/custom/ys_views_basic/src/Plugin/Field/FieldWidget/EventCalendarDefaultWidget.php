<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'event_calendar_default' widget.
 *
 * @FieldWidget(
 *   id = "event_calendar_default_widget",
 *   label = @Translation("Event calendar default widget"),
 *   field_types = {
 *     "event_calendar_basic_params"
 *   }
 * )
 */
class EventCalendarDefaultWidget extends ViewsBasicDefaultWidget {

  /**
   * {@inheritdoc}
   *
   * Add event calendar specific options.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $formState) {


    $formSelectors = $this->viewsBasicManager->getFormSelectors($formState, NULL, NULL);
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

    $custom_vocab_label = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab')->label();
    $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'] = [
      '#type' => 'checkboxes',
      '#options' => [
        'show_category_filter' => $this->t('Show Event Category'),
        'show_audience_filter' => $this->t('Show Audience'),
        'show_custom_vocab_filter' => $this->t('Show @vocab', ['@vocab' => $custom_vocab_label]),
      ],
      '#title' => $this->t('Exposed Filter Options'),
      '#tree' => TRUE,
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('exposed_filter_options', $items[$delta]->params) : [],
    ];

    $vocabulary_id = 'event_category';
    $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Event Category'),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents($vocabulary_id),
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('category_included_terms', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-category-included-terms">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [$formSelectors['show_category_filter_selector'] => ['checked' => TRUE]],
      ],
    ];

    $vocabulary_id = 'audience';
    $form['group_user_selection']['entity_and_view_mode']['audience_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Audience'),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents($vocabulary_id),
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('audience_included_terms', $items[$delta]->params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-audience-included-terms">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [$formSelectors['show_audience_filter_selector'] => ['checked' => TRUE]],
      ],
    ];

    $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by @vocab Parent Term', ['@vocab' => $custom_vocab_label]),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents('custom_vocab'),
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('custom_vocab_included_terms', $items[$delta]->params) : NULL,
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
      '#options' => $this->viewsBasicManager->getEventTags(),
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('terms_include', $items[$delta]->params) : [],
    ];

    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Exclude content that uses the following tags or categories'),
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->getEventTags(),
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
        '+' => $this->t('Can have any term listed in include/exclude terms'),
        ',' => $this->t('Must have all terms listed in include/exclude terms'),
      ],
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('operator', $items[$delta]->params) : '+',
      '#attributes' => [
        'class'     => [
          'term-operator-item',
        ],
      ],
    ];

    $form['group_user_selection']['entity_specific']['event_time_period'] = [
      '#type' => 'radios',
      '#title' => $this->t('Event Time Period'),
      '#options' => [
        'future' => $this->t('Future Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-future.svg" alt="Future Events icon showing a calendar with a future-pointing arrow to the right.">',
        'past' => $this->t('Past Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-past.svg" alt="Past Events icon showing a calendar with a past-pointing arrow to the left.">',
        'all' => $this->t('All Events') . '<img src="/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/event-time-all.svg" alt="All Events icon showing a calendar.">',
      ],
      '#default_value' => ($items[$delta]->params) ? $this->viewsBasicManager->getDefaultParamValue('event_time_period', $items[$delta]->params) : 'future',
      '#prefix' => '<div id="edit-event-time-period">',
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

    $formSelectors = $this->viewsBasicManager->getFormSelectors($form_state);

    foreach ($values as &$value) {
      $paramData = [
        "field_options" => $form['group_user_selection']['entity_and_view_mode']['field_options']['#value'],
        "exposed_filter_options" => $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options']['#value'],
        "category_filter_label" => $form['group_user_selection']['entity_and_view_mode']['category_filter_label']['#value'],
        "category_included_terms" => $form['group_user_selection']['entity_and_view_mode']['category_included_terms']['#value'],
        "audience_included_terms" => $form['group_user_selection']['entity_and_view_mode']['audience_included_terms']['#value'],
        "custom_vocab_included_terms" => $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms']['#value'],
        "terms_include" => $form['group_user_selection']['filter_and_sort']['terms_include']['#value'],
        "terms_exclude" => $form['group_user_selection']['filter_and_sort']['terms_exclude']['#value'],
        "event_time_period" => $form['group_user_selection']['entity_specific']['event_time_period']['#value'],
        "operator" => $form['group_user_selection']['filter_and_sort']['term_operator']['#value'],
        "sort_by" => $form_state->getValue($formSelectors['sort_by_array']),
        "display" => $form_state->getValue($formSelectors['display_array']),
        "limit" => (int) $form_state->getValue($formSelectors['limit_array']),
      ];
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

}
