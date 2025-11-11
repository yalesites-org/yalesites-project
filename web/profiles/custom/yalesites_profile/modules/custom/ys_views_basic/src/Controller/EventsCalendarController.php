<?php

declare(strict_types=1);

namespace Drupal\ys_views_basic\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns an Ajax response containing calendar data for the given month.
 */
final class EventsCalendarController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private EventsCalendarInterface $eventsCalendar,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ys_views_basic.events_calendar'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request): AjaxResponse {
    $response = new AjaxResponse();

    $calendar_id = $request->request->get('calendar_id');
    $month = $request->request->get('month') ?? date('m');
    $year = $request->request->get('year') ?? date('Y');
    // If present, prefer explicit calendar_month/year from the form state.
    $month = $request->request->get('calendar_month', $month);
    $year = $request->request->get('calendar_year', $year);
    $category_included_terms = $request->request->get('category_included_terms');
    $audience_included_terms = $request->request->get('audience_included_terms');
    $custom_vocab_included_terms = $request->request->get('custom_vocab_included_terms');
    $terms_include = $request->request->get('terms_include');
    $terms_exclude = $request->request->get('terms_exclude');
    $term_operator = $request->request->get('term_operator');
    $search = $request->request->get('search');

    $category_included_terms = $this->decodeArray($category_included_terms);
    $audience_included_terms = $this->decodeArray($audience_included_terms);
    $custom_vocab_included_terms = $this->decodeArray($custom_vocab_included_terms);

    // Prepare filter array to be implemented in EventsCalendar service.
    $filters = [
      'category_included_terms' => $category_included_terms,
      'audience_included_terms' => $audience_included_terms,
      'custom_vocab_included_terms' => $custom_vocab_included_terms,
      'terms_include' => $terms_include,
      'terms_exclude' => $terms_exclude,
      'term_operator' => $term_operator,
      'search' => $search,
    ];

    // Get filtered calendar (service must be updated to support filters).
    $events_calendar = $this->eventsCalendar->getCalendar($month, $year, $filters);

    $calendar = [
      '#theme' => 'views_basic_events_calendar',
      '#month_data' => $events_calendar,
      '#cache' => [
        'tags' => ['node_list:event'],
      ],
    ];

    // Wrap with a container that preserves the original wrapper ID so
    // subsequent AJAX updates continue to target the same element.
    if ($calendar_id) {
      $wrapper_id = ltrim((string) $calendar_id, '#');
      $calendar_wrapper = [
        '#type' => 'container',
        '#attributes' => ['id' => $wrapper_id],
        '#cache' => [
          'tags' => ['node_list:event'],
        ],
        'calendar_content' => $calendar,
      ];
      $response->addCommand(new ReplaceCommand($calendar_id, $calendar_wrapper));
    }

    return $response;
  }

  /**
   * Decodes a JSON-encoded array value if applicable.
   *
   * Accepts mixed input (string or array). If a JSON string is provided and
   * decodes cleanly to an array, returns that array. Empty strings return an
   * empty array. Non-array inputs are returned as-is to preserve type.
   *
   * @param mixed $value
   *   The incoming value from the request.
   *
   * @return mixed
   *   The decoded array if applicable, otherwise the original value.
   */
  private function decodeArray($value) {
    if (is_string($value)) {
      $trimmed = trim($value);
      if ($trimmed === '') {
        return [];
      }
      if ($trimmed[0] === '[' || $trimmed[0] === '{') {
        $decoded = json_decode($trimmed, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          return $decoded;
        }
      }
    }
    return $value;
  }

}
