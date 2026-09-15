<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\BeaconAuthorization;

/**
 * Tests the Beacon authorization service.
 *
 * The single source of truth for whether a platform admin has authorized
 * Beacon for the site, read from ys_beacon.settings:platform_authorized.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\BeaconAuthorization
 */
class BeaconAuthorizationTest extends UnitTestCase {

  /**
   * Builds the service with a config factory stub for the given settings.
   */
  private function authorization(array $settings): BeaconAuthorization {
    return new BeaconAuthorization(
      $this->getConfigFactoryStub(['ys_beacon.settings' => $settings]),
    );
  }

  /**
   * A missing or falsy flag is not authorized; a truthy flag is.
   *
   * @covers ::isAuthorized
   */
  public function testReflectsFlag(): void {
    $this->assertFalse($this->authorization([])->isAuthorized());
    $this->assertFalse($this->authorization(['platform_authorized' => FALSE])->isAuthorized());
    $this->assertTrue($this->authorization(['platform_authorized' => TRUE])->isAuthorized());
  }

}
