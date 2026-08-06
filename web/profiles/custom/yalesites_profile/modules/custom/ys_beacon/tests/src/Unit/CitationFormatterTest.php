<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\CitationFormatter;

/**
 * Tests the shared citation formatter.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\CitationFormatter
 */
class CitationFormatterTest extends UnitTestCase {

  /**
   * The formatter under test.
   *
   * @var \Drupal\ys_beacon\Service\CitationFormatter
   */
  protected CitationFormatter $formatter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->formatter = new CitationFormatter();
  }

  /**
   * Only sources whose [docN] marker appears are flagged cited.
   *
   * @covers ::format
   * @covers ::citedMarkers
   */
  public function testCitedFlagFollowsMarkers(): void {
    $citations = [
      ['title' => 'One', 'url' => 'https://example.com/1', 'content' => 'First'],
      ['title' => 'Two', 'url' => 'https://example.com/2', 'content' => 'Second'],
      ['title' => 'Three', 'url' => 'https://example.com/3', 'content' => 'Third'],
    ];
    // The model cited the first and third sources only.
    $result = $this->formatter->format('Per [doc1] and also [doc3], yes.', $citations);

    $this->assertCount(3, $result, 'All retrieved sources are returned, cited or not.');
    $this->assertTrue($result[0]['cited']);
    $this->assertFalse($result[1]['cited']);
    $this->assertTrue($result[2]['cited']);
    $this->assertSame([1, 2, 3], array_column($result, 'number'));
  }

  /**
   * Duplicate URLs collapse to one entry, cited if any duplicate was cited.
   *
   * @covers ::format
   */
  public function testDuplicateUrlsAreMergedAndRenumbered(): void {
    $citations = [
      ['title' => 'Page A (chunk 1)', 'url' => 'https://example.com/a', 'content' => 'A1'],
      ['title' => 'Page B', 'url' => 'https://example.com/b', 'content' => 'B'],
      ['title' => 'Page A (chunk 2)', 'url' => 'https://example.com/a', 'content' => 'A2'],
    ];
    // [doc3] is the second chunk of Page A, so Page A counts as cited.
    $result = $this->formatter->format('See [doc3].', $citations);

    $this->assertCount(2, $result, 'The two chunks of Page A merge into one source.');
    $this->assertSame('https://example.com/a', $result[0]['url']);
    $this->assertTrue($result[0]['cited'], 'A duplicate cited under any marker is cited.');
    $this->assertSame('Page B', $result[1]['title']);
    $this->assertFalse($result[1]['cited']);
    $this->assertSame([1, 2], array_column($result, 'number'));
  }

  /**
   * An excerpt is derived from the content.
   *
   * @covers ::format
   */
  public function testExcerptIsTruncated(): void {
    $long = str_repeat('x', 500);
    $result = $this->formatter->format('No markers here.', [
      ['title' => 'Long', 'url' => 'https://example.com/long', 'content' => $long],
    ]);

    $this->assertFalse($result[0]['cited']);
    $this->assertSame(300, mb_strlen($result[0]['excerpt']));
    $this->assertSame($long, $result[0]['content']);
  }

  /**
   * No retrieved sources yields an empty list.
   *
   * @covers ::format
   */
  public function testEmptyCitationsYieldEmptyList(): void {
    $this->assertSame([], $this->formatter->format('Any answer [doc1].', []));
  }

}
