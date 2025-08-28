<?php

namespace Drupal\ys_views_basic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event calendar exposed filter form with AJAX.
 */
class EventCalendarFilterForm extends FormBase {

  /**
   * The calendar wrapper ID constant.
   */
  const CALENDAR_WRAPPER_ID = 'event-calendar-wrapper-static';

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $viewsBasicManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The events calendar service.
   *
   * @var \Drupal\ys_views_basic\Service\EventsCalendarInterface
   */
  protected $eventsCalendar;

  /**
   * Constructs the form object.
   *
   * @param \Drupal\ys_views_basic\ViewsBasicManager $viewsBasicManager
   *   The views basic manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\ys_views_basic\Service\EventsCalendarInterface $eventsCalendar
   *   The events calendar service.
   */
  public function __construct(ViewsBasicManager $viewsBasicManager, EntityTypeManagerInterface $entityTypeManager, EventsCalendarInterface $eventsCalendar) {
    $this->viewsBasicManager = $viewsBasicManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->eventsCalendar = $eventsCalendar;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
    $container->get('ys_views_basic.views_basic_manager'),
      $container->get('entity_type.manager'),
      $container->get('ys_views_basic.events_calendar'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'event_calendar_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $params = NULL, $wrapper_id = NULL) {
    // Decode the params from the field widget.
    $paramsDecoded = $params ? json_decode($params, TRUE) : [];
    $exposedFilterOptions = $paramsDecoded['exposed_filter_options'] ?? [];

    // Set up form wrapper and libraries.
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'] = [
      'core/drupal.ajax',
      'atomic/chosen-select',
      'atomic/calendar',
    ];

    // Store the calendar wrapper ID in the form state.
    $form_state->set('calendar_wrapper_id', self::CALENDAR_WRAPPER_ID);

    // Create filters container.
    $form['filters_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ys-filter-form', 'ys-filter-form--scaffold'],
      ],
    ];

    // Build filter elements.
    $this->buildFilterElements($form, $form_state, $exposedFilterOptions, $paramsDecoded);

    // Add hidden fields.
    $this->addHiddenFields($form, $paramsDecoded, $form_state);

    // Render initial calendar.
    $this->renderCalendar($form, $this->getFiltersFromParams($paramsDecoded), $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op: all logic is handled in AJAX callback.
  }

  /**
   * AJAX callback for filter form.
   */
  public function ajaxFilterCallback(array &$form, FormStateInterface $form_state) {
    // Ensure navigated month/year from user input are persisted in form state.
    $user_input = (array) $form_state->getUserInput();
    if (!empty($user_input['calendar_month'])) {
      $form_state->setValue(['filters_container', 'calendar_month'], $user_input['calendar_month']);
    }
    if (!empty($user_input['calendar_year'])) {
      $form_state->setValue(['filters_container', 'calendar_year'], $user_input['calendar_year']);
    }

    // Get filters from form state.
    $filters = $this->getFiltersFromFormState($form_state);

    // Render updated calendar.
    $this->renderCalendar($form, $filters, $form_state);

    // Return just the calendar container.
    return $form['calendar_container']['calendar'];
  }

  /**
   * Builds filter elements based on exposed filter options.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $exposedFilterOptions
   *   The exposed filter options.
   * @param array $paramsDecoded
   *   The decoded parameters.
   */
  private function buildFilterElements(array &$form, FormStateInterface $form_state, array $exposedFilterOptions, array $paramsDecoded) {
    // Get current form values for AJAX rebuilds.
    $currentValues = [
      'category' => $form_state->getValue('category_included_terms') ?? ($paramsDecoded['category_included_terms'] ?? []),
      'audience' => $form_state->getValue('audience_included_terms') ?? ($paramsDecoded['audience_included_terms'] ?? []),
      'custom_vocab' => $form_state->getValue('custom_vocab_included_terms') ?? ($paramsDecoded['custom_vocab_included_terms'] ?? []),
    ];

    // Search filter (ajax triggers via debounced change in JS).
    if (!empty($exposedFilterOptions['show_search_filter'])) {
      $form['filters_container']['search'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Search'),
        '#default_value' => $form_state->getValue('search') ?? '',
        '#attributes' => [
          'placeholder' => $this->t('Search events'),
          'class' => ['ys-events-search-input'],
        ],
        '#ajax' => [
          'callback' => '::ajaxFilterCallback',
          'wrapper' => self::CALENDAR_WRAPPER_ID,
          'event' => 'ys-calendar-search',
          'progress' => ['type' => 'none'],
        ],
      ];
    }

    // Category filter.
    if (!empty($exposedFilterOptions['show_category_filter'])) {
      $form['filters_container']['category_included_terms'] = $this->createFilterElement(
        'Category',
        $this->getTaxonomyOptions('event_category', $paramsDecoded['category_included_terms'] ?? NULL),
        $currentValues['category']
      );
    }

    // Audience filter.
    if (!empty($exposedFilterOptions['show_audience_filter'])) {
      $form['filters_container']['audience_included_terms'] = $this->createFilterElement(
        'Audience',
        $this->getTaxonomyOptions('audience', $paramsDecoded['audience_included_terms'] ?? NULL),
        $currentValues['audience']
      );
    }

    // Custom vocabulary filter.
    if (!empty($exposedFilterOptions['show_custom_vocab_filter'])) {
      $custom_vocab_label = $this->entityTypeManager
        ->getStorage('taxonomy_vocabulary')
        ->load('custom_vocab')
        ->label();

      $form['filters_container']['custom_vocab_included_terms'] = $this->createFilterElement(
        $custom_vocab_label,
        $this->getTaxonomyOptions('custom_vocab', $paramsDecoded['custom_vocab_included_terms'] ?? NULL),
        $currentValues['custom_vocab']
      );
    }
  }

  /**
   * Creates a filter element with consistent configuration.
   *
   * @param string $title
   *   The filter title.
   * @param array $options
   *   The filter options.
   * @param mixed $default_value
   *   The default value.
   *
   * @return array
   *   The filter element array.
   */
  private function createFilterElement(string $title, array $options, $default_value): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('@title', ['@title' => $title]),
      '#options' => $options,
      '#default_value' => $default_value,
      '#multiple' => TRUE,
      '#chosen' => TRUE,
      '#ajax' => [
        'callback' => '::ajaxFilterCallback',
        'wrapper' => self::CALENDAR_WRAPPER_ID,
        'event' => 'change',
        'progress' => ['type' => 'none'],
      ],
    ];
  }

  /**
   * Gets taxonomy options for a vocabulary, optionally filtered by parent term.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param mixed $parent_term_id
   *   The parent term ID from widget configuration, if any.
   *
   * @return array
   *   The taxonomy options.
   */
  private function getTaxonomyOptions(string $vocabulary, $parent_term_id = NULL): array {
    // If a parent term is selected in the widget, show only its children.
    if (!empty($parent_term_id)) {
      $child_terms = $this->viewsBasicManager->getChildTermsByParentId((int) $parent_term_id, $vocabulary);

      // Load the term entities to get their names.
      if (!empty($child_terms)) {
        $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $terms = $term_storage->loadMultiple(array_keys($child_terms));
        foreach ($terms as $term) {
          $options[$term->id()] = $term->getName();
        }
      }

      return $options;
    }

    // Default behavior: show all parent terms.
    $options = $this->viewsBasicManager->getTaxonomyParents($vocabulary);
    unset($options['']);
    return $options;
  }

  /**
   * Adds hidden fields to the form.
   *
   * @param array $form
   *   The form array.
   * @param array $paramsDecoded
   *   The decoded parameters.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function addHiddenFields(array &$form, array $paramsDecoded, FormStateInterface $form_state) {
    // Hidden filters stay under the filters_container.
    $filterHiddenFields = [
      'terms_include' => $paramsDecoded['terms_include'] ?? [],
      'terms_exclude' => $paramsDecoded['terms_exclude'] ?? [],
      'term_operator' => $paramsDecoded['term_operator'] ?? '+',
      'event_time_period' => $paramsDecoded['event_time_period'] ?? 'all',
    ];

    foreach ($filterHiddenFields as $field => $value) {
      $form['filters_container'][$field] = [
        '#type' => 'hidden',
        '#value' => $value,
      ];
    }

    // Persist navigated calendar state in filters container for :has() CSS
    // selector counting.
    $form['filters_container']['calendar_month'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('calendar_month') ?? date('m'),
    ];
    $form['filters_container']['calendar_year'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('calendar_year') ?? date('Y'),
    ];
  }

  /**
   * Gets filters array from decoded parameters.
   *
   * @param array $paramsDecoded
   *   The decoded parameters.
   *
   * @return array
   *   The filters array.
   */
  private function getFiltersFromParams(array $paramsDecoded): array {
    return [
      'category_included_terms' => $paramsDecoded['category_included_terms'] ?? [],
      'audience_included_terms' => $paramsDecoded['audience_included_terms'] ?? [],
      'custom_vocab_included_terms' => $paramsDecoded['custom_vocab_included_terms'] ?? [],
      'terms_include' => $paramsDecoded['terms_include'] ?? [],
      'terms_exclude' => $paramsDecoded['terms_exclude'] ?? [],
      'term_operator' => $paramsDecoded['term_operator'] ?? '+',
      'event_time_period' => $paramsDecoded['event_time_period'] ?? 'all',
      'search' => $paramsDecoded['search'] ?? '',
    ];
  }

  /**
   * Gets filters array from form state values.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The filters array.
   */
  private function getFiltersFromFormState(FormStateInterface $form_state): array {
    // Helper function to ensure array format.
    $ensureArray = function ($value) {
      return is_array($value) ? $value : ($value ? [$value] : []);
    };

    return [
      'category_included_terms' => $ensureArray($form_state->getValue('category_included_terms')),
      'audience_included_terms' => $ensureArray($form_state->getValue('audience_included_terms')),
      'custom_vocab_included_terms' => $ensureArray($form_state->getValue('custom_vocab_included_terms')),
      'terms_include' => $ensureArray($form_state->getValue('terms_include')),
      'terms_exclude' => $ensureArray($form_state->getValue('terms_exclude')),
      'term_operator' => $form_state->getValue('term_operator') ?: '+',
      'event_time_period' => $form_state->getValue('event_time_period') ?: 'all',
      'search' => trim((string) ($form_state->getValue('search') ?? '')),
    ];
  }

  /**
   * Renders the calendar with given filters.
   *
   * @param array $form
   *   The form array.
   * @param array $filters
   *   The filters to apply.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state containing month/year values.
   */
  private function renderCalendar(array &$form, array $filters, FormStateInterface $form_state) {
    // Use navigated month/year if provided, otherwise fall back to current.
    $user_input = (array) $form_state->getUserInput();
    $month = $form_state->getValue(['filters_container', 'calendar_month'])
      ?: ($user_input['calendar_month'] ?? ($user_input['filters_container']['calendar_month'] ?? date('m')));
    $year = $form_state->getValue(['filters_container', 'calendar_year'])
      ?: ($user_input['calendar_year'] ?? ($user_input['filters_container']['calendar_year'] ?? date('Y')));

    // Get calendar data.
    $events_calendar = $this->eventsCalendar->getCalendar($month, $year, $filters);

    // Build cache contexts based on active filters.
    $cache_contexts = [
      'timezone',
      'user',
      'url.query_args',
    ];

    // Build cache tags.
    $cache_tags = [
      // Invalidate when any event node changes.
      'node_list:event',
    ];

    // Create calendar container.
    $form['calendar_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ys-filter-form__calendar']],
      'calendar' => [
        '#type' => 'container',
        '#attributes' => ['id' => self::CALENDAR_WRAPPER_ID],
        '#cache' => [
          'contexts' => $cache_contexts,
          'tags' => $cache_tags,
        ],
        'calendar_content' => [
          '#theme' => 'views_basic_events_calendar',
          '#month_data' => $events_calendar,
          '#cache' => [
            'contexts' => $cache_contexts,
            'tags' => $cache_tags,
          ],
        ],
      ],
    ];
  }

}
