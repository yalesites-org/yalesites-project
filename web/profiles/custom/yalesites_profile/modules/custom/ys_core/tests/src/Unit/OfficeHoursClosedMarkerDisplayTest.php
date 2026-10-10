<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests that the theme turns the widget's Closed marker into a closed day.
 *
 * The Closed control in ys_core's js/office-hours-widget.js stores a reserved
 * value in the day's comment column, because a weekday with no hours and no
 * comment is not stored at all. Atomic's _atomic_office_hours_day() is the
 * display side of that contract: it must render the day as Closed with no
 * note, and never print the raw marker. Anything else in the comment is the
 * editor's own note, except that a closed day does not repeat "Closed" as a
 * note under its Hours cell.
 *
 * The function lives in the Atomic theme, which has no test suite of its own,
 * so it is loaded from the installed theme here.
 *
 * @group ys_core
 */
class OfficeHoursClosedMarkerDisplayTest extends UnitTestCase {

  /**
   * The marker the widget writes. Must match CLOSED_MARKER in the JS.
   */
  const MARKER = '__ys_office_hours_closed__';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $theme = $this->root . '/themes/contrib/atomic/atomic.theme';
    if (!is_file($theme)) {
      $this->markTestSkipped('The Atomic theme is not installed.');
    }
    require_once $theme;
  }

  /**
   * Builds one stored time slot.
   */
  protected function slot(?int $start, ?int $end, string $comment = '', bool $all_day = FALSE): object {
    return (object) [
      'starthours' => $start,
      'endhours' => $end,
      'comment' => $comment,
      'all_day' => $all_day,
    ];
  }

  /**
   * A day closed by the control is closed, with no note.
   */
  public function testMarkerRendersAsClosedWithNoNote(): void {
    $day = _atomic_office_hours_day([$this->slot(NULL, NULL, self::MARKER)]);
    $this->assertTrue($day['closed']);
    $this->assertSame('', $day['comment']);
  }

  /**
   * The raw marker never reaches the page, even beside hours or a note.
   */
  public function testMarkerIsNeverPrinted(): void {
    $day = _atomic_office_hours_day([
      $this->slot(900, 1700, self::MARKER),
      $this->slot(NULL, NULL, 'By appointment'),
    ]);
    $this->assertFalse($day['closed']);
    $this->assertSame('By appointment', $day['comment']);
  }

  /**
   * A typed "Closed" is not repeated under a closed day's Hours cell.
   *
   * Display only: the stored note is untouched. Beside real hours it is a
   * note like any other and is shown.
   */
  public function testTypedClosedIsNotRepeatedWhenClosed(): void {
    $day = _atomic_office_hours_day([$this->slot(NULL, NULL, 'closed')]);
    $this->assertTrue($day['closed']);
    $this->assertSame('', $day['comment']);

    $day = _atomic_office_hours_day([$this->slot(900, 1200, 'Closed')]);
    $this->assertFalse($day['closed']);
    $this->assertSame('Closed', $day['comment']);
  }

}
