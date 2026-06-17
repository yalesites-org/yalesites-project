<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_localist\MetaFieldsManager;

/**
 * Tests the event registration-CTA suppression helper (#953).
 *
 * @coversDefaultClass \Drupal\ys_localist\MetaFieldsManager
 *
 * @group yalesites
 */
class RegisterSuppressionTest extends UnitTestCase {

  /**
   * When not hidden, the register flag and ticket link pass through unchanged.
   *
   * @covers ::applyRegisterSuppression
   */
  public function testPassthroughWhenNotHidden() {
    $this->assertSame(
      [TRUE, 'https://example.com/rsvp'],
      MetaFieldsManager::applyRegisterSuppression(FALSE, TRUE, 'https://example.com/rsvp')
    );
    $this->assertSame(
      [FALSE, NULL],
      MetaFieldsManager::applyRegisterSuppression(FALSE, FALSE, NULL)
    );
  }

  /**
   * When hidden, both the register flag and the ticket link are cleared.
   *
   * @covers ::applyRegisterSuppression
   */
  public function testSuppressedWhenHidden() {
    // Even with a register flag and an always-present rsvp link, hiding wins.
    $this->assertSame(
      [FALSE, NULL],
      MetaFieldsManager::applyRegisterSuppression(TRUE, TRUE, 'https://example.com/rsvp')
    );
  }

}
