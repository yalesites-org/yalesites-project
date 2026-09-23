<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'event_calendar_basic_params' field type.
 */
#[FieldType(
  id: 'event_calendar_basic_params',
  label: new TranslatableMarkup('Event Calendar Basic Params'),
  description: new TranslatableMarkup('Stores parameters to pass to Event Calendar'),
  default_widget: 'event_calendar_default_widget',
  default_formatter: 'event_calendar_default_formatter',
)]
class EventCalendarBasicParams extends ViewsBasicParams {

}
