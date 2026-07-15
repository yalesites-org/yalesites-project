<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Service\EventsCalendar;

/**
 * Tests the month-grid date math in the events calendar service.
 *
 * Regression coverage for the bug (yalesites-org/YaleSites-Internal#1379) where
 * the trailing overflow cells in the last row of a month grid were tagged with
 * the wrong month. The next month was derived from the last day of the current
 * month via DrupalDateTime::modify('+1 month'), which overflows for any 31-day
 * month whose successor is shorter (e.g. 2026-08-31 + 1 month lands on
 * 2026-10-01, not September). Those cells then rendered next-next-month dates
 * and surfaced events from the wrong day. The next month must instead be
 * derived from the first of the month, which is safe in every month.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Service\EventsCalendar
 *
 * @group ys_views_basic
 */
class EventsCalendarTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // DrupalDateTime's constructor reads the current language for its default
    // langcode, so a minimal container with a language manager is required.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $container = new ContainerBuilder();
    $container->set('language_manager', $languageManager);
    \Drupal::setContainer($container);
  }

  /**
   * Trailing overflow cells belong to the month after the viewed one.
   *
   * @covers ::getCalendar
   *
   * @dataProvider trailingOverflowProvider
   */
  public function testTrailingOverflowMonth(string $month, string $year, string $expectedMonth, string $expectedYear): void {
    $calendar = $this->buildService()->getCalendar($month, $year);
    $lastRow = end($calendar);

    // Trailing overflow cells are the last-row cells that fall outside the
    // viewed month.
    $overflow = array_values(array_filter(
      $lastRow,
      fn(array $cell): bool => $cell['date']['month'] !== $month
    ));

    // Independently derive how many trailing overflow cells this month should
    // have (0 when the month ends on a Saturday) using a plain DateTime, so the
    // assertions hold for any month instead of depending on the fixture only
    // listing months whose last day is not a Saturday.
    $lastWeekday = (int) (new \DateTime("$year-$month-01"))
      ->modify('last day of this month')
      ->format('w');
    $this->assertCount(
      6 - $lastWeekday,
      $overflow,
      "Unexpected number of trailing overflow cells for $month/$year."
    );

    foreach ($overflow as $cell) {
      $this->assertSame(
        $expectedMonth,
        $cell['date']['month'],
        "Trailing overflow cell for $month/$year should belong to month $expectedMonth, got {$cell['date']['month']}."
      );
      $this->assertSame(
        $expectedYear,
        $cell['date']['year'],
        "Trailing overflow cell for $month/$year should belong to year $expectedYear, got {$cell['date']['year']}."
      );
    }
  }

  /**
   * Provides viewed months and the month/year the trailing overflow belongs to.
   *
   * @return array
   *   Each case: [viewed month, viewed year, expected overflow month, year].
   */
  public static function trailingOverflowProvider(): array {
    return [
      // Regression cases: 31-day month whose successor is shorter. Before the
      // fix these overflowed two months ahead.
      'August -> September' => ['08', '2026', '09', '2026'],
      'January -> February' => ['01', '2025', '02', '2025'],
      'March -> April' => ['03', '2026', '04', '2026'],
      'May -> June' => ['05', '2026', '06', '2026'],
      'October -> November' => ['10', '2025', '11', '2025'],

      // Controls that must stay correct after the fix.
      // December rolls the year over.
      'December -> January (year rollover)' => ['12', '2026', '01', '2027'],
      // July's successor (August) also has 31 days: never overflowed.
      'July -> August' => ['07', '2026', '08', '2026'],
      // February has fewer than 31 days: never overflowed.
      'February -> March' => ['02', '2025', '03', '2025'],
    ];
  }

  /**
   * Builds the service with storage mocked to return no events.
   *
   * The date-grid math is independent of the loaded events, so the node query
   * is stubbed to return nothing. This keeps the test a pure unit test with no
   * database or container bootstrap.
   */
  private function buildService(): EventsCalendar {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($query);
    $nodeStorage->method('loadMultiple')->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($nodeStorage);

    $aliasManager = $this->createMock(AliasManagerInterface::class);

    return new EventsCalendar($entityTypeManager, $aliasManager);
  }

}
