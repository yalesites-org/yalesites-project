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
 * Tests that the announcements feed request is bounded by explicit timeouts.
 *
 * The cross-site feed fetch runs from a lazy builder attached to the admin
 * menu, so it is on the render path of every admin page rather than only the
 * dashboard route. Without explicit options the request inherits Drupal's
 * ClientFactory default of `timeout => 30` and no `connect_timeout` at all, so
 * an unreachable feed host stalls an editor's page load for 30 seconds.
 *
 * These tests pin the timeouts to the request itself rather than asserting an
 * elapsed wall-clock time, which would be slow and flaky.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\DashboardAnnouncements
 */
class DashboardAnnouncementsFeedTimeoutTest extends UnitTestCase {

  /**
   * Drupal's ClientFactory default, which applies when no options are passed.
   *
   * @see \Drupal\Core\Http\ClientFactory
   */
  private const DRUPAL_DEFAULT_TIMEOUT = 30;

  /**
   * The HTTP client double, which records the options it was called with.
   *
   * Typed as a plain object rather than ClientInterface: it is an anonymous
   * implementation carrying an extra $lastOptions property that the interface
   * does not declare, and this test reads exactly that property.
   *
   * @var object
   */
  private object $httpClientDouble;

  /**
   * Builds the service with an HTTP client that records its request options.
   */
  private function serviceCapturingOptions(): DashboardAnnouncements {
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
    $stream->method('__toString')->willReturn('{"items":[]}');
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    // ClientInterface::get() is Guzzle's __call shortcut, not a real interface
    // method, so it cannot be configured on a PHPUnit interface mock. This
    // double mirrors the one in DashboardAnnouncementsCategoriesTest, with the
    // addition of recording the options array the service passes in.
    $http_client = new class($response) implements ClientInterface {

      /**
       * The options passed to the most recent get() call.
       *
       * @var array
       */
      public array $lastOptions = [];

      public function __construct(private ResponseInterface $response) {}

      /**
       * Records the request options, then returns the canned response.
       */
      public function get($uri, array $options = []): ResponseInterface {
        $this->lastOptions = $options;
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
       *
       * @return mixed
       *   Always NULL; the service under test never reads client config.
       */
      public function getConfig(?string $option = NULL) {
        return NULL;
      }

    };

    // No current request means isSameSiteUrl() is always false, so the fetch
    // goes through the mocked HTTP client rather than the same-site controller
    // shortcut.
    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn(NULL);

    $this->httpClientDouble = $http_client;

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
   * The feed request sets an explicit read timeout well under Drupal's default.
   *
   * @covers ::getAnnouncements
   */
  public function testFeedRequestSetsExplicitReadTimeout(): void {
    $this->serviceCapturingOptions()->getAnnouncements();

    $this->assertSame(
      DashboardAnnouncements::FEED_TIMEOUT,
      $this->httpClientDouble->lastOptions['timeout'] ?? NULL,
      'The feed request must pass its own explicit timeout rather than inheriting the ClientFactory default.'
    );
    $this->assertLessThan(
      self::DRUPAL_DEFAULT_TIMEOUT,
      DashboardAnnouncements::FEED_TIMEOUT,
      'A timeout at or above the inherited default would not bound the admin-page stall at all.'
    );
  }

  /**
   * The feed request sets an explicit connect timeout.
   *
   * Drupal's ClientFactory leaves connect_timeout unset, so a host that drops
   * packets (rather than refusing the connection) is bounded only by the read
   * timeout. Setting it explicitly is what makes an unroutable host fail fast.
   *
   * @covers ::getAnnouncements
   */
  public function testFeedRequestSetsExplicitConnectTimeout(): void {
    $this->serviceCapturingOptions()->getAnnouncements();

    $this->assertSame(
      DashboardAnnouncements::FEED_CONNECT_TIMEOUT,
      $this->httpClientDouble->lastOptions['connect_timeout'] ?? NULL,
      'The feed request must set an explicit connect_timeout; ClientFactory leaves it unset.'
    );
    $this->assertLessThanOrEqual(
      DashboardAnnouncements::FEED_TIMEOUT,
      DashboardAnnouncements::FEED_CONNECT_TIMEOUT,
      'A connect timeout longer than the whole-request timeout could never be reached.'
    );
  }

}
