<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Form\AiTesterForm;

/**
 * Tests the accessible label on a run history row's select checkbox.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Form\AiTesterForm
 * @group ys_beacon
 */
class AiTesterHistoryLabelTest extends UnitTestCase {

  /**
   * The source filename both fixtures share.
   */
  const FILE = 'questions.txt';

  /**
   * The formatted date both fixtures share.
   */
  const DATE = '07/29/2025 - 10:40';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The label is a TranslatableMarkup, so casting it needs the service.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * The label names the run, so the checkbox is never announced bare.
   *
   * Core's tableselect gives a row checkbox an empty label unless the option
   * row supplies a 'title', and an unlabelled checkbox is a WCAG 2.1 SC 4.1.2
   * failure. Selecting runs to compare is the whole point of this table, so
   * the label has to say which run it selects.
   *
   * @covers ::historyRowLabel
   */
  public function testLabelIdentifiesTheRun(): void {
    $label = (string) AiTesterForm::historyRowLabel(12, self::FILE, self::DATE);

    $this->assertNotSame('', trim($label));
    $this->assertStringContainsString('12', $label);
    $this->assertStringContainsString(self::FILE, $label);
    $this->assertStringContainsString(self::DATE, $label);
  }

  /**
   * Two runs of one "run both" submission are still told apart.
   *
   * Both halves share a request timestamp and a source file, so the id is the
   * only part that distinguishes them — and a label that cannot distinguish
   * them leaves a screen reader user with two identical checkboxes.
   *
   * @covers ::historyRowLabel
   */
  public function testRunsSharingOneFileAndTimeGetDistinctLabels(): void {
    $a = (string) AiTesterForm::historyRowLabel(12, self::FILE, self::DATE);
    $b = (string) AiTesterForm::historyRowLabel(13, self::FILE, self::DATE);

    $this->assertNotSame($a, $b);
  }

}
