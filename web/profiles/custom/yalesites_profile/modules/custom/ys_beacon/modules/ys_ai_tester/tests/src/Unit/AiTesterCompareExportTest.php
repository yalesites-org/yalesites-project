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
 * Tests the compare view's readability aids and its AI-analysis export modal.
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

    // The legend names both runs so the colors are not the only cue.
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
   * The export button opens the modal as a Drupal core dialog.
   *
   * The label names no particular assistant: the export is a package for any
   * LLM, not a Clarity-specific upload.
   *
   * @covers ::compare
   */
  public function testCompareHasAiExportButton(): void {
    $build = $this->controllerReturning($this->comparison())->compare(7, 9);
    $export = $build['downloads']['export'];

    $this->assertSame('Export for AI analysis', (string) $export['#title']);
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

    $this->assertSame('ys_ai_tester_ai_export', $build['#theme']);
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

  /**
   * A comparison carrying one retrieved source per side, with full page text.
   *
   * Mirrors what CitationFormatter::format() stores: 'content' is the whole
   * retrieved chunk and 'excerpt' is its first 300 characters, so the two
   * overlap and 'content' is unbounded in the length of the indexed page.
   *
   * @return array
   *   The comparison structure.
   */
  protected function comparisonWithCitations(): array {
    $data = $this->comparison();
    $long = str_repeat('Indexed page body. ', 400);
    $citation = [
      'number' => 1,
      'title' => 'Library hours',
      'url' => 'https://example.yale.edu/hours',
      'content' => $long,
      'excerpt' => mb_substr($long, 0, 300),
      'cited' => TRUE,
    ];

    $data['pairs'][0]['a']['citations'] = [$citation];
    $data['pairs'][0]['b']['citations'] = [$citation];
    return $data;
  }

  /**
   * The JSON export drops each source's full text but keeps its excerpt.
   *
   * The download doubles as the file a reviewer hands to an LLM, so its size is
   * a token budget. Every citation stored 'content' (the entire retrieved
   * chunk, unbounded in page length) alongside 'excerpt' (that same text's
   * first 300 characters), which made the payload grow with how long the
   * indexed pages happen to be while adding no category of information the
   * excerpt does not already carry. Dropping 'content' bounds each source at
   * its excerpt. Nothing reads the field: the compare view never rendered it
   * and the CSV never emitted it.
   *
   * @covers ::downloadComparisonJson
   */
  public function testComparisonJsonDropsFullSourceTextKeepingExcerpt(): void {
    $controller = $this->controllerReturning($this->comparisonWithCitations());

    $payload = json_decode(
      (string) $controller->downloadComparisonJson(7, 9)->getContent(),
      TRUE
    );

    foreach (['a', 'b'] as $side) {
      $citation = $payload['pairs'][0][$side]['citations'][0];
      $this->assertArrayNotHasKey('content', $citation);
      // Everything the analysis prompt asks about survives.
      $this->assertSame(300, mb_strlen($citation['excerpt']));
      $this->assertSame('Library hours', $citation['title']);
      $this->assertSame('https://example.yale.edu/hours', $citation['url']);
      $this->assertSame(1, $citation['number']);
      $this->assertTrue($citation['cited']);
    }
  }

  /**
   * A side that was not asked in one run stays null through the export.
   *
   * The prompt tells the model an absent side means "not asked in this run", so
   * stripping citations must not turn a null side into an empty object.
   *
   * @covers ::downloadComparisonJson
   */
  public function testComparisonJsonKeepsAnUnpairedSideNull(): void {
    $data = $this->comparisonWithCitations();
    $data['pairs'][0]['b'] = NULL;
    $data['pairs'][0]['status'] = 'only_a';

    $payload = json_decode(
      (string) $this->controllerReturning($data)
        ->downloadComparisonJson(7, 9)
        ->getContent(),
      TRUE
    );

    $this->assertNull($payload['pairs'][0]['b']);
    $this->assertArrayNotHasKey(
      'content',
      $payload['pairs'][0]['a']['citations'][0]
    );
  }

  /**
   * The run meta and summary blocks survive the citation stripping untouched.
   *
   * @covers ::downloadComparisonJson
   */
  public function testComparisonJsonKeepsRunMetaAndSummary(): void {
    $payload = json_decode(
      (string) $this->controllerReturning($this->comparisonWithCitations())
        ->downloadComparisonJson(7, 9)
        ->getContent(),
      TRUE
    );

    $this->assertSame(7, $payload['run_a']['id']);
    $this->assertSame('beacon', $payload['run_a']['backend']);
    $this->assertSame('legacy', $payload['run_b']['backend']);
    $this->assertSame(2, $payload['summary']['total_compared']);
    $this->assertSame(1, $payload['summary']['differ']);
    // The question text and per-side answers are the point of the export.
    $this->assertSame(
      'What are the library hours?',
      $payload['pairs'][0]['question']
    );
    $this->assertSame(
      'Open until 11:45 p.m.',
      $payload['pairs'][0]['a']['answer']
    );
  }

}
