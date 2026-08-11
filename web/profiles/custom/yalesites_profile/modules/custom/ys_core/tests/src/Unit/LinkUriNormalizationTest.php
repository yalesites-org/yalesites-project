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
 * - A mistyped scheme, "htp://example.com", is rejected outright with "The
 *   path '…' is invalid." — the editor is told the whole address is wrong
 *   rather than the one character that is.
 *
 * All are normalised to a valid absolute URL, the same auto-correct behaviour
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

      // A scheme one keystroke away from http(s) is the scheme the editor
      // meant; core rejects the whole value instead of the one wrong
      // character, so correct it rather than making them find it.
      'dropped t' => ['htp://example.com', 'http://example.com'],
      'dropped h' => ['ttp://example.com', 'http://example.com'],
      'doubled p' => ['htpp://example.com', 'http://example.com'],
      'truncated scheme' => ['htt://example.com', 'http://example.com'],
      'doubled h' => ['hhtp://example.com', 'http://example.com'],
      'extra t' => ['htttp://example.com', 'http://example.com'],
      // One edit from "https" resolves to https, not http, so a secure URL is
      // not silently downgraded to a plaintext one.
      'dropped t on https' => ['htps://example.com', 'https://example.com'],
      'dropped h on https' => ['ttps://example.com', 'https://example.com'],
      'mistyped scheme keeps the rest of the URL' => ['htp://example.com/a/b?x=1#f', 'http://example.com/a/b?x=1#f'],
      'mistyped scheme with a single slash' => ['htp:/example.com', 'http://example.com'],
      'mistyped scheme with no slash' => ['htp:example.com', 'http://example.com'],
      'mistyped scheme upper case' => ['HTP://example.com', 'http://example.com'],

      // Shapes we deliberately do not try to guess at.
      'three slashes left alone' => ['///example.com', '///example.com'],
      'non http scheme single slash left alone' => ['ftp:/example.com', 'ftp:/example.com'],
      // Two edits away is a different word, not a typo. A defanged URL is the
      // common real-world case and must survive as typed.
      'defanged scheme left alone' => ['hxxp://example.com', 'hxxp://example.com'],
      'unknown scheme left alone' => ['foo://example.com', 'foo://example.com'],
      // Correcting a scheme must never manufacture an executable one.
      'javascript scheme left alone' => ['javascript:alert(1)', 'javascript:alert(1)'],
      'data scheme left alone' => ['data:text/html,hello', 'data:text/html,hello'],
      // A mistyped scheme with nothing after it is not a URL to correct.
      'mistyped scheme with no host left alone' => ['htp://', 'htp://'],
      // A host:port must not be mistaken for a scheme by the correction. Note
      // this shape is already left untouched before this change — the
      // "declares a scheme" guard matches "example.com:" — so a bare domain
      // carrying a port is still not normalised. That is a pre-existing gap,
      // not the behaviour we want; it is asserted here only to pin that the
      // scheme correction does not make it worse.
      'bare domain with port left alone' => ['example.com:8080/a', 'example.com:8080/a'],
    ];
  }

  /**
   * A protocol the site permits is never rewritten as if it were a typo.
   *
   * The correction is bounded to a single edit from "http"/"https" precisely
   * so it cannot capture a scheme the editor meant. This is the guard on that
   * bound: widen it to two edits and "ftp", "nntp" and "rtsp" all start being
   * rewritten to "http".
   *
   * @dataProvider allowedProtocolProvider
   */
  public function testAllowedProtocolsAreNeverRewritten(string $scheme): void {
    $uri = $scheme . '://example.com';
    $this->assertSame($uri, _ys_core_normalize_bare_domain_uri($uri));
  }

  /**
   * Provides every protocol this site allows.
   *
   * The list is UrlHelper::getAllowedProtocols() as configured here, read back
   * from the running site rather than assumed. It is hardcoded because a unit
   * test has no container: UrlHelper falls back to its ['http', 'https']
   * default, which would silently shrink this test's coverage to two cases.
   *
   * @return array
   *   Each case: [scheme].
   */
  public static function allowedProtocolProvider(): array {
    $protocols = [
      'http', 'https', 'ftp', 'news', 'nntp', 'tel', 'telnet', 'mailto', 'irc',
      'ssh', 'sftp', 'webcal', 'rtsp',
    ];

    return array_combine($protocols, array_map(fn($p) => [$p], $protocols));
  }

}
