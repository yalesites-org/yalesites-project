<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Controller\AiTesterController;
use Drupal\ys_ai_tester\RunComparator;

/**
 * Tests CSV formula-injection neutralization in the comparison export.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Controller\AiTesterController
 *
 * @group ys_beacon
 */
class AiTesterCsvCellTest extends UnitTestCase {

  /**
   * Invokes the protected csvCell() method on a controller built with stubs.
   */
  protected function csvCell(string $value): string {
    $controller = new AiTesterController(
      $this->createMock(Connection::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(RunComparator::class),
    );
    $method = new \ReflectionMethod($controller, 'csvCell');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $value);
  }

  /**
   * @covers ::csvCell
   * @dataProvider provideFormulaCells
   */
  public function testNeutralizesFormulaCells(string $value, string $expected): void {
    $this->assertSame($expected, $this->csvCell($value));
  }

  /**
   * Cells that must be neutralized, and benign cells that must pass through.
   */
  public static function provideFormulaCells(): array {
    return [
      'plain text untouched' => ['hello world', 'hello world'],
      'empty untouched' => ['', ''],
      'comma text untouched' => ['normal, text', 'normal, text'],
      'equals formula' => ['=1+1', "'=1+1"],
      'plus formula' => ['+1', "'+1"],
      'minus formula' => ['-5', "'-5"],
      'at formula' => ['@SUM(A1)', "'@SUM(A1)"],
      'leading space then formula' => [' =1+1', "' =1+1"],
      'leading tab' => ["\t=1", "'\t=1"],
      'leading carriage return' => ["\r=1", "'\r=1"],
    ];
  }

}
