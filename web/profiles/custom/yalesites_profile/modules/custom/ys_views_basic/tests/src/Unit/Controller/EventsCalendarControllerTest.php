<?php

namespace Drupal\Tests\ys_views_basic\Unit\Controller;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Controller\EventsCalendarController;
use Drupal\ys_views_basic\Service\EventsCalendar;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that month navigation announces the displayed month.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Controller\EventsCalendarController
 * @group ys_views_basic
 * @group yalesites
 */
class EventsCalendarControllerTest extends UnitTestCase {

  /**
   * Builds the controller around a stubbed calendar service.
   */
  protected function buildController(): EventsCalendarController {
    $calendar = $this->createMock(EventsCalendar::class);
    $calendar->method('getCalendar')->willReturn([]);
    $controller = new EventsCalendarController($calendar);
    $controller->setStringTranslation($this->getStringTranslationStub());
    return $controller;
  }

  /**
   * Returns the polite announce command data from a response.
   */
  protected function announcement(Request $request): ?array {
    $commands = ($this->buildController())($request)->getCommands();
    foreach ($commands as $command) {
      if ($command['command'] === 'announce') {
        return $command;
      }
    }
    return NULL;
  }

  /**
   * Next and previous month navigation name the new month.
   *
   * @covers ::__invoke
   * @dataProvider monthProvider
   */
  public function testAnnouncesNewMonth(string $month, string $year, string $expected): void {
    $request = Request::create('/', 'POST', [
      'calendar_month' => $month,
      'calendar_year' => $year,
    ]);
    $command = $this->announcement($request);
    $this->assertNotNull($command, 'Month navigation sends an announce command.');
    $this->assertSame($expected, $command['text']);
    $this->assertSame('polite', $command['priority']);
  }

  /**
   * Months to navigate to, including a year rollover.
   */
  public static function monthProvider(): array {
    return [
      'next month' => ['12', '2026', 'Showing December 2026.'],
      'rollover to January' => ['1', '2027', 'Showing January 2027.'],
      'previous month' => ['10', '2026', 'Showing October 2026.'],
    ];
  }

  /**
   * An out-of-range month falls back to the current month, not an error.
   *
   * @covers ::__invoke
   */
  public function testInvalidMonthFallsBack(): void {
    $request = Request::create('/', 'POST', [
      'calendar_month' => '99',
      'calendar_year' => '2026',
    ]);
    $command = $this->announcement($request);
    $this->assertSame('Showing ' . date('F Y') . '.', $command['text']);
  }

}
