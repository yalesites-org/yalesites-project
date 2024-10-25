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

    if (!$request->request->has('calendar_id') || !$request->request->has('month') || !$request->request->has('year')) {
      return $response;
    }

    // Calendar wrapper that needs to be updated.
    $calendar_id = $request->request->get('calendar_id');
    $month = $request->request->get('month');
    $year = $request->request->get('year');

    $events_calendar = $this->eventsCalendar
      ->getCalendar($month, $year);

    $calendar = [
      '#theme' => 'views_basic_events_calendar',
      '#month_data' => $events_calendar,
      '#cache' => [
        'tags' => ['node_list:event'],
      ],
    ];

    $response->addCommand(new ReplaceCommand($calendar_id, $calendar));

    return $response;
  }

}
