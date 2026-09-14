<?php

namespace Drupal\ys_content_export;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the columns and rows for a content-list CSV export.
 *
 * Kept free of injected services so its column mapping and cell sanitisation
 * can be unit tested directly; row building reads only node methods.
 */
class ContentExportBuilder {

  /**
   * Taxonomy columns shared by every content type.
   *
   * Keyed by field machine name; the value is the column header.
   */
  const SHARED_TAXONOMY = [
    'field_tags' => 'Tags',
    'field_audience' => 'Audience',
    'field_custom_vocab' => 'Custom Vocab',
  ];

  /**
   * Taxonomy columns specific to each content type.
   *
   * `field_category` is one field whose label differs per bundle; profiles use
   * `field_affiliation` instead and have no category.
   */
  const BUNDLE_TAXONOMY = [
    'page' => ['field_category' => 'Category'],
    'post' => ['field_category' => 'Category'],
    'event' => ['field_category' => 'Event Category'],
    'resource' => ['field_category' => 'Resource Category'],
    'profile' => ['field_affiliation' => 'Affiliation'],
  ];

  /**
   * The date column specific to each content type.
   *
   * Keyed by bundle, then by field machine name, with the column header as the
   * value. Headers match the wording editors already see on the matching Manage
   * screen. Bundles absent from this map export no date column.
   */
  const BUNDLE_DATE = [
    'event' => ['field_event_date' => 'Dates'],
    'resource' => ['field_publish_date' => 'Resource Publication Date'],
  ];

  /**
   * Returns the ordered export columns for a content type.
   *
   * @param string $bundle
   *   The node bundle machine name.
   *
   * @return array
   *   Ordered map of column key (a node field name, or one of the pseudo keys
   *   title/url/published/cas_protected) to its header label.
   */
  public static function getColumns(string $bundle): array {
    // The date column sits immediately after Title so the file reads in roughly
    // the same order as the on-screen Manage table it was exported from.
    $columns = ['title' => 'Title'];
    $columns += self::BUNDLE_DATE[$bundle] ?? [];
    $columns += [
      'url' => 'URL',
      'published' => 'Published',
      'cas_protected' => 'CAS Protected',
    ];
    $columns += self::SHARED_TAXONOMY;
    $columns += self::BUNDLE_TAXONOMY[$bundle] ?? [];
    return $columns;
  }

  /**
   * Builds one sanitised CSV row for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to export.
   * @param string $bundle
   *   The node bundle machine name.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter, used for the event date column. Passed in rather than
   *   injected so this class stays service-free and directly unit testable.
   *
   * @return array
   *   The row values, in the same order as getColumns(), each passed through
   *   sanitizeCell().
   */
  public static function getRow(NodeInterface $node, string $bundle, DateFormatterInterface $date_formatter): array {
    $row = [];
    foreach (array_keys(self::getColumns($bundle)) as $key) {
      $row[] = self::sanitizeCell(self::cellValue($node, $key, $date_formatter));
    }
    return $row;
  }

  /**
   * Resolves the raw value for a single column of a node.
   */
  protected static function cellValue(NodeInterface $node, string $key, DateFormatterInterface $date_formatter): string {
    switch ($key) {
      case 'title':
        return (string) $node->label();

      case 'url':
        return $node->toUrl()->toString();

      case 'published':
        return $node->isPublished() ? 'Yes' : 'No';

      case 'cas_protected':
        return $node->hasField('field_login_required') && $node->get('field_login_required')->value
          ? 'Yes' : 'No';

      case 'field_event_date':
        return self::eventDates($node, $date_formatter);

      case 'field_publish_date':
        return self::publishDate($node);

      default:
        // Any remaining column is assumed to be an entity reference, so a new
        // non-reference field needs an explicit case above rather than relying
        // on this branch.
        if (!$node->hasField($key)) {
          return '';
        }
        $names = [];
        foreach ($node->get($key)->referencedEntities() as $term) {
          $names[] = $term->label();
        }
        return implode(', ', $names);
    }
  }

