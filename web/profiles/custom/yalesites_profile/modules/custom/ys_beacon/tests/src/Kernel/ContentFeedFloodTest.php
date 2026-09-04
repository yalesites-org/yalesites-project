<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Controller\ContentFeedController;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Drupal\ys_beacon\Service\ContentFeedPage;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the content feed's quota against the real flood backend.
 *
 * The builder and the authorization flag are stubbed so this exercises the
 * controller's throttling against Drupal's database flood service rather than
 * against a mock of it - the counting, the window, and the client identifier
 * are the parts worth proving.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Controller\ContentFeedController
 */
class ContentFeedFloodTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * Builds the controller with a stubbed builder and the real flood service.
   */
  private function controller(bool $authorized): ContentFeedController {
    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);

    $builder = $this->createMock(ContentFeedBuilder::class);
    $builder->method('build')->willReturn(new ContentFeedPage(
      ['data' => [], 'pagination' => ['type' => 'node']],
      new CacheableMetadata(),
    ));

    return new ContentFeedController(
      $builder,
      $authorization,
      $this->container->get('flood'),
    );
  }

  /**
   * Requests are served up to the quota and refused with a 429 past it.
   *
   * @covers ::feed
   */
  public function testQuotaIsEnforcedPerClient(): void {
    $controller = $this->controller(TRUE);
    $request = Request::create('/api/ys-beacon/v1/content', 'GET', ['type' => 'node']);

    for ($i = 1; $i <= ContentFeedController::FLOOD_LIMIT; $i++) {
      $this->assertSame(200, $controller->feed($request)->getStatusCode());
    }

    $refused = $controller->feed($request);
    $this->assertSame(429, $refused->getStatusCode());
    $this->assertSame(
      'Too many requests. Please try again shortly.',
      json_decode($refused->getContent(), TRUE)['error'] ?? NULL
    );
  }

  /**
   * An unauthorized site still gets a 403, never a 429.
   *
   * @covers ::feed
   */
  public function testUnauthorizedSiteIsNeverThrottled(): void {
    $controller = $this->controller(FALSE);
    $request = Request::create('/api/ys-beacon/v1/content', 'GET', ['type' => 'node']);

    $this->assertSame(403, $controller->feed($request)->getStatusCode());
    $this->assertSame(403, $controller->feed($request)->getStatusCode());

    // Asserted against the counter directly rather than by exhausting the
    // quota: a threshold of 1 is refused by a single registration, so this
    // fails if either refusal above consumed any.
    $this->assertTrue(
      $this->container->get('flood')
        ->isAllowed(ContentFeedController::FLOOD_EVENT, 1, ContentFeedController::FLOOD_WINDOW),
      'A refused request consumes no quota.'
    );
  }

}
