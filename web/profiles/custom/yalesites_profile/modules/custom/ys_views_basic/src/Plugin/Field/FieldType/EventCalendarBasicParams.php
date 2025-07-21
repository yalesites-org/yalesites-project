<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldType;

/**
 * Plugin implementation of the 'event_calendar_basic_params' field type.
 *
 * @FieldType(
 *   id = "event_calendar_basic_params",
 *   label = @Translation("Event Calendar Basic Params"),
 *   description = @Translation("Stores parameters to pass to Event Calendar"),
 *   category = @Translation("Custom"),
 *   default_widget = "event_calendar_default_widget",
 *   default_formatter = "event_calendar_default_formatter"
 * )
 */
class EventCalendarBasicParams extends ViewsBasicParams {

}