  /**
   * Lists every instance of an event's date field, oldest first.
   *
   * Smart Date stores each occurrence as its own field item, and stored delta
   * order is not chronological, so the instances are sorted before rendering.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   *
   * @return string
   *   Every instance, comma separated, or an empty string when the event has
   *   no dates.
   */
  protected static function eventDates(NodeInterface $node, DateFormatterInterface $date_formatter): string {
    if (!$node->hasField('field_event_date')) {
      return '';
    }

    $instances = [];
    foreach ($node->get('field_event_date') as $item) {
      $start = (int) $item->value;
      if (!$start) {
        continue;
      }
      // A Smart Date item always stores an end, but fall back to the start so a
      // partially written value still exports as a point in time.
      $instances[] = [$start, (int) $item->end_value ?: $start];
    }
    usort($instances, fn(array $a, array $b) => $a <=> $b);

    $rendered = [];
    foreach ($instances as [$start, $end]) {
      $rendered[] = self::eventInstance($start, $end, $date_formatter);
    }
    return implode(', ', $rendered);
  }

  /**
   * Renders one event occurrence in the site's configured timezone.
   *
   * Uses the platform's existing event_date_only and event_time_only date
   * formats rather than a new pattern. The end date is only repeated when the
   * occurrence crosses midnight, and an all-day occurrence is labelled instead
   * of being shown as a 12:00 am to 11:59 pm range.
   *
   * @param int $start
   *   The occurrence start, as a UNIX timestamp.
   * @param int $end
   *   The occurrence end, as a UNIX timestamp.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   *
   * @return string
   *   The rendered occurrence.
   */
  protected static function eventInstance(int $start, int $end, DateFormatterInterface $date_formatter): string {
    $start_date = $date_formatter->format($start, 'event_date_only');
    $end_date = $date_formatter->format($end, 'event_date_only');
    $spans_days = $end_date !== $start_date;

    if (self::isAllDay($start, $end, $date_formatter)) {
      return $spans_days
        ? $start_date . ' - ' . $end_date . ' (All day)'
        : $start_date . ' (All day)';
    }

    $start_time = $date_formatter->format($start, 'event_time_only');
    $end_time = $date_formatter->format($end, 'event_time_only');
    // A zero-duration occurrence is a point in time, not a range.
    if (!$spans_days && $start_time === $end_time) {
      return $start_date . ' ' . $start_time;
    }
    // The end date is only repeated when the occurrence crosses midnight.
    $end_label = $spans_days ? $end_date . ' ' . $end_time : $end_time;
    return $start_date . ' ' . $start_time . ' - ' . $end_label;
  }

  /**
   * Determines whether an occurrence covers whole days.
   *
   * Mirrors how Smart Date itself defines all-day (see
   * SmartDateTrait::isAllDay): the occurrence starts at midnight and ends a
   * minute before it. Compared through the date formatter so the clock times
   * are read in the site's timezone. The 'custom' format type is what lets the
   * formatter use the pattern directly; a named type would make it load a date
   * format config entity on every call.
   *
   * @param int $start
   *   The occurrence start, as a UNIX timestamp.
   * @param int $end
   *   The occurrence end, as a UNIX timestamp.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   *
   * @return bool
   *   TRUE when the occurrence is all-day.
   */
  protected static function isAllDay(int $start, int $end, DateFormatterInterface $date_formatter): bool {
    return $date_formatter->format($start, 'custom', 'H:i') === '00:00'
      && $date_formatter->format($end, 'custom', 'H:i') === '23:59';
  }

  /**
   * Returns a resource's publication date.
   *
   * The field is date-only, stored as Y-m-d, which is already the format the
   * Manage Resources column renders. The stored value is emitted verbatim so
   * there is no timestamp round-trip whose only possible effect would be
   * shifting the date by a day across a timezone boundary.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The resource node.
   *
   * @return string
   *   The publication date, or an empty string when unset.
   */
  protected static function publishDate(NodeInterface $node): string {
    if (!$node->hasField('field_publish_date')) {
      return '';
    }
    return (string) $node->get('field_publish_date')->value;
  }

  /**
   * Neutralises CSV formula injection (CWE-1236).
   *
   * Spreadsheet apps treat a cell beginning with =, +, -, @, tab or carriage
   * return as a formula. Prefixing such a value with a single quote forces it
   * to be read as text.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string
   *   The value, safe to write to a CSV cell.
   */
  public static function sanitizeCell(string $value): string {
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
      return "'" . $value;
    }
    return $value;
  }

}
