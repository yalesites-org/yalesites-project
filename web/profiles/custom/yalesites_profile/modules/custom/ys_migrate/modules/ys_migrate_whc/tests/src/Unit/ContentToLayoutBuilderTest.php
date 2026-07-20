<?php

namespace Drupal\Tests\ys_migrate_whc\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate_whc\Plugin\migrate\process\whc\ContentToLayoutBuilder;

/**
 * Unit tests for the whc_content_to_layout_builder process plugin helpers.
 *
 * Covers the pure static helper methods used by the WHC migrations via YAML
 * callbacks: YouTube URL normalisation and straight-to-curly quote conversion.
 *
 * @coversDefaultClass \Drupal\ys_migrate_whc\Plugin\migrate\process\whc\ContentToLayoutBuilder
 * @group ys_migrate_whc
 * @group yalesites
 */
class ContentToLayoutBuilderTest extends UnitTestCase {

  /**
   * @covers ::toWatchUrl
   * @dataProvider watchUrlProvider
   */
  public function testToWatchUrl(string $input, string $expected): void {
    $this->assertSame($expected, ContentToLayoutBuilder::toWatchUrl($input));
  }

  /**
   * Data provider for testToWatchUrl().
   *
   * @return array[]
   *   Sets of [input url, expected watch url].
   */
  public static function watchUrlProvider(): array {
    $watch = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    return [
      'embed url' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', $watch],
      'short youtu.be url' => ['https://youtu.be/dQw4w9WgXcQ', $watch],
      'already a watch url' => [$watch, $watch],
      'nocookie embed url' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $watch],
      'non-youtube url is unchanged' => ['https://vimeo.com/123456', 'https://vimeo.com/123456'],
    ];
  }

  /**
   * @covers ::replaceStraightQuotes
   * @dataProvider straightQuotesProvider
   */
  public function testReplaceStraightQuotes(string $input, string $expected): void {
    $this->assertSame($expected, ContentToLayoutBuilder::replaceStraightQuotes($input));
  }

  /**
   * Data provider for testReplaceStraightQuotes().
   *
   * @return array[]
   *   Sets of [input text, expected text].
   */
  public static function straightQuotesProvider(): array {
    return [
      'double quotes become curly' => ['He said "hello".', 'He said “hello”.'],
      'single quotes become curly' => ["The 'best' day.", 'The ‘best’ day.'],
      'mixed quotes' => ['A "quote" and a \'note\'.', 'A “quote” and a ‘note’.'],
      'text without quotes is unchanged' => ['Plain text.', 'Plain text.'],
    ];
  }

}
