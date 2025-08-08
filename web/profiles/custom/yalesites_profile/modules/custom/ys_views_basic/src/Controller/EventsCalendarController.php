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
    $category_included_terms = $request->request->get('category_included_terms');
    $audience_included_terms = $request->request->get('audience_included_terms');
    $custom_vocab_included_terms = $request->request->get('custom_vocab_included_terms');
    $terms_include = $request->request->get('terms_include');
    $terms_exclude = $request->request->get('terms_exclude');
    $term_operator = $request->request->get('term_operator');

    // Prepare filter array to be implemented in EventsCalendar service.
    $filters = [
      'category_included_terms' => $category_included_terms,
      'audience_included_terms' => $audience_included_terms,
      'custom_vocab_included_terms' => $custom_vocab_included_terms,
      'terms_include' => $terms_include,
      'terms_exclude' => $terms_exclude,
      'term_operator' => $term_operator,
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

    if ($calendar_id) {
      $response->addCommand(new ReplaceCommand($calendar_id, $calendar));
    }

    return $response;
  }

}
