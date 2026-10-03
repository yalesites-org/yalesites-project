<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Controller\EventsCalendarController;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for the EventsCalendarController.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Controller\EventsCalendarController
 * @group ys_views_basic
 */
class EventsCalendarControllerTest extends UnitTestCase {

  /**
   * Month navigation must forward the configured parent terms to the service.
   *
   * @covers ::__invoke
   */
  public function testParentTermsAreDecodedAndPassedToService() {
    $calendar = $this->createMock(EventsCalendarInterface::class);
    $calendar->expects($this->once())
      ->method('getCalendar')
      ->with('06', '2024', $this->callback(
        fn(array $filters) => ($filters['parent_terms'] ?? NULL) == ['event_category' => '580']
      ))
      ->willReturn([]);

    $controller = new EventsCalendarController($calendar);
    $controller(Request::create('/events-calendar', 'POST', [
      'month' => '06',
      'year' => '2024',
      'parent_terms' => '{"event_category":"580"}',
    ]));
  }

}
