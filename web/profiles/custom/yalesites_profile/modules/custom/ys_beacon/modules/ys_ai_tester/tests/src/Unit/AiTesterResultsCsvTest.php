<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Controller\AiTesterController;
use Drupal\ys_ai_tester\RunComparator;

/**
 * Tests the run-detail results CSV export (BOM, header, hardening, multiline).
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Controller\AiTesterController
 *
 * @group ys_beacon
 */
class AiTesterResultsCsvTest extends UnitTestCase {

  /**
   * Invokes the protected buildResultsCsv() method on a controller with stubs.
   */
  protected function buildResultsCsv(array $rows): string {
    $controller = new AiTesterController(
      $this->createMock(Connection::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(RunComparator::class),
    );
    $method = new \ReflectionMethod($controller, 'buildResultsCsv');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $rows);
  }

  /**
   * @covers ::buildResultsCsv
   */
  public function testStartsWithUtf8Bom(): void {
    $csv = $this->buildResultsCsv([]);
    $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
  }

  /**
   * @covers ::buildResultsCsv
   */
  public function testHasQuestionAnswerSourcesHeader(): void {
    $csv = $this->buildResultsCsv([]);
    $this->assertStringContainsString('Question,Answer,Sources', $csv);
  }

  /**
   * @covers ::buildResultsCsv
   */
  public function testNeutralizesFormulaInjection(): void {
    $csv = $this->buildResultsCsv([
      ['question' => '=cmd()', 'answer' => 'safe', 'sources' => ''],
    ]);
    // The leading = would be executed as a formula; csvCell prefixes a quote.
    $this->assertStringContainsString("'=cmd()", $csv);
  }

  /**
   * @covers ::buildResultsCsv
   */
  public function testPreservesMultilineAnswerInSingleCell(): void {
    $csv = $this->buildResultsCsv([
      ['question' => 'Q', 'answer' => "line one\nline two", 'sources' => 'https://a | https://b'],
    ]);
    // Fputcsv quotes a field containing a newline, keeping it in one cell.
    $this->assertStringContainsString("\"line one\nline two\"", $csv);
    $this->assertStringContainsString('https://a | https://b', $csv);
  }

}
