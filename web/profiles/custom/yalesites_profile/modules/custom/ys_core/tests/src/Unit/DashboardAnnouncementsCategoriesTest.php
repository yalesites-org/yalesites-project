<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Drupal\ys_core\DashboardAnnouncements;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that a fetched feed's category data reaches getAnnouncements().
 *
 * The existing unread-state tests (DashboardAnnouncementsUnreadTest) seed the
 * keyvalue cache directly and never exercise the raw feed-parsing branch of
 * getAnnouncements() at all. These cover the new `tags` -> `categories`
 * pass-through in that branch, including tolerating a feed item that predates
 * this change (the "cache shape gotcha" from the issue).
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\DashboardAnnouncements
 */
class DashboardAnnouncementsCategoriesTest extends UnitTestCase {

  /**
   * Builds the service with an HTTP client returning the given feed body.
   *
   * @param string $feed_body
   *   The raw JSON feed body the mocked HTTP client returns.
   */
  private function serviceFetching(string $feed_body): DashboardAnnouncements {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'announcements_enabled' => TRUE,
      'announcements_feed_url' => 'https://example.com/feed',
      default => NULL,
    });
    $config_factory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $config_factory->method('get')->willReturn($config);

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);
    $key_value = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value->method('get')->willReturn($store);

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('__toString')->willReturn($feed_body);
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    // ClientInterface::get() is Guzzle's __call shortcut, not a real interface
    // method, so it cannot be configured on a PHPUnit interface mock. A tiny
    // hand-written double stands in for the real Client here instead.
    $http_client = new class($response) implements ClientInterface {

      public function __construct(private ResponseInterface $response) {}

      /**
       * Returns the canned response regardless of the requested URI.
       */
      public function get($uri, array $options = []): ResponseInterface {
        return $this->response;
      }

      /**
       * Unused by this test double.
       */
      public function send(RequestInterface $request, array $options = []): ResponseInterface {
        throw new \LogicException('Not implemented in this test double.');
      }

      /**
       * Unused by this test double.
       */
      public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
        throw new \LogicException('Not implemented in this test double.');
      }

      /**
       * Unused by this test double.
       */
      public function request(string $method, $uri, array $options = []): ResponseInterface {
        throw new \LogicException('Not implemented in this test double.');
      }

      /**
       * Unused by this test double.
       */
      public function requestAsync(string $method, $uri, array $options = []): PromiseInterface {
        throw new \LogicException('Not implemented in this test double.');
      }

      /**
       * Unused by this test double.
       */
      public function getConfig(?string $option = NULL) {
        return NULL;
      }

    };

    // No current request means isSameSiteUrl() is always false, so the fetch
    // always goes through the mocked HTTP client below rather than trying to
    // resolve the (unrelated) same-site controller shortcut.
    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn(NULL);

    return new DashboardAnnouncements(
      $http_client,
      $config_factory,
      $key_value,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(UserDataInterface::class),
      $request_stack,
      $this->createMock(ClassResolverInterface::class),
    );
  }

  /**
   * A feed item's `tags` reaches the parsed announcement as `categories`.
   *
   * @covers ::getAnnouncements
   */
  public function testCategoriesPassThroughFromFeed(): void {
    $service = $this->serviceFetching(json_encode([
      'items' => [
        [
          'title' => 'Has categories',
          'url' => 'https://example.com/a',
          'date_published' => '2026-01-01T00:00:00Z',
          'summary' => '',
          'tags' => ['Feature release', 'News'],
        ],
      ],
    ]));

    $items = $service->getAnnouncements();

    $this->assertSame(['Feature release', 'News'], $items[0]['categories']);
  }

  /**
   * An item with no `tags` key resolves to an empty list, not a fatal.
   *
   * Covers the "cache shape gotcha" from the issue: a downstream site must
   * keep rendering the rest of an announcement even when the source has not
   * yet started emitting categories.
   *
   * @covers ::getAnnouncements
   */
  public function testMissingCategoriesKeyResolvesToEmptyList(): void {
    $service = $this->serviceFetching(json_encode([
      'items' => [
        [
          'title' => 'No categories key',
          'url' => 'https://example.com/b',
          'date_published' => '2026-01-01T00:00:00Z',
          'summary' => '',
        ],
      ],
    ]));

    $items = $service->getAnnouncements();

    $this->assertSame([], $items[0]['categories']);
  }

  /**
   * Non-string or blank entries in `tags` are dropped, not fatal.
   *
   * @covers ::getAnnouncements
   */
  public function testCategoriesAreFilteredToNonEmptyTrimmedStrings(): void {
    $service = $this->serviceFetching(json_encode([
      'items' => [
        [
          'title' => 'Malformed categories',
          'url' => 'https://example.com/c',
          'date_published' => '2026-01-01T00:00:00Z',
          'summary' => '',
          'tags' => [' News ', '', NULL, 123],
        ],
      ],
    ]));

    $items = $service->getAnnouncements();

    $this->assertSame(['News', '123'], $items[0]['categories']);
  }

}
