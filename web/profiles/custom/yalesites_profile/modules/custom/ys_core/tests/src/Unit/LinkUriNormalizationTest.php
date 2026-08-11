<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests the link URI normalisation applied to every core link widget.
 *
 * Regression coverage for malformed URLs that Drupal accepts and stores but
 * cannot resolve, so they publish as dead or broken links with no warning to
 * the editor:
 * - A protocol-relative "//example.com" is rewritten by the core link widget
 *   to "internal://example.com". That passes validation and renders as an
 *   anchor with an empty href — styled exactly like a working link, but going
 *   nowhere.
 * - A single-slash "https:/example.com" already declares a scheme, so it is
 *   stored verbatim and rendered as a literal, broken href.
 *
 * Both are normalised to a valid absolute URL, the same auto-correct behaviour
 * the bare-domain case already uses, rather than being rejected.
 *
 * @group ys_core
 */
class LinkUriNormalizationTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_core.module';
  }

  /**
   * Malformed URIs are corrected and well-formed ones are left alone.
   *
   * @dataProvider uriProvider
   */
  public function testNormalization(string $input, string $expected): void {
    $this->assertSame($expected, _ys_core_normalize_bare_domain_uri($input));
  }

  /**
   * Provides link field input with the URI it should normalise to.
   *
   * @return array
   *   Each case: [entered value, expected normalised value].
   */
  public static function uriProvider(): array {
    return [
      // Protocol-relative: the scheme the editor omitted is supplied.
      'protocol relative host' => ['//example.com', 'https://example.com'],
      'protocol relative with path' => ['//example.com/a/b?x=1#f', 'https://example.com/a/b?x=1#f'],
      'protocol relative with port' => ['//example.com:8080/a', 'https://example.com:8080/a'],

      // A scheme missing its authority delimiter gets it back.
      'https single slash' => ['https:/example.com', 'https://example.com'],
      'http single slash' => ['http:/example.com', 'http://example.com'],
      'https single slash with path' => ['https:/example.com/a/b', 'https://example.com/a/b'],
      'https no slash' => ['https:example.com', 'https://example.com'],
      // Core compares the scheme against its allowed-protocol list with a
      // case-sensitive in_array(), so an upper-case scheme must be lowered or
      // the corrected value is still rejected as an invalid path.
      'mixed case scheme is lowered' => ['HTTPS:/example.com', 'https://example.com'],

      // The bare-domain behaviour this extends must keep working.
      'bare domain' => ['google.com', 'https://google.com'],
      'bare domain with path' => ['www.example.org/path?x=1', 'https://www.example.org/path?x=1'],

      // Valid input must pass through untouched.
      'well formed https' => ['https://example.com', 'https://example.com'],
      'well formed http' => ['http://example.com', 'http://example.com'],
      'well formed https with path' => ['https://yale.edu/about', 'https://yale.edu/about'],
      'internal path' => ['/about', '/about'],
      'fragment' => ['#anchor', '#anchor'],
      'query' => ['?page=2', '?page=2'],
      'front token' => ['<front>', '<front>'],
      'nolink token' => ['<nolink>', '<nolink>'],
      'mailto' => ['mailto:someone@example.com', 'mailto:someone@example.com'],
      'tel' => ['tel:+12035551212', 'tel:+12035551212'],
      'internal scheme' => ['internal:/about', 'internal:/about'],
      'entity scheme' => ['entity:node/1', 'entity:node/1'],
      'route scheme' => ['route:<front>', 'route:<front>'],
      'empty string' => ['', ''],
      'scheme only' => ['https:', 'https:'],

      // Shapes we deliberately do not try to guess at.
      'three slashes left alone' => ['///example.com', '///example.com'],
      'non http scheme single slash left alone' => ['ftp:/example.com', 'ftp:/example.com'],
    ];
  }

}
