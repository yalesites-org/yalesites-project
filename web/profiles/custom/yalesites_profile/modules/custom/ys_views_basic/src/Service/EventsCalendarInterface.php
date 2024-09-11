<?php

namespace Drupal\ys_views_basic\Service;

use Drupal\node\NodeInterface;

/**
 * Provides an interface for the EventCalendar.
 */
interface EventsCalendarInterface {

  /**
   * Prepares events calendar for a given month and year.
   *
   * @param string $month
   *   The month as a two-digit string (e.g., '06').
   * @param string $year
   *   The year as a four-digit string (e.g., 'yyyy').
   *
   * @return array
   *   A two-dimensional array representing the calendar grid.
   */
  public function getCalendar(string $month, string $year): array;

  /**
   * Creates a calendar cell array for a given day.
   *
   * @param int $day
   *   The day of the month for the calendar cell.
   * @param string $month
   *   The month of the calendar cell in 'mm' format.
   * @param string $year
   *   The year of the calendar cell in 'yyyy' format.
   * @param array $events
   *   The node events.
   *
   * @return array
   *   The calendar cell array.
   */
  public function createCalendarCell(int $day, string $month, string $year, array $events): array;

  /**
   * Retrieves events for a specific day.
   *
   * @param int $day
   *   The day of the month.
   * @param string $month
   *   The month in numeric format (e.g., "01" for January).
   * @param string $year
   *   The year in four-digit format (e.g., "2024").
   * @param \Drupal\node\NodeInterface[] $events
   *   The node events.
   *
   * @return array
   *   The events list.
   */
  public function getEvents(int $day, string $month, string $year, array $events): array;

  /**
   * Creates an array representing an event with relevant details.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node from which to extract information.
   * @param string $time
   *   The formatted time of the event.
   * @param int $timestamp
   *   The timestamp of the event occurrence.
   *
   * @return array
   *   An associative array containing details of the event
   */
  public function createEventArray(NodeInterface $node, string $time, int $timestamp): array;

  /**
   * Determines if an event is set to all day.
   *
   * @param int $start_ts
   *   Start timestamp.
   * @param int $end_ts
   *   End timestamp.
   * @param string|null $timezone
   *   Optional timezone.
   *
   * @return bool
   *   TRUE if the event is all day, FALSE otherwise.
   */
  public function isAllDay(int $start_ts, int $end_ts, string $timezone = NULL): bool;

  /**
   * Get monthly events nodes.
   *
   * @param string $month
   *   The month for which to fetch events.
   * @param string $year
   *   The year for which to fetch events.
   *
   * @return \Drupal\node\NodeInterface[]
   *   An array of event nodes.
   */
  public function loadMonthlyEvents(string $month, string $year): array;

}
