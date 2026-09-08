<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Controller\ContentFeedController;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Drupal\ys_beacon\Service\ContentFeedPage;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the content feed endpoint's authorization, throttling, and caching.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Controller\ContentFeedController
 */
class ContentFeedControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Cacheable responses validate their cache contexts through the container.
    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the controller with the given authorization state and collaborators.
   */
  private function controller(bool $authorized, ?ContentFeedBuilder $builder = NULL, ?FloodInterface $flood = NULL): ContentFeedController {
    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);
    if ($flood === NULL) {
      $flood = $this->createMock(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }
    return new ContentFeedController(
      $builder ?? $this->createMock(ContentFeedBuilder::class),
      $authorization,
      $flood
    );
  }

  /**
   * Builds a feed builder returning one page with the given cacheability.
   */
  private function builderReturning(array $tags = []): ContentFeedBuilder {
    $builder = $this->createMock(ContentFeedBuilder::class);
    $builder->method('build')->willReturn(new ContentFeedPage(
      ['data' => [], 'pagination' => ['type' => 'node']],
      (new CacheableMetadata())->addCacheTags($tags),
    ));
    return $builder;
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
    $response = $this->controller(TRUE, $this->builderReturning())
      ->feed(Request::create('/', 'GET', ['type' => 'node']));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayHasKey('data', json_decode($response->getContent(), TRUE));
  }

  /**
   * A request within the quota is served and counted against it.
   *
   * @covers ::feed
   */
  public function testRequestUnderTheLimitIsServedAndRegistered(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with(
        ContentFeedController::FLOOD_EVENT,
        ContentFeedController::FLOOD_LIMIT,
        ContentFeedController::FLOOD_WINDOW
      )
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with(ContentFeedController::FLOOD_EVENT, ContentFeedController::FLOOD_WINDOW);

    $response = $this->controller(TRUE, $this->builderReturning(), $flood)
      ->feed(Request::create('/', 'GET', ['type' => 'node']));

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * A request past the quota is refused with a 429 and a JSON error body.
   *
   * @covers ::feed
   */
  public function testTooManyRequestsPastTheLimit(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $builder = $this->createMock(ContentFeedBuilder::class);
    $builder->expects($this->never())->method('build');

    $response = $this->controller(TRUE, $builder, $flood)
      ->feed(Request::create('/', 'GET', ['type' => 'node']));

    $this->assertSame(429, $response->getStatusCode());
    $this->assertArrayHasKey('error', json_decode($response->getContent(), TRUE));
    $this->assertSame((string) ContentFeedController::FLOOD_WINDOW, $response->headers->get('Retry-After'));
    // Never cached: a cached 429 would outlive the window that produced it.
    $this->assertNotInstanceOf(CacheableResponseInterface::class, $response);
  }

  /**
   * An unauthorized site keeps its 403 and is never throttled into a 429.
   *
   * @covers ::feed
   */
  public function testAuthorizationIsCheckedBeforeTheQuota(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');

    $response = $this->controller(FALSE, NULL, $flood)->feed(Request::create('/'));

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * The 403 varies with the authorization flag rather than being shared.
   *
   * @covers ::feed
   */
  public function testForbiddenResponseIsInvalidatedByTheAuthorizationFlag(): void {
    $response = $this->controller(FALSE)->feed(Request::create('/'));

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $this->assertContains(
      'config:' . BeaconAuthorization::CONFIG_NAME,
      $response->getCacheableMetadata()->getCacheTags()
    );
  }

  /**
   * The feed response carries the page's cacheability plus its own.
   *
   * @covers ::feed
   */
  public function testFeedResponseCarriesCacheability(): void {
    $response = $this->controller(TRUE, $this->builderReturning(['node_list', 'node:1']))
      ->feed(Request::create('/', 'GET', ['type' => 'node']));

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $metadata = $response->getCacheableMetadata();
    $this->assertContains('node_list', $metadata->getCacheTags());
    $this->assertContains('node:1', $metadata->getCacheTags());
    $this->assertContains(
      'config:' . BeaconAuthorization::CONFIG_NAME,
      $metadata->getCacheTags()
    );
    foreach (['type', 'page', 'page_size'] as $arg) {
      $this->assertContains('url.query_args:' . $arg, $metadata->getCacheContexts());
    }
  }

}
