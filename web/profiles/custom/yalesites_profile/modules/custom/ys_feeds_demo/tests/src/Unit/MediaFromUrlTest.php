<?php

namespace Drupal\Tests\ys_feeds_demo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\feeds\Exception\TargetValidationException;
use Drupal\ys_feeds_demo\Feeds\Target\MediaFromUrl;

/**
 * Unit tests for the guards in MediaFromUrl.
 *
 * A feed source is untrusted input that this target turns into a file on disk,
 * so the two checks that decide what it will and will not fetch are worth
 * pinning down: the scheme allow-list, and the extension allow-list read from
 * the media type's own source field.
 *
 * @coversDefaultClass \Drupal\ys_feeds_demo\Feeds\Target\MediaFromUrl
 * @group ys_feeds_demo
 * @group yalesites
 */
class MediaFromUrlTest extends UnitTestCase {

  /**
   * The target under test, built without its service dependencies.
   *
   * The methods exercised here are pure, so the constructor is skipped rather
   * than mocking six services that none of them touch.
   *
   * @var \Drupal\ys_feeds_demo\Feeds\Target\MediaFromUrl
   */
  protected $target;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->target = (new \ReflectionClass(MediaFromUrl::class))->newInstanceWithoutConstructor();
  }

  /**
   * Calls a protected method on the target.
   *
   * @param string $method
   *   The method name.
   * @param array $args
   *   Arguments to pass.
   *
   * @return mixed
   *   Whatever the method returns.
   */
  protected function call(string $method, array $args) {
    $ref = new \ReflectionMethod(MediaFromUrl::class, $method);
    $ref->setAccessible(TRUE);

    return $ref->invokeArgs($this->target, $args);
  }

  /**
   * A permitted extension yields the file name.
   *
   * @covers ::getFileName
   * @dataProvider acceptedFileNameProvider
   */
  public function testAcceptedFileNames(string $url, string $allowed, string $expected): void {
    $this->assertSame($expected, $this->call('getFileName', [$url, $allowed]));
  }

  /**
   * Provides URLs whose file name should be accepted.
   *
   * @return array
   *   Cases: [url, allowed extensions, expected file name].
   */
  public static function acceptedFileNameProvider(): array {
    return [
      'plain pdf' => ['https://example.org/docs/report.pdf', 'pdf doc docx', 'report.pdf'],
      'uppercase extension' => ['https://example.org/REPORT.PDF', 'pdf', 'REPORT.PDF'],
      'query string ignored' => ['https://example.org/a/cover.jpg?v=2&x=1', 'png jpg jpeg', 'cover.jpg'],
      'no allow-list permits anything' => ['https://example.org/thing.xyz', '', 'thing.xyz'],
    ];
  }

  /**
   * A disallowed extension is refused, naming what was allowed.
   *
   * @covers ::getFileName
   */
  public function testDisallowedExtensionIsRefused(): void {
    $this->expectException(TargetValidationException::class);
    $this->expectExceptionMessageMatches('/extension exe/');

    $this->call('getFileName', ['https://example.org/payload.exe', 'pdf doc docx']);
  }

  /**
   * A URL with no usable file name is refused.
   *
   * @covers ::getFileName
   */
  public function testMissingFileNameIsRefused(): void {
    $this->expectException(TargetValidationException::class);

    $this->call('getFileName', ['https://example.org/', 'pdf']);
  }

  /**
   * Only http and https are fetched.
   *
   * @covers ::resolveMedia
   * @dataProvider rejectedSchemeProvider
   */
  public function testNonHttpSchemesAreRefused(string $url): void {
    $this->expectException(TargetValidationException::class);
    $this->expectExceptionMessageMatches('/only http and https/');

    $this->call('resolveMedia', [$url, '']);
  }

  /**
   * Provides URLs whose scheme must be refused.
   *
   * @return array
   *   Cases: [url].
   */
  public static function rejectedSchemeProvider(): array {
    return [
      'local file' => ['file:///etc/passwd'],
      'php wrapper' => ['php://input'],
      'data uri' => ['data:text/plain;base64,SGVsbG8='],
      'ftp' => ['ftp://example.org/thing.pdf'],
      'no scheme' => ['/sites/default/files/thing.pdf'],
    ];
  }

}
