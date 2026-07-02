<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Event listing widget.
 *
 * Serves the event_card, event_list_item, and event_condensed bundles. The
 * calendar display mode keeps its own existing event_calendar bundle and
 * EventCalendarDefaultWidget and is out of scope here. The display mode is
 * fixed by the bundle (ADR DR-2), so this widget only adds the event-specific
 * controls: the "Hide Add to Calendar link" option and the event time period
 * (future / past / all). Events are queried through the events scaffold view
 * (with distinct de-duplication and a manual date sort) which
 * ViewsBasicManager selects from the stored content type — ADR DR-5.
 *
 * @FieldWidget(
 *   id = "event_view_widget",
 *   label = @Translation("Event listing widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class EventViewWidget extends ViewsBasicWidgetBase {

  /**
   * Event time period options.
   */
  const TIME_PERIOD_FUTURE = 'future';
  const TIME_PERIOD_PAST = 'past';
  const TIME_PERIOD_ALL = 'all';

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_EVENT;
  }

  /**
   * {@inheritdoc}
   *
   * Adds the event-only "Hide Add to Calendar link" option and the event time
   * period selector. No #states are needed because this widget only ever
   * serves events.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    $params = $items[$delta]->params;

    $form['group_user_selection']['entity_and_view_mode']['event_field_options'] = [
      '#type' => 'checkboxes',
      '#options' => [
        'hide_add_to_calendar' => $this->t('Hide Add to Calendar link'),
      ],
      '#title' => $this->t('Event Field Display Options'),
      '#tree' => TRUE,
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('event_field_options', $params) : [],
    ];

    $icon_base = '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/';
    $form['group_user_selection']['entity_specific']['event_time_period'] = [
      '#type' => 'radios',
      '#title' => $this->t('Event Time Period'),
      '#options' => [
        self::TIME_PERIOD_FUTURE => $this->t('Future Events') . '<img src="' . $icon_base . 'event-time-future.svg" alt="Future Events icon showing a calendar with a future-pointing arrow to the right.">',
        self::TIME_PERIOD_PAST => $this->t('Past Events') . '<img src="' . $icon_base . 'event-time-past.svg" alt="Past Events icon showing a calendar with a past-pointing arrow to the left.">',
        self::TIME_PERIOD_ALL => $this->t('All Events') . '<img src="' . $icon_base . 'event-time-all.svg" alt="All Events icon showing a calendar.">',
      ],
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('event_time_period', $params) : self::TIME_PERIOD_FUTURE,
      '#prefix' => '<div id="edit-event-time-period">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function massageEntitySpecificParams(array &$paramData, array $form, FormStateInterface $form_state): void {
    $selection = $form['group_user_selection'];
    $paramData['event_field_options'] = $selection['entity_and_view_mode']['event_field_options']['#value'];
    $paramData['filters']['event_time_period'] = $selection['entity_specific']['event_time_period']['#value'];
  }

}
