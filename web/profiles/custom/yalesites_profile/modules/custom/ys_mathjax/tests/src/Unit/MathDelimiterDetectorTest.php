<?php

namespace Drupal\Tests\ys_mathjax\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_mathjax\MathDelimiterDetector;

/**
 * Unit tests for MathDelimiterDetector.
 *
 * @coversDefaultClass \Drupal\ys_mathjax\MathDelimiterDetector
 * @group ys_mathjax
 * @group yalesites
 */
class MathDelimiterDetectorTest extends UnitTestCase {

  /**
   * Tests that hasMath() detects math and ignores non-math content.
   *
   * @param string $text
   *   The text to inspect.
   * @param bool $expected
   *   Whether math notation should be detected.
   *
   * @dataProvider mathProvider
   * @covers ::hasMath
   */
  public function testHasMath(string $text, bool $expected): void {
    $this->assertSame($expected, MathDelimiterDetector::hasMath($text));
  }

  /**
   * Provides text samples and whether they contain math notation.
   *
   * @return array
   *   Cases keyed by description: [text, expected].
   */
  public static function mathProvider(): array {
    return [
      'inline paren delimiter' => ['The value is \(E=mc^2\) today.', TRUE],
      'display dollar delimiter' => ['$$\int_0^1 x\,dx$$', TRUE],
      'display bracket delimiter' => ['\[a^2 + b^2 = c^2\]', TRUE],
      'mathml element' => ['<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi></math>', TRUE],
      'currency single dollar' => ['Tickets are $5 and lunch is $10.', FALSE],
      'plain parentheses and brackets' => ['See item (a) in list [3] below.', FALSE],
      'backslash escapes not math' => ['Use \n for newline and \t for tab.', FALSE],
      'empty string' => ['', FALSE],
      'plain prose' => ['This paragraph has no mathematics at all.', FALSE],
    ];
  }

}
