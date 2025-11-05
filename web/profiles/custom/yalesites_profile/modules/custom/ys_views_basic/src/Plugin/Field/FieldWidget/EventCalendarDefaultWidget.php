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
   * Vocabulary IDs used in the widget.
   */
  const VOCABULARY_EVENT_CATEGORY = 'event_category';
  const VOCABULARY_AUDIENCE = 'audience';
  const VOCABULARY_CUSTOM = 'custom_vocab';

  /**
   * Default term operator values.
   */
  const TERM_OPERATOR_OR = '+';
  const TERM_OPERATOR_AND = ',';

  /**
   * Event time period options.
   */
  const TIME_PERIOD_FUTURE = 'future';
  const TIME_PERIOD_PAST = 'past';
  const TIME_PERIOD_ALL = 'all';

  /**
   * {@inheritdoc}
   *
   * Add event calendar specific options.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $formState) {
    // Initialize form selectors and basic structure.
    $this->initializeFormStructure($form, $formState);

    // Build main parameter container.
    $element['group_params'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['views-basic--params']],
    ];

    // Build all form sections.
    $this->buildUserSelectionContainer($form);
    $this->buildExposedFilterOptions($form, $items, $delta);
    $this->buildTaxonomyFilters($form, $items, $delta);
    $this->buildTermFilters($form, $items, $delta);
    $this->buildEventSpecificOptions($form, $items, $delta);
    $this->buildParamsTextarea($element, $items, $delta);

    // Attach necessary libraries.
    $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic';

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Get data from user selection and save into params field.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $paramData = $this->buildParamData($form);
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Initialize form selectors and basic structure.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   */
  private function initializeFormStructure(array &$form, FormStateInterface $formState) {
    $formSelectors = $this->viewsBasicManager->getFormSelectors($formState, NULL, NULL);
    $form['#form_selectors'] = $formSelectors;
  }

  /**
   * Build the main user selection container structure.
   *
   * @param array $form
   *   The form array.
   */
  private function buildUserSelectionContainer(array &$form) {
    $form['group_user_selection'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['views-basic--group-user-selection'],
        'data-drupal-ck-style-fence' => '',
      ],
      '#weight' => 10,
    ];

    // Create sub-containers.
    $containers = [
      'entity_and_view_mode' => ['views-basic--entity-view-mode'],
      'filter_and_sort' => [],
      'filter_options' => [],
      'entity_specific' => [],
      'options' => [],
    ];

    foreach ($containers as $container_name => $additional_classes) {
      $classes = array_merge(['grouped-items'], $additional_classes);
      $form['group_user_selection'][$container_name] = [
        '#type' => 'container',
        '#attributes' => ['class' => $classes],
      ];
    }
  }

  /**
   * Build exposed filter options checkboxes.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   */
  private function buildExposedFilterOptions(array &$form, FieldItemListInterface $items, int $delta) {
    $custom_vocab_label = $this->getCustomVocabularyLabel();

    $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exposed Filter Options'),
      '#options' => [
        'show_search_filter' => $this->t('Show Search (results based on the content title only)'),
        'show_category_filter' => $this->t('Show Event Category'),
        'show_audience_filter' => $this->t('Show Audience'),
        'show_custom_vocab_filter' => $this->t('Show @vocab', ['@vocab' => $custom_vocab_label]),
      ],
      '#tree' => TRUE,
      '#default_value' => $this->getDefaultParamValue('exposed_filter_options', $items, $delta),
    ];
  }

  /**
   * Build taxonomy filter elements.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   */
  private function buildTaxonomyFilters(array &$form, FieldItemListInterface $items, int $delta) {
    $formSelectors = $form['#form_selectors'];
    $custom_vocab_label = $this->getCustomVocabularyLabel();

    // Category filter.
    $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] =
      $this->createTaxonomyFilterElement(
        'Filter by Event Category',
        self::VOCABULARY_EVENT_CATEGORY,
        'category_included_terms',
        $items,
        $delta,
        $formSelectors['show_category_filter_selector']
      );

    // Audience filter.
    $form['group_user_selection']['entity_and_view_mode']['audience_included_terms'] =
      $this->createTaxonomyFilterElement(
        'Filter by Audience',
        self::VOCABULARY_AUDIENCE,
        'audience_included_terms',
        $items,
        $delta,
        $formSelectors['show_audience_filter_selector']
      );

    // Custom vocabulary filter.
    $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] =
      $this->createTaxonomyFilterElement(
        $this->t('Filter by @vocab', ['@vocab' => $custom_vocab_label]),
        self::VOCABULARY_CUSTOM,
        'custom_vocab_included_terms',
        $items,
        $delta,
        $formSelectors['show_custom_vocab_filter_selector']
      );
  }

  /**
   * Build term include/exclude filters.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   */
  private function buildTermFilters(array &$form, FieldItemListInterface $items, int $delta) {
    $event_tags = $this->viewsBasicManager->getEventTags();

    // Include terms.
    $form['group_user_selection']['filter_and_sort']['terms_include'] = [
      '#title' => $this->t('Include content that uses the following tags or categories'),
      '#type' => 'select',
      '#options' => $event_tags,
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => $this->getDefaultParamValue('terms_include', $items, $delta),
    ];

    // Exclude terms.
    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Exclude content that uses the following tags or categories'),
      '#type' => 'select',
      '#options' => $event_tags,
      '#multiple' => TRUE,
      '#chosen' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => $this->getDefaultParamValue('terms_exclude', $items, $delta),
    ];

    // Term operator.
    $form['group_user_selection']['filter_and_sort']['term_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match Content That Has'),
      '#options' => [
        self::TERM_OPERATOR_OR => $this->t('Can have any term listed in include/exclude terms'),
        self::TERM_OPERATOR_AND => $this->t('Must have all terms listed in include/exclude terms'),
      ],
      '#default_value' => $this->getDefaultParamValue('operator', $items, $delta, self::TERM_OPERATOR_OR),
      '#attributes' => ['class' => ['term-operator-item']],
    ];
  }

  /**
   * Build event-specific options.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   */
  private function buildEventSpecificOptions(array &$form, FieldItemListInterface $items, int $delta) {
    $icon_base_path = '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/';

    $form['group_user_selection']['entity_specific']['event_time_period'] = [
      '#type' => 'radios',
      '#title' => $this->t('Event Time Period'),
      '#options' => [
        self::TIME_PERIOD_FUTURE => $this->t('Future Events') .
        '<img src="' . $icon_base_path . 'event-time-future.svg" alt="Future Events icon showing a calendar with a future-pointing arrow to the right.">',
        self::TIME_PERIOD_PAST => $this->t('Past Events') .
        '<img src="' . $icon_base_path . 'event-time-past.svg" alt="Past Events icon showing a calendar with a past-pointing arrow to the left.">',
        self::TIME_PERIOD_ALL => $this->t('All Events') .
        '<img src="' . $icon_base_path . 'event-time-all.svg" alt="All Events icon showing a calendar.">',
      ],
      '#default_value' => $this->getDefaultParamValue('event_time_period', $items, $delta, self::TIME_PERIOD_FUTURE),
      '#prefix' => '<div id="edit-event-time-period">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Build the params textarea.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   */
  private function buildParamsTextarea(array &$element, FieldItemListInterface $items, int $delta) {
    $element['group_params']['params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Params (for developers)'),
      '#description' => $this->t('This field is automatically populated based on your selections above. Only modify if you know what you are doing.'),
      '#default_value' => $items[$delta]->params ?? NULL,
      '#empty_value' => '',
      '#attributes' => [
        'class' => ['views-basic--params', 'visually-hidden'],
        'readonly' => 'readonly',
      ],
    ];
  }

  /**
   * Create a taxonomy filter element with consistent configuration.
   *
   * @param string $title
   *   The filter title.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $field_name
   *   The field name for form state.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   * @param string $visibility_selector
   *   The form selector for visibility state.
   *
   * @return array
   *   The form element array.
   */
  private function createTaxonomyFilterElement(string $title, string $vocabulary_id, string $field_name, FieldItemListInterface $items, int $delta, string $visibility_selector): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('@title', ['@title' => $title]),
      '#description' => $this->t("Select a parent term to show content tagged with that terms sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents($vocabulary_id),
      '#default_value' => $this->getDefaultParamValue($field_name, $items, $delta),
      '#validated' => 'true',
      '#prefix' => '<div id="edit-' . str_replace('_', '-', $field_name) . '">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [$visibility_selector => ['checked' => TRUE]],
      ],
    ];
  }

  /**
   * Get default parameter value with fallback.
   *
   * @param string $param_name
   *   The parameter name.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The delta value.
   * @param mixed $fallback
   *   The fallback value.
   *
   * @return mixed
   *   The default value.
   */
  private function getDefaultParamValue(string $param_name, FieldItemListInterface $items, int $delta, $fallback = NULL) {
    return ($items[$delta]->params)
      ? $this->viewsBasicManager->getDefaultParamValue($param_name, $items[$delta]->params)
      : ($fallback ?: ($param_name === 'terms_include' || $param_name === 'terms_exclude' || $param_name === 'exposed_filter_options' ? [] : NULL));
  }

  /**
   * Get the custom vocabulary label.
   *
   * @return string
   *   The custom vocabulary label.
   */
  private function getCustomVocabularyLabel(): string {
    return $this->entityTypeManager
      ->getStorage('taxonomy_vocabulary')
      ->load(self::VOCABULARY_CUSTOM)
      ->label();
  }

  /**
   * Build parameter data array for encoding.
   *
   * @param array $form
   *   The form array.
   *
   * @return array
   *   The parameter data array.
   */
  private function buildParamData(array $form): array {
    return [
      "filters" => [
        "types" => ['event'],
        "terms_include" => $form['group_user_selection']['filter_and_sort']['terms_include']['#value'],
        "terms_exclude" => $form['group_user_selection']['filter_and_sort']['terms_exclude']['#value'],
        "event_time_period" => $form['group_user_selection']['entity_specific']['event_time_period']['#value'],
        "operator" => $form['group_user_selection']['filter_and_sort']['term_operator']['#value'],
      ],
      "exposed_filter_options" => $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options']['#value'],
      "category_filter_label" => $form['group_user_selection']['entity_and_view_mode']['category_filter_label']['#value'] ?? NULL,
      "category_included_terms" => $form['group_user_selection']['entity_and_view_mode']['category_included_terms']['#value'],
      "audience_included_terms" => $form['group_user_selection']['entity_and_view_mode']['audience_included_terms']['#value'],
      "custom_vocab_included_terms" => $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms']['#value'],
    ];
  }

}
