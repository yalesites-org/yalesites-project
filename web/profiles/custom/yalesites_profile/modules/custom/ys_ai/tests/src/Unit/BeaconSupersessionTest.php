<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\BeaconSupersession;
use Drupal\ys_beacon\BeaconAuthorization;

/**
 * Unit tests for BeaconSupersession.
 *
 * The legacy YaleSites AI surfaces are superseded once Beacon is
 * platform-authorized for the site. ys_beacon is an optional dependency: the
 * authorization service is injected with an optional container reference, so
 * on a site without Beacon the constructor argument is NULL and nothing is
 * superseded.
 *
 * @coversDefaultClass \Drupal\ys_ai\BeaconSupersession
 *
 * @group ys_ai
 * @group yalesites
 */
class BeaconSupersessionTest extends UnitTestCase {

  /**
   * Builds the service with an authorization service in a given state.
   */
  protected function createSupersession(?bool $authorized): BeaconSupersession {
    if ($authorized === NULL) {
      return new BeaconSupersession();
    }

    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);

    return new BeaconSupersession($authorization);
  }

  /**
   * Legacy AI is superseded once Beacon is platform-authorized.
   *
   * @covers ::isSuperseded
   */
  public function testSupersededWhenBeaconIsAuthorized(): void {
    $this->assertTrue($this->createSupersession(TRUE)->isSuperseded());
  }

  /**
   * Legacy AI stands while Beacon is installed but not yet authorized.
   *
   * @covers ::isSuperseded
   */
  public function testNotSupersededWhenBeaconIsNotAuthorized(): void {
    $this->assertFalse($this->createSupersession(FALSE)->isSuperseded());
  }

  /**
   * Legacy AI stands on a site where ys_beacon is not installed.
   *
   * The optional service reference resolves to NULL there, so ys_ai keeps
   * working without a hard dependency on ys_beacon.
   *
   * @covers ::isSuperseded
   */
  public function testNotSupersededWhenBeaconIsAbsent(): void {
    $this->assertFalse($this->createSupersession(NULL)->isSuperseded());
  }

  /**
   * The Beacon settings cache tag is exposed whatever the answer.
   *
   * Callers add it to their access results so flipping authorization
   * invalidates them without a manual cache rebuild.
   *
   * @covers ::getCacheTags
   */
  public function testCacheTagsCoverBeaconSettings(): void {
    $this->assertSame(
      ['config:ys_beacon.settings'],
      $this->createSupersession(TRUE)->getCacheTags()
    );
  }

}
