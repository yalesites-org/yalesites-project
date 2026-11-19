<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\SameSiteRedirect;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the same-site Referer check used after admin GET actions.
 *
 * @coversDefaultClass \Drupal\ys_core\SameSiteRedirect
 *
 * @group yalesites
 * @group ys_core
 */
class SameSiteRedirectTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->with('<front>')->willReturn('/');
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a request on http://localhost carrying the given Referer.
   *
   * @param string|null $referer
   *   The HTTP_REFERER value, or NULL to omit it.
   */
  protected function request(?string $referer): Request {
    $request = Request::create('/admin/yalesites/sync');
    if ($referer !== NULL) {
      $request->server->set('HTTP_REFERER', $referer);
    }
    return $request;
  }

  /**
   * A same-site Referer is returned unchanged.
   *
   * @covers ::fromReferer
   */
  public function testSameSiteRefererIsFollowed(): void {
    $this->assertSame(
      'http://localhost/admin/yalesites/localist',
      SameSiteRedirect::fromReferer($this->request('http://localhost/admin/yalesites/localist'))
    );
  }

  /**
   * Referers that must not be followed, because they leave the site.
   *
   * @return array
   *   Data sets of referer values.
   */
  public static function offSiteRefererProvider(): array {
    return [
      'missing' => [NULL],
      'empty' => [''],
      'other host' => ['https://evil.example.com/admin/yalesites/localist'],
      'protocol-relative' => ['//evil.example.com/admin'],
      'backslash host' => ['http:\\\\evil.example.com/admin'],
      'lookalike host' => ['http://localhost.evil.example.com/admin'],
      'userinfo trick' => ['http://localhost@evil.example.com/admin'],
      'relative path' => ['/admin/yalesites/localist'],
      'dangerous protocol' => ['javascript:alert(1)'],
      'no host' => ['mailto:admin@localhost'],
    ];
  }

  /**
   * An off-site, malformed or missing Referer falls back to the front page.
   *
   * @covers ::fromReferer
   * @dataProvider offSiteRefererProvider
   */
  public function testOffSiteRefererFallsBackToFrontPage(?string $referer): void {
    $this->assertSame('/', SameSiteRedirect::fromReferer($this->request($referer)));
  }

}
