<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_markdown\MarkdownBuilder;

/**
 * Tests the post-conversion Markdown cleanup.
 *
 * @group ys_markdown
 * @coversDefaultClass \Drupal\ys_markdown\MarkdownBuilder
 */
class MarkdownTidyTest extends UnitTestCase {

  /**
   * Indentation, trailing spaces and blank runs are removed, code is kept.
   *
   * @covers ::tidy
   */
  public function testTidy(): void {
    $in = "      Lorem  \n\n\n\n   ![Alt](/a.jpg)   \n \n \n```\n  code\n    indented\n```\n    tail";
    $out = MarkdownBuilder::tidy($in);
    $this->assertSame("Lorem\n\n![Alt](/a.jpg)\n\n```\n  code\n    indented\n```\ntail", $out);
    foreach (explode("\n", $out) as $line) {
      if ($line !== '  code' && $line !== '    indented') {
        $this->assertDoesNotMatchRegularExpression('/^ {4}/', $line);
      }
    }
  }

}
