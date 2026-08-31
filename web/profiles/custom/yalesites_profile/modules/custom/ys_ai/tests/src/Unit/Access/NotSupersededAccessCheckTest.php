<?php

namespace Drupal\Tests\ys_ai\Unit\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Access\NotSupersededAccessCheck;
use Drupal\ys_ai\BeaconSupersession;

/**
 * Unit tests for NotSupersededAccessCheck.
 *
 * Applied additively to every ys_ai-owned route, so a bookmarked URL cannot
 * reach a form whose menu link has been hidden. The check answers only the
 * supersession question; the permission and configuration checks already on
 * those routes continue to apply alongside it.
 *
 * @coversDefaultClass \Drupal\ys_ai\Access\NotSupersededAccessCheck
 *
 * @group ys_ai
 * @group yalesites
 */
class NotSupersededAccessCheckTest extends UnitTestCase {

  /**
   * Builds the access check over a supersession service in a given state.
   */
  protected function createAccessCheck(bool $superseded): NotSupersededAccessCheck {
    $supersession = $this->createMock(BeaconSupersession::class);
    $supersession->method('isSuperseded')->willReturn($superseded);
    $supersession->method('getCacheTags')->willReturn(['config:ys_beacon.settings']);

    return new NotSupersededAccessCheck($supersession);
  }

  /**
   * Access is forbidden once Beacon supersedes the legacy AI surfaces.
   *
   * Forbidden rather than neutral, so no other access check on the route can
   * grant what this one denies.
   *
   * @covers ::access
   */
  public function testAccessForbiddenWhenSuperseded(): void {
    $result = $this->createAccessCheck(TRUE)->access($this->createMock(AccountInterface::class));

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Access is allowed while the legacy surfaces are not superseded.
   *
   * Allowed rather than neutral: Drupal requires every route requirement to
   * return allowed, so a neutral result here would deny the route outright.
   *
   * @covers ::access
   */
  public function testAccessAllowedWhenNotSuperseded(): void {
    $result = $this->createAccessCheck(FALSE)->access($this->createMock(AccountInterface::class));

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Both outcomes depend on the Beacon settings cache tag.
   *
   * @covers ::access
   */
  public function testAccessResultsCarryBeaconSettingsCacheTag(): void {
    foreach ([TRUE, FALSE] as $superseded) {
      $result = $this->createAccessCheck($superseded)->access($this->createMock(AccountInterface::class));

      $this->assertSame(['config:ys_beacon.settings'], $result->getCacheTags());
    }
  }

}
