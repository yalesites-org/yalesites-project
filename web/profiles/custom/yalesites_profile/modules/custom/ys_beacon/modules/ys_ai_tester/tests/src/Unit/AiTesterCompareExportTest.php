<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\Controller\AiTesterController;
use Drupal\ys_ai_tester\RunComparator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the compare view's readability aids and its Clarity export modal.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Controller\AiTesterController
 *
 * @group ys_beacon
 */
class AiTesterCompareExportTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // compare() and its helpers call $this->t() and Url::fromRoute().
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds the controller with a comparator stubbed to return $data.
   *
   * @param array $data
   *   The comparison structure the stubbed comparator returns.
   *
   * @return \Drupal\ys_ai_tester\Controller\AiTesterController
   *   The controller under test.
   */
  protected function controllerReturning(array $data): AiTesterController {
    $comparator = $this->createMock(RunComparator::class);
    $comparator->method('compare')->willReturn($data);

    $date_formatter = $this->createMock(DateFormatterInterface::class);
    $date_formatter->method('format')->willReturn('2026-07-29');

    $registry = $this->createMock(AnswerBackendRegistry::class);
    $registry->method('labelFor')->willReturnCallback(
      static fn (string $id): string => $id === 'legacy'
        ? 'Legacy ai_engine (Azure)'
        : 'Beacon'
    );

    return new AiTesterController(
      $this->createMock(Connection::class),
      $date_formatter,
      $comparator,
      $registry,
    );
  }

  /**
   * A two-question cross-assistant comparison.
   *
   * @return array
   *   The comparison structure.
   */
  protected function comparison(): array {
    $side = static fn (string $answer): array => [
      'error' => '',
      'answer' => $answer,
      'citations' => [],
      'len' => mb_strlen($answer),
      'cited' => 1,
      'retrieved' => 1,
      'empty' => FALSE,
    ];
    $meta = static fn (int $id, string $backend): array => [
      'id' => $id,
      'created' => 1753800000,
      'source_filename' => 'questions.txt',
      'status' => 'complete',
      'backend' => $backend,
    ];
    $overlap = ['both' => [], 'only_a' => [], 'only_b' => []];

    return [
      'run_a' => $meta(7, 'beacon'),
      'run_b' => $meta(9, 'legacy'),
      'pairs' => [
        [
          'question' => 'What are the library hours?',
          'status' => 'differs',
          'a' => $side('Open until 11:45 p.m.'),
          'b' => $side('Open until midnight.'),
          'len_delta' => -2,
          'citation_overlap' => $overlap,
        ],
        [
          'question' => 'How do I apply for aid?',
          'status' => 'identical',
          'a' => $side('Submit the CSS Profile.'),
          'b' => $side('Submit the CSS Profile.'),
          'len_delta' => 0,
          'citation_overlap' => $overlap,
        ],
      ],
      'summary' => [
        'total_compared' => 2,
        'differ' => 1,
        'identical' => 1,
        'only_a' => 0,
        'only_b' => 0,
      ],
    ];
  }

  /**
   * The view explains what the diff highlighting means, with a legend.
   *
   * Individual highlights are deliberately not focusable tooltips: a
   * paragraph-length answer yields dozens of changed-word spans, so the
   * explanation is given once for the table instead.
   *
   * @covers ::compare
   * @covers ::diffHelp
   */
  public function testCompareExplainsDiffHighlighting(): void {
    $build = $this->controllerReturning($this->comparison())->compare(7, 9);

    $this->assertArrayHasKey('help', $build);
    $help = (string) $build['help']['#value'];
    $this->assertStringContainsString('Highlighted', $help);
    $this->assertStringContainsString('other run', $help);

    // The legend names both directions so the colors are not the only cue.
    $legend = $build['legend'];
    $this->assertStringContainsString(
      'Run A',
      (string) $legend['only_a']['label']['#plain_text']
    );
    $this->assertStringContainsString(
      'Run B',
      (string) $legend['only_b']['label']['#plain_text']
    );
    // Swatches repeat the highlight color only, so they are decorative.
    $this->assertSame(
      'true',
      $legend['only_a']['swatch']['#attributes']['aria-hidden']
    );
  }

  /**
   * Below the mobile breakpoint the table is replaced by an explanation.
   *
   * @covers ::compare
   */
  public function testCompareCarriesMobileNotice(): void {
    $build = $this->controllerReturning($this->comparison())->compare(7, 9);

    // The CSS hides every sibling of the notice inside this container, so the
    // class is load-bearing rather than cosmetic.
    $this->assertContains('ys-compare', $build['#attributes']['class']);

    $notice = $build['mobile_notice'];
    $this->assertContains(
      'ys-compare-mobile-notice',
      $notice['#attributes']['class']
    );
    $this->assertStringContainsString(
      'larger screen',
      (string) $notice['#value']
    );
  }

  /**
   * The export button opens the Clarity modal as a Drupal core dialog.
   *
   * @covers ::compare
   */
  public function testCompareHasClarityExportButton(): void {
    $build = $this->controllerReturning($this->comparison())->compare(7, 9);
    $export = $build['downloads']['export'];

    $this->assertSame(
      'ys_ai_tester.compare_export',
      $export['#url']->getRouteName()
    );
    $this->assertContains('use-ajax', $export['#attributes']['class']);
    $this->assertSame('modal', $export['#attributes']['data-dialog-type']);
    // Core's dialog library is what traps focus and handles Escape.
    $this->assertContains(
      'core/drupal.dialog.ajax',
      $build['#attached']['library']
    );
  }

  /**
   * The modal reuses the existing comparison JSON route, not a new export.
   *
   * @covers ::compareExport
   */
  public function testExportModalReusesExistingJsonRoute(): void {
    $build = $this->controllerReturning($this->comparison())->compareExport(7, 9);

    $download = $build['#download'];
    $this->assertSame(
      'ys_ai_tester.compare_json',
      $download['#url']->getRouteName()
    );
    $this->assertSame(
      ['run_a' => 7, 'run_b' => 9],
      $download['#url']->getRouteParameters()
    );
  }

  /**
   * The prompt is given the run's real ids, assistants, and question count.
   *
   * @covers ::compareExport
   */
  public function testExportModalPromptCarriesRunContext(): void {
    $build = $this->controllerReturning($this->comparison())->compareExport(7, 9);

    $this->assertSame('ys_ai_tester_clarity_export', $build['#theme']);
    $this->assertSame(2, $build['#question_count']);
    $this->assertSame(7, $build['#run_a']);
    $this->assertSame(9, $build['#run_b']);
    $this->assertSame('Beacon', (string) $build['#label_a']);
    $this->assertSame(
      'Legacy ai_engine (Azure)',
      (string) $build['#label_b']
    );
  }

  /**
   * The copy button targets the prompt field and loads the copy behavior.
   *
   * @covers ::compareExport
   */
  public function testExportModalCopyButtonTargetsThePrompt(): void {
    $build = $this->controllerReturning($this->comparison())->compareExport(7, 9);

    $copy = $build['#copy'];
    $this->assertSame(
      $build['#prompt_id'],
      $copy['#attributes']['data-ys-copy-target']
    );
    // A plain button, not a link: it must never navigate.
    $this->assertSame('button', $copy['#attributes']['type']);
    $this->assertContains(
      'ys_ai_tester/compare_export',
      $build['#attached']['library']
    );
  }

}
