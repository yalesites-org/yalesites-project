<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\RunComparator;
use Drupal\ys_ai_tester\RunExporter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the export service that builds every downloadable run artefact.
 *
 * These bodies used to be assembled inside the controller's route methods,
 * where they could only be reached through a Response. They are the artefacts
 * people quote as evidence, so they are now built by a service that can be
 * asserted on directly.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\RunExporter
 *
 * @group ys_beacon
 */
class RunExporterTest extends UnitTestCase {

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
   * Builds the exporter with the database stubbed to return the given rows.
   *
   * Two queries are issued — the run itself, then its results — so the stub
   * routes on the table name rather than on call order.
   *
   * @param object|null $run
   *   The run row, or NULL to make the run look missing.
   * @param array $results
   *   The run's result rows.
   * @param \Drupal\ys_ai_tester\RunComparator|null $comparator
   *   The comparator, stubbed when a test exercises a comparison export.
   *
   * @return \Drupal\ys_ai_tester\RunExporter
   *   The exporter under test.
   */
  protected function exporterFor(?object $run, array $results = [], ?RunComparator $comparator = NULL): RunExporter {
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

    return new RunExporter(
      $database,
      $comparator ?? $this->createMock(RunComparator::class),
    );
  }

  /**
   * A comparison structure as returned by RunComparator::compare().
   */
  protected function comparison(): array {
    return [
      'run_a' => ['id' => 7, 'backend' => 'beacon', 'source_filename' => 'a.txt'],
      'run_b' => ['id' => 9, 'backend' => 'legacy', 'source_filename' => 'b.txt'],
      'summary' => [
        'total_compared' => 1,
        'differ' => 1,
        'identical' => 0,
        'only_a' => 0,
        'only_b' => 0,
      ],
      'pairs' => [
        [
          'question' => 'Is my department eligible?',
          'status' => 'differs',
          'a' => [
            'answer' => 'Yes, most are.',
            'error' => '',
            'empty' => FALSE,
            'len' => 14,
            'cited' => 1,
            'retrieved' => 1,
            'citations' => [
              [
                'title' => 'Eligibility',
                'url' => 'https://example.com/eligibility',
                'cited' => TRUE,
                'excerpt' => 'A short excerpt.',
                'content' => 'The entire retrieved chunk, which must never be exported.',
              ],
            ],
          ],
          'b' => [
            'answer' => 'Most departments are.',
            'error' => '',
            'empty' => FALSE,
            'len' => 21,
            'cited' => 0,
            'retrieved' => 0,
            'citations' => [],
          ],
          'citation_overlap' => [
            'both' => [],
            'only_a' => [['url' => 'https://example.com/eligibility']],
            'only_b' => [],
          ],
        ],
      ],
    ];
  }

