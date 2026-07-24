<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Access\BeaconAuthorizedAccessCheck;
use Drupal\ys_beacon\BeaconAuthorization;

/**
 * Tests the Beacon authorization access check.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Access\BeaconAuthorizedAccessCheck
 */
class BeaconAuthorizedAccessCheckTest extends UnitTestCase {

  /**
   * Builds the access check with an authorization service stubbed to $value.
   */
  private function check(bool $value): BeaconAuthorizedAccessCheck {
    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($value);
    return new BeaconAuthorizedAccessCheck($authorization);
  }

  /**
   * Access is allowed when Beacon is authorized, and tags the settings config.
   *
   * @covers ::access
   */
  public function testAllowedWhenAuthorized(): void {
    $result = $this->check(TRUE)->access($this->createMock(AccountInterface::class));
    $this->assertTrue($result->isAllowed());
    $this->assertContains('config:ys_beacon.settings', $result->getCacheTags());
  }

  /**
   * Access is forbidden when Beacon is not authorized.
   *
   * @covers ::access
   */
  public function testForbiddenWhenNotAuthorized(): void {
    $result = $this->check(FALSE)->access($this->createMock(AccountInterface::class));
    $this->assertTrue($result->isForbidden());
    $this->assertContains('config:ys_beacon.settings', $result->getCacheTags());
  }

}
