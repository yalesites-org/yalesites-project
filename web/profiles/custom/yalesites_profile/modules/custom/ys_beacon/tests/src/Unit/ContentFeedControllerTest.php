<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Controller\ContentFeedController;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the content feed endpoint's authorization and type handling.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Controller\ContentFeedController
 */
class ContentFeedControllerTest extends UnitTestCase {

  /**
   * Builds the controller with the given authorization state and builder.
   */
  private function controller(bool $authorized, ?ContentFeedBuilder $builder = NULL): ContentFeedController {
    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);
    return new ContentFeedController(
      $builder ?? $this->createMock(ContentFeedBuilder::class),
      $authorization
    );
  }

  /**
   * The feed is closed with a 403 when Beacon is not authorized.
   *
   * @covers ::feed
   */
  public function testForbiddenWhenNotAuthorized(): void {
    $response = $this->controller(FALSE)->feed(Request::create('/'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * An unsupported type surfaces as a 400 rather than a 500.
   *
   * @covers ::feed
   */
  public function testBadRequestForUnsupportedType(): void {
    $builder = $this->createMock(ContentFeedBuilder::class);
    $builder->method('build')
      ->willThrowException(new \InvalidArgumentException('Unsupported feed type "widget".'));

    $response = $this->controller(TRUE, $builder)
      ->feed(Request::create('/', 'GET', ['type' => 'widget']));

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * An authorized request returns the builder's payload as JSON.
   *
   * @covers ::feed
   */
  public function testReturnsPayloadWhenAuthorized(): void {
    $builder = $this->createMock(ContentFeedBuilder::class);
    $builder->method('build')->willReturn(['data' => [], 'pagination' => ['type' => 'node']]);

    $response = $this->controller(TRUE, $builder)
      ->feed(Request::create('/', 'GET', ['type' => 'node']));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('data', json_decode($response->getContent(), TRUE));
  }

}
