<?php

namespace Drupal\ys_views_basic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event calendar exposed filter form with AJAX.
 */
class EventCalendarFilterForm extends FormBase {

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
   * Constructs the form object.
   */
  public function __construct(ViewsBasicManager $viewsBasicManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->viewsBasicManager = $viewsBasicManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
    $container->get('ys_views_basic.views_basic_manager'),
    $container->get('entity_type.manager')
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
    $paramsDecoded = $params ? json_decode($params, TRUE) : [];


    $exposedFilterOptions = $paramsDecoded['exposed_filter_options'] ?? [];
    $unique_id = uniqid();
    $form_wrapper_id = $wrapper_id ?: 'event-calendar-form-wrapper-' . $unique_id;
    $calendar_wrapper_id = $form_wrapper_id;

    $form['#prefix'] = '<div id="' . $form_wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $form['#calendar_wrapper_id'] = $calendar_wrapper_id;
    if (!empty($exposedFilterOptions['show_category_filter'])) {
      $options = $this->viewsBasicManager->getTaxonomyParents('event_category');
      // Remove the 'All Items' option.
      unset($options['']);
      $form['category_included_terms'] = [
        '#type' => 'select',
        '#title' => $this->t('Filter by Event Category'),
        '#options' => $options,
        '#default_value' => [],
        '#multiple' => TRUE,
        '#attributes' => [
          'class' => ['chosen-enable'],
        ],
        '#ajax' => [
          'callback' => '::ajaxFilterCallback',
          'wrapper' => $form_wrapper_id,
          'event' => 'change',
        ],
      ];
    }
    if (!empty($exposedFilterOptions['show_audience_filter'])) {
      $options = $this->viewsBasicManager->getTaxonomyParents('audience');
      unset($options['']);
      $form['audience_included_terms'] = [
        '#type' => 'select',
        '#title' => $this->t('Filter by Audience'),
        '#options' => $options,
        '#default_value' => [],
        '#ajax' => [
          'callback' => '::ajaxFilterCallback',
          'wrapper' => $form_wrapper_id,
          'event' => 'change',
        ],
      ];
    }
    if (!empty($exposedFilterOptions['show_custom_vocab_filter'])) {
      $custom_vocab_label = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab')->label();
      $options = $this->viewsBasicManager->getTaxonomyParents('custom_vocab');
      unset($options['']);
      $form['custom_vocab_included_terms'] = [
        '#type' => 'select',
        '#title' => $this->t('Filter by @vocab Parent Term', ['@vocab' => $custom_vocab_label]),
        '#options' => $options,
        '#default_value' => [],
        '#ajax' => [
          'callback' => '::ajaxFilterCallback',
          'wrapper' => $form_wrapper_id,
          'event' => 'change',
        ],
      ];
    }
    // Always add hidden fields for these options, using values from $paramsDecoded.
    $form['terms_include'] = [
      '#type' => 'hidden',
      '#value' => $paramsDecoded['terms_include'] ?? [],
    ];
    $form['terms_exclude'] = [
      '#type' => 'hidden',
      '#value' => $paramsDecoded['terms_exclude'] ?? [],
    ];
    $form['term_operator'] = [
      '#type' => 'hidden',
      '#value' => $paramsDecoded['term_operator'] ?? '+',
    ];
    // Remove terms_include, terms_exclude, and term_operator fields from the form.
    // (No code for these fields should be present.)
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#ajax' => [
        'callback' => '::ajaxFilterCallback',
        'wrapper' => $form_wrapper_id,
      ],
    ];

    // Get the current month/year for initial calendar rendering.
    $month = date('m');
    $year = date('Y');

    // Get the EventsCalendar service.
    $events_calendar_service = \Drupal::service('ys_views_basic.events_calendar');
    $events_calendar = $events_calendar_service->getCalendar($month, $year);

    // Render the calendar initially.
    $form['calendar'] = [
      '#theme' => 'views_basic_events_calendar',
      '#month_data' => $events_calendar,
      '#cache' => [
        'tags' => ['node_list:event'],
        'contexts' => ['timezone'],
      ],
    ];

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
    // Get selected filter values from the form state.
    $category = $form_state->getValue('category_included_terms');
    $audience = $form_state->getValue('audience_included_terms');
    $custom_vocab = $form_state->getValue('custom_vocab_included_terms');
    $terms_include = $form_state->getValue('terms_include');
    $terms_exclude = $form_state->getValue('terms_exclude');
    $term_operator = $form_state->getValue('term_operator');

    // Get the current month/year (could be extended to allow navigation).
    $month = date('m');
    $year = date('Y');

    // Get the EventsCalendar service.
    $events_calendar_service = \Drupal::service('ys_views_basic.events_calendar');

    // Prepare filters array.
    $filters = [
      'category_included_terms' => $category,
      'audience_included_terms' => $audience,
      'custom_vocab_included_terms' => $custom_vocab,
      'terms_include' => $terms_include,
      'terms_exclude' => $terms_exclude,
      'term_operator' => $term_operator,
    ];

    // Pass filters to the EventsCalendar service.
    $events_calendar = $events_calendar_service->getCalendar($month, $year, $filters);

    // Update the calendar in the form with filtered data.
    $form['calendar'] = [
      '#theme' => 'views_basic_events_calendar',
      '#month_data' => $events_calendar,
      '#cache' => [
        'tags' => ['node_list:event'],
        'contexts' => ['timezone'],
      ],
    ];

    // Return the updated form.
    return $form;
  }

}
