<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Form\AiTesterForm;

/**
 * Tests the question-input parsing and file/textarea XOR classification.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Form\AiTesterForm
 *
 * @group ys_beacon
 */
class AiTesterQuestionInputTest extends UnitTestCase {

  /**
   * @covers ::parseQuestionLines
   * @dataProvider provideQuestionText
   */
  public function testParseQuestionLines(string $text, array $expected): void {
    $this->assertSame($expected, AiTesterForm::parseQuestionLines($text));
  }

  /**
   * Raw textarea/file text and the question list it should parse into.
   */
  public static function provideQuestionText(): array {
    return [
      'simple lines' => ["a\nb\nc", ['a', 'b', 'c']],
      'trims and drops blank lines' => ["  a  \n\n  \n b ", ['a', 'b']],
      'handles crlf and cr' => ["a\r\nb\rc", ['a', 'b', 'c']],
      'empty string' => ['', []],
      'whitespace only' => ["   \n\t\n", []],
      'single line' => ['hello world', ['hello world']],
    ];
  }

  /**
   * @covers ::classifyInput
   * @dataProvider provideInputCombinations
   */
  public function testClassifyInput(bool $has_file, bool $has_text, string $expected): void {
    $this->assertSame($expected, AiTesterForm::classifyInput($has_file, $has_text));
  }

  /**
   * File/textarea presence combinations and the resolved source or error.
   */
  public static function provideInputCombinations(): array {
    return [
      'both supplied is an error' => [TRUE, TRUE, 'both'],
      'neither supplied is an error' => [FALSE, FALSE, 'neither'],
      'file only' => [TRUE, FALSE, 'file'],
      'text only' => [FALSE, TRUE, 'text'],
    ];
  }

}
