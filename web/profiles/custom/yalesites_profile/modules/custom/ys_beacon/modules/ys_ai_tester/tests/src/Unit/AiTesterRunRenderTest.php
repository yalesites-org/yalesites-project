<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\Controller\AiTesterController;
use Drupal\ys_ai_tester\RunComparator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the single-run detail view renders as question tabs.
 *
 * The run view used to be a three-column Question/Answer/Sources table while
 * the comparison beside it had already moved to a tab rail plus answer panels,
 * so the two views of the same data looked nothing alike. These tests hold the
 * run view to the comparison's shape: the same tab list, the same panel, one
 * answer column instead of two.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Controller\AiTesterController
 *
 * @group ys_beacon
 */
class AiTesterRunRenderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // run() and its helpers call $this->t() and Url::fromRoute().
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * A run row as stored in {ys_ai_tester_run}.
   */
  protected function runRow(int $id = 3, string $backend = 'beacon'): object {
    return (object) [
      'id' => $id,
      'created' => 1750000000,
      'source_filename' => 'run-1-questions.txt',
      'status' => 'complete',
      'backend' => $backend,
    ];
  }

  /**
   * A result row as stored in {ys_ai_tester_result}.
   *
   * @param string $question
   *   The question text.
   * @param string $answer
   *   The recorded answer, empty when the backend returned nothing.
   * @param array $citations
   *   Citation entries, encoded the way the batch stores them.
   * @param string $error
   *   The recorded error, empty when the question succeeded.
   *
   * @return object
   *   The result row.
   */
  protected function resultRow(string $question, string $answer, array $citations = [], string $error = ''): object {
    return (object) [
      'question' => $question,
      'answer' => $answer,
      'citations' => json_encode($citations),
      'error' => $error,
    ];
  }

  /**
   * Builds the controller with the database stubbed to return the given rows.
   *
   * Run() issues two queries — the run itself, then its results — so the stub
   * routes on the table name rather than on call order.
   *
   * @param object|null $run
   *   The run row, or NULL to make the run look missing.
   * @param array $results
   *   The run's result rows.
   *
   * @return \Drupal\ys_ai_tester\Controller\AiTesterController
   *   The controller under test.
   */
  protected function controllerFor(?object $run, array $results): AiTesterController {
    $run_statement = $this->createMock(StatementInterface::class);
    $run_statement->method('fetchObject')->willReturn($run);

    $results_statement = $this->createMock(StatementInterface::class);
    $results_statement->method('fetchAll')->willReturn($results);

    $database = $this->createMock(Connection::class);
    $database->method('query')->willReturnCallback(
      static fn (string $query): StatementInterface => str_contains($query, 'ys_ai_tester_result')
        ? $results_statement
        : $run_statement
    );

    $date_formatter = $this->createMock(DateFormatterInterface::class);
    $date_formatter->method('format')->willReturn('2026-08-03');

    $registry = $this->createMock(AnswerBackendRegistry::class);
    $registry->method('labelFor')->willReturnCallback(
      static fn (string $id): string => ucfirst($id)
    );

    return new AiTesterController(
      $database,
      $date_formatter,
      $this->createMock(RunComparator::class),
      $registry,
    );
  }

  /**
   * The run view renders the question tabs widget, not its old table.
   *
   * This is the whole point of the change: the two views of a run's answers now
   * share one widget, so a fix to the tab rail reaches both.
   *
   * @covers ::run
   * @covers ::runQuestionTabs
   */
  public function testRunRendersQuestionTabsInsteadOfTheTable(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Is my department eligible?', 'Yes, most are.'),
    ]);

    $results = $controller->run(3)['results'];

    $this->assertSame('ys_ai_tester_question_tabs', $results['#theme']);
    $this->assertArrayNotHasKey('#type', $results);
    $this->assertArrayNotHasKey('#rows', $results);
    // The widget is inert without its behavior, so it must bring its own
    // library rather than depend on the route having attached one.
    $this->assertContains('ys_ai_tester/question_tabs', $results['#attached']['library']);
  }

  /**
   * Every tab controls its own panel, and the ids pair up both ways.
   *
   * The template wires aria-controls from the tab's panel_id and
   * aria-labelledby from the panel's tab_id, so a mismatch here breaks the tab
   * list for keyboard and screen reader users without changing how it looks.
   *
   * @covers ::runQuestionTabs
   * @covers ::questionTab
   */
  public function testEachRunTabIsPairedWithItsOwnPanel(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('First question?', 'First answer.'),
      $this->resultRow('Second question?', 'Second answer.'),
      $this->resultRow('Third question?', 'Third answer.'),
    ]);

    $results = $controller->run(3)['results'];

    $this->assertCount(3, $results['#tabs']);
    $this->assertCount(3, $results['#panels']);
    foreach ($results['#tabs'] as $i => $tab) {
      $panel = $results['#panels'][$i];
      $this->assertSame($tab['panel_id'], $panel['id']);
      $this->assertSame($tab['id'], $panel['tab_id']);
    }

    $ids = array_column($results['#tabs'], 'id');
    $this->assertSame($ids, array_unique($ids));
    $panel_ids = array_column($results['#panels'], 'id');
    $this->assertSame($panel_ids, array_unique($panel_ids));
  }

  /**
   * A run panel holds exactly one answer column — the comparison minus Run B.
   *
   * The panel grid derives its column count from how many sides it is handed,
   * so this assertion is what keeps the single-run view from rendering in a
   * half-width column with dead space beside it.
   *
   * @covers ::runQuestionTabs
   */
  public function testRunPanelHasOneSideOnly(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Is my department eligible?', 'Yes, most are.'),
    ]);

    $panel = $controller->run(3)['results']['#panels'][0];

    $this->assertCount(1, $panel['sides']);
    $this->assertStringContainsString('Is my department eligible?', (string) $panel['heading']);
  }

  /**
   * The panel heading says which question this is out of how many.
   *
   * The tab rail scrolls once a run has more questions than fit it, so the
   * heading is the only place that says how far through the set you are.
   *
   * @covers ::runQuestionTabs
   * @covers ::questionHeading
   */
  public function testRunPanelHeadingCountsThePositionInTheSet(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('First question?', 'First answer.'),
      $this->resultRow('Second question?', 'Second answer.'),
      $this->resultRow('Third question?', 'Third answer.'),
    ]);

    $panels = $controller->run(3)['results']['#panels'];

    $this->assertStringContainsString('Question 1 of 3', (string) $panels[0]['heading']);
    $this->assertStringContainsString('Question 3 of 3', (string) $panels[2]['heading']);
    $this->assertStringContainsString('Third question?', (string) $panels[2]['heading']);
  }

  /**
   * A whitespace-only answer counts as empty, the way the comparison counts it.
   *
   * RunComparator::side() trims before deciding a side is empty. If this view
   * tested the raw string instead, the same stored result would be flagged
   * blank on the compare page and read as a real answer here.
   *
   * @covers ::resultStatus
   * @covers ::runSide
   */
  public function testWhitespaceOnlyAnswerCountsAsEmpty(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Blank but spacey?', "  \n\t "),
    ]);

    $results = $controller->run(3)['results'];

    $this->assertSame('empty', $results['#tabs'][0]['status']);
    $content = $results['#panels'][0]['sides'][0]['content'];
    $this->assertStringContainsString('Empty answer', (string) $content['meta']['#markup']);
  }

  /**
   * The single-run panel carries no per-run heading or meta line.
   *
   * The comparison labels each column ("Run A: file", "Run #2 · Beacon")
   * because a reader has to tell the two apart. A one-run page has nothing to
   * disambiguate and its header already states the run id, file, assistant and
   * status, so repeating that in all 25 panels is noise. Asserted rather than
   * left implicit because an empty string here renders an empty <h3>, which is
   * an accessibility defect rather than a cosmetic one.
   *
   * @covers ::runQuestionTabs
   */
  public function testRunPanelOmitsThePerRunHeading(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Is my department eligible?', 'Yes, most are.'),
    ]);

    $side = $controller->run(3)['results']['#panels'][0]['sides'][0];

    $this->assertNull($side['heading']);
    $this->assertNull($side['meta']);
  }

  /**
   * Each tab reports whether its question was answered, blank, or failed.
   *
   * The comparison's tabs carry a pair status; a single run has no pair, so the
   * badge reports the one thing worth scanning 25 questions for — which of them
   * did not come back with an answer. The label is visible text, so the
   * meaning is never carried by the chip colour alone.
   *
   * @covers ::runQuestionTabs
   * @covers ::resultStatus
   * @covers ::statusLabel
   */
  public function testRunTabCarriesResultStatusAsVisibleText(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Answered?', 'A real answer.'),
      $this->resultRow('Blank?', ''),
      $this->resultRow('Failed?', '', [], 'cURL error 28: Operation timed out'),
    ]);

    $tabs = $controller->run(3)['results']['#tabs'];

    $this->assertSame('answered', $tabs[0]['status']);
    $this->assertSame('Answered', (string) $tabs[0]['status_label']);
    $this->assertSame('empty', $tabs[1]['status']);
    $this->assertSame('No answer', (string) $tabs[1]['status_label']);
    $this->assertSame('error', $tabs[2]['status']);
    $this->assertSame('Error', (string) $tabs[2]['status_label']);

    $this->assertStringContainsString('Question 1', (string) $tabs[0]['number']);
    $this->assertStringContainsString('Question 3', (string) $tabs[2]['number']);
  }

  /**
   * A recorded error is reported instead of reading as an empty answer.
   *
   * A failure and a genuinely blank answer look identical in the answer column,
   * which is why the error text has to appear somewhere in the panel.
   *
   * @covers ::runQuestionTabs
   * @covers ::runSide
   */
  public function testErroredResultReportsTheErrorInThePanel(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Failed?', '', [], 'cURL error 28: Operation timed out'),
    ]);

    $content = $controller->run(3)['results']['#panels'][0]['sides'][0]['content'];
    $meta = (string) $content['meta']['#markup'];

    $this->assertStringContainsString('Operation timed out', $meta);
    $this->assertStringNotContainsString('No answer', $meta);
  }

  /**
   * A blank answer with no recorded error still says so.
   *
   * @covers ::runQuestionTabs
   * @covers ::runSide
   */
  public function testEmptyAnswerIsLabelledInThePanel(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Blank?', ''),
    ]);

    $content = $controller->run(3)['results']['#panels'][0]['sides'][0]['content'];

    $this->assertStringContainsString('Empty answer', (string) $content['meta']['#markup']);
  }

  /**
   * The panel keeps every retrieved source and whether it was cited.
   *
   * This is the run view's own information — the comparison only lists the
   * sources unique to one side — so converting the layout must not drop it.
   *
   * @covers ::runQuestionTabs
   * @covers ::runSide
   * @covers ::buildCitationsCell
   */
  public function testRunPanelKeepsEveryRetrievedSourceWithItsCitedFlag(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Eligible?', 'Yes.', [
        ['title' => 'Request Beacon', 'url' => 'https://yalesites.yale.edu/request-beacon', 'cited' => TRUE],
        ['title' => 'Office Hours', 'url' => 'https://yalesites.yale.edu/hours', 'cited' => FALSE],
      ]),
    ]);

    $sources = $controller->run(3)['results']['#panels'][0]['sides'][0]['content']['sources'];
    $items = $sources['list']['#items'];

    // Asserted off the render array positionally, not against a json_encode()
    // haystack: "cited" is a substring of "retrieved, not cited", so a
    // substring check for the cited case passes even when every source is
    // uncited, and json_encode() flattens the link's #url to {} so it cannot
    // tell citationLink()'s link branch from its plain-text fallback either.
    $this->assertCount(2, $items);
    $this->assertStringContainsString('Request Beacon', (string) $items[0]['link']['#title']);
    $this->assertSame(' — <em>cited</em>', $items[0]['flag']['#markup']);
    $this->assertStringContainsString('Office Hours', (string) $items[1]['link']['#title']);
    $this->assertSame(' — <em>retrieved, not cited</em>', $items[1]['flag']['#markup']);
  }

  /**
   * A run with no results keeps the message the table's #empty carried.
   *
   * The table got its empty state free from #empty; the tabs template renders
   * that message itself, so only a test keeps an unprocessed run from rendering
   * as a blank page.
   *
   * @covers ::run
   * @covers ::runQuestionTabs
   */
  public function testRunWithNoResultsKeepsItsEmptyMessage(): void {
    $controller = $this->controllerFor($this->runRow(), []);

    $results = $controller->run(3)['results'];

    $this->assertSame([], $results['#tabs']);
    $this->assertSame([], $results['#panels']);
    $this->assertStringContainsString('No results yet', (string) $results['#empty']);
  }

  /**
   * A long question is truncated for the tab but kept whole in the panel.
   *
   * @covers ::runQuestionTabs
   * @covers ::questionTab
   */
  public function testLongRunQuestionIsTruncatedOnlyOnTheTab(): void {
    $question = 'What are the office hours for the registrar during reading period and final examinations?';
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow($question, 'An answer.'),
    ]);

    $results = $controller->run(3)['results'];

    $preview = $results['#tabs'][0]['preview'];
    $this->assertNotSame($question, $preview);
    $this->assertLessThanOrEqual(60, mb_strlen($preview));
    // The panel heading keeps the full question; only the tab is shortened.
    $this->assertStringContainsString($question, (string) $results['#panels'][0]['heading']);
  }

  /**
   * The answer is escaped before it reaches the panel's markup.
   *
   * The old table cell handed the answer to Twig, which escaped it. The panel
   * builds a markup string instead, so the escaping is now this code's job and
   * an answer is backend output rather than trusted content.
   *
   * @covers ::runSide
   */
  public function testRunAnswerIsEscaped(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Injected?', '<script>alert(1)</script>'),
    ]);

    $answer = (string) $controller->run(3)['results']['#panels'][0]['sides'][0]['content']['answer']['#markup'];

    $this->assertStringNotContainsString('<script>', $answer);
    $this->assertStringContainsString('&lt;script&gt;', $answer);
  }

  /**
   * The question text is escaped on its way into the panel heading.
   *
   * Questions are user-supplied — they arrive in an uploaded .txt file — and
   * the heading is a new output path for them, so the escaping is asserted
   * rather than assumed. t()'s @ placeholder is what does it.
   *
   * @covers ::questionHeading
   */
  public function testQuestionIsEscapedInTheHeading(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('<script>alert(1)</script>', 'An answer.'),
    ]);

    $heading = (string) $controller->run(3)['results']['#panels'][0]['heading'];

    $this->assertStringNotContainsString('<script>', $heading);
    $this->assertStringContainsString('&lt;script&gt;', $heading);
  }

  /**
   * The run header and its download buttons survive the layout change.
   *
   * @covers ::run
   */
  public function testRunKeepsItsHeaderAndDownloadButtons(): void {
    $controller = $this->controllerFor($this->runRow(), [
      $this->resultRow('Eligible?', 'Yes.'),
    ]);

    $build = $controller->run(3);

    $meta = (string) $build['meta']['#markup'];
    $this->assertStringContainsString('Run #3', $meta);
    $this->assertStringContainsString('run-1-questions.txt', $meta);
    $this->assertStringContainsString('Beacon', $meta);
    $this->assertStringContainsString('complete', $meta);

    foreach (['json', 'csv', 'questions'] as $key) {
      $this->assertSame('link', $build['downloads'][$key]['#type']);
    }
    $this->assertSame('link', $build['back']['#type']);
  }

}