  /**
   * A missing run is a 404 rather than an empty export.
   *
   * Every export route reaches the database through here, so a deleted or
   * mistyped run id must not come back as a valid, empty file.
   *
   * @covers ::runJson
   */
  public function testMissingRunThrowsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->exporterFor(NULL)->runJson(3);
  }

  /**
   * The JSON export carries the error alongside the answer.
   *
   * A failed question and an assistant that answered nothing are
   * indistinguishable from the answer alone, and the export is the artefact
   * people quote.
   *
   * @covers ::runJson
   */
  public function testRunJsonExportsQuestionAnswerErrorAndCitations(): void {
    $exporter = $this->exporterFor((object) ['id' => 3], [
      $this->resultRow('Eligible?', 'Yes.', [['title' => 'Page', 'url' => 'https://example.com', 'cited' => TRUE]]),
      $this->resultRow('Contact?', '', [], 'cURL error 28: Operation timed out'),
    ]);

    $payload = $exporter->runJson(3);

    $this->assertCount(2, $payload);
    $this->assertSame('Eligible?', $payload[0]['question']);
    $this->assertSame('Yes.', $payload[0]['answer']);
    $this->assertSame('', $payload[0]['error']);
    $this->assertSame('Page', $payload[0]['citations'][0]['title']);
    $this->assertSame('cURL error 28: Operation timed out', $payload[1]['error']);
  }

  /**
   * A result with no stored citations decodes to an empty list, not NULL.
   *
   * @covers ::runJson
   */
  public function testRunJsonDecodesAbsentCitationsToAnEmptyList(): void {
    $row = $this->resultRow('Eligible?', 'Yes.');
    $row->citations = NULL;

    $payload = $this->exporterFor((object) ['id' => 3], [$row])->runJson(3);

    $this->assertSame([], $payload[0]['citations']);
  }

  /**
   * The questions export is the stored source file, verbatim.
   *
   * It is offered so a run can be edited and re-uploaded, which only works if
   * what comes back out is what went in.
   *
   * @covers ::questions
   */
  public function testQuestionsExportReturnsTheStoredSourceVerbatim(): void {
    $source = "First question?\nSecond question?";

    $exporter = $this->exporterFor((object) ['source_content' => $source]);

    $this->assertSame($source, $exporter->questions(3));
  }

  /**
   * The run CSV opens with a UTF-8 BOM and the documented header.
   *
   * Without the BOM Excel renders a curly quote or an em dash — which
   * assistant answers routinely contain — as mojibake.
   *
   * @covers ::runCsv
   */
  public function testRunCsvStartsWithBomAndHeader(): void {
    $csv = $this->exporterFor((object) ['id' => 3], [])->runCsv(3);

    $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    $this->assertStringContainsString('Question,Answer,Error,Sources', $csv);
  }

  /**
   * The run CSV neutralizes a cell a spreadsheet would execute as a formula.
   *
   * @covers ::runCsv
   */
  public function testRunCsvNeutralizesFormulaInjection(): void {
    $csv = $this->exporterFor((object) ['id' => 3], [
      $this->resultRow('=cmd()', 'safe'),
    ])->runCsv(3);

    $this->assertStringContainsString("'=cmd()", $csv);
  }

  /**
   * The run CSV lists only the sources that carry a URL.
   *
   * A URL-less citation has nothing to put in the Sources column, and the
   * comparison CSV already omits them — the two exports must agree. The ones
   * that remain are joined into a single cell.
   *
   * @covers ::runCsv
   */
  public function testRunCsvOmitsUrllessSources(): void {
    $csv = $this->exporterFor((object) ['id' => 3], [
      $this->resultRow('Eligible?', 'Yes.', [
        ['title' => 'Has a URL', 'url' => 'https://example.com/kept', 'cited' => TRUE],
        ['title' => 'No URL at all', 'cited' => FALSE],
        ['title' => 'Also has a URL', 'url' => 'https://example.com/second', 'cited' => FALSE],
      ]),
    ])->runCsv(3);

    // Two URLs so the ' | ' join is asserted and not just pass-through: the
    // Sources column is one cell however many sources a question had.
    $this->assertStringContainsString(
      'https://example.com/kept | https://example.com/second',
      $csv
    );
    $this->assertStringNotContainsString('No URL at all', $csv);
  }

  /**
   * A multiline answer stays inside one CSV cell.
   *
   * @covers ::runCsv
   */
  public function testRunCsvKeepsMultilineAnswerInOneCell(): void {
    $csv = $this->exporterFor((object) ['id' => 3], [
      $this->resultRow('Q', "line one\nline two"),
    ])->runCsv(3);

    $this->assertStringContainsString("\"line one\nline two\"", $csv);
  }

  /**
   * The comparison CSV carries both answers, both errors and the source split.
   *
   * @covers ::comparisonCsv
   */
  public function testComparisonCsvCarriesBothSidesAndTheSourceSplit(): void {
    $comparator = $this->createMock(RunComparator::class);
    $comparator->method('compare')->willReturn($this->comparison());

    $csv = $this->exporterFor(NULL, [], $comparator)->comparisonCsv(7, 9);

    $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    $this->assertStringContainsString('question,status,answer_a,answer_b', $csv);
    $this->assertStringContainsString('Yes, most are.', $csv);
    $this->assertStringContainsString('Most departments are.', $csv);
    $this->assertStringContainsString('https://example.com/eligibility', $csv);
  }

  /**
   * Both CSV exports harden their cells the same way.
   *
   * They each carried their own copy of the write loop once and had already
   * drifted — the comparison export was missing the BOM. One shared builder is
   * what keeps that from happening again, so it is asserted on both.
   *
   * @covers ::comparisonCsv
   */
  public function testComparisonCsvNeutralizesFormulaInjection(): void {
    $data = $this->comparison();
    $data['pairs'][0]['a']['answer'] = '=SUM(1,1)';

    $comparator = $this->createMock(RunComparator::class);
    $comparator->method('compare')->willReturn($data);

    $csv = $this->exporterFor(NULL, [], $comparator)->comparisonCsv(7, 9);

    $this->assertStringContainsString("'=SUM(1,1)", $csv);
  }

  /**
   * A failed question exports its error, not just a blank answer.
   *
   * The download is the artefact people quote as comparison evidence, so an
   * empty Answer cell must be distinguishable from an assistant that failed.
   *
   * @covers ::runCsv
   */
  public function testRunCsvExportsTheRecordedError(): void {
    $csv = $this->exporterFor((object) ['id' => 3], [
      $this->resultRow('Q', '', [], 'cURL error 28: Operation timed out'),
    ])->runCsv(3);

    $this->assertStringContainsString('cURL error 28: Operation timed out', $csv);
  }

  /**
   * Formula neutralization covers every trigger a spreadsheet acts on.
   *
   * Both CSV exports share one cell hardener, so the triggers are enumerated
   * once here against it rather than per export route. Asserted through the
   * protected method so a benign value can be shown passing through unchanged,
   * which a substring check against a whole CSV body cannot do.
   *
   * @covers ::csvCell
   * @dataProvider provideFormulaCells
   */
  public function testNeutralizesFormulaCells(string $value, string $expected): void {
    $exporter = $this->exporterFor(NULL);
    $method = new \ReflectionMethod($exporter, 'csvCell');
    $method->setAccessible(TRUE);

    $this->assertSame($expected, $method->invoke($exporter, $value));
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
