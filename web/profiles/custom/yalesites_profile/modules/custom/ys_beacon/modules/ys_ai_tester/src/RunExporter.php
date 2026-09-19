<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\Core\Database\Connection;

/**
 * Builds the downloadable artefacts for a tester run or a run comparison.
 *
 * Five routes offer a file: a run's results as JSON and as CSV, its question
 * list as plain text, and a comparison as JSON and as CSV. The bodies used to
 * be assembled inside the controller's route methods, where the only way to
 * assert on one was through a Response. They are the artefacts people quote as
 * evidence, so they are built here instead and the controller is left with the
 * HTTP envelope — status, content type, and the attachment filename.
 */
class RunExporter {

  use TesterRunStorageTrait;

  /**
   * The CSV header for a run's results export.
   */
  protected const RUN_CSV_HEADER = ['Question', 'Answer', 'Error', 'Sources'];

  /**
   * The CSV header for a comparison export.
   */
  protected const COMPARISON_CSV_HEADER = [
    'question', 'status', 'answer_a', 'answer_b',
    'error_a', 'error_b',
    'cited_a', 'cited_b', 'len_a', 'len_b',
    'shared_sources', 'only_a_sources', 'only_b_sources',
  ];

  /**
   * Constructs the run exporter.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\ys_ai_tester\RunComparator $runComparator
   *   The run comparator, which supplies both comparison exports.
   */
  public function __construct(
    protected Connection $database,
    protected RunComparator $runComparator,
  ) {}

  /**
   * Builds a run's results as the JSON export payload.
   *
   * @param int $run_id
   *   The run id.
   *
   * @return array
   *   One entry per question, each with question, answer, error and citations.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no run with the given id exists.
   */
  public function runJson(int $run_id): array {
    $this->loadRunOr404($run_id, 'id');

    $output = [];
    foreach ($this->loadResultRows($run_id) as $result) {
      $output[] = [
        'question' => $result->question,
        'answer' => $result->answer,
        // Exported so a failed question is not read as an assistant that
        // answered nothing — the export is the artefact people quote.
        'error' => (string) ($result->error ?? ''),
        'citations' => $this->decodeCitations($result->citations),
      ];
    }

    return $output;
  }

  /**
   * Builds a run's results as a spreadsheet-friendly CSV body.
   *
   * @param int $run_id
   *   The run id.
   *
   * @return string
   *   The CSV file body, including the leading BOM.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no run with the given id exists.
   */
  public function runCsv(int $run_id): string {
    $this->loadRunOr404($run_id, 'id');

    $rows = [];
    foreach ($this->loadResultRows($run_id) as $result) {
      // Drop URL-less citations from the Sources column: they carry no URL to
      // list, matching how the comparison CSV omits them.
      $sources = array_filter(
        $this->decodeCitations($result->citations),
        static fn (array $citation): bool => !empty($citation['url']),
      );
      $rows[] = [
        (string) $result->question,
        (string) $result->answer,
        (string) ($result->error ?? ''),
        $this->joinSourceUrls($sources),
      ];
    }

    return $this->buildCsv(self::RUN_CSV_HEADER, $rows);
  }

  /**
   * Returns a run's question list as stored.
   *
   * One question per line, ready to edit and re-upload as a new run, so what
   * comes out has to be exactly what went in.
   *
   * @param int $run_id
   *   The run id.
   *
   * @return string
   *   The uploaded source content, verbatim.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no run with the given id exists.
   */
  public function questions(int $run_id): string {
    return (string) $this->loadRunOr404($run_id, 'source_content')->source_content;
  }

  /**
   * Builds a comparison as the JSON export payload.
   *
   * This file doubles as the analysis package a reviewer hands to an LLM, so
   * its size is a token budget rather than a detail. See withoutSourceText()
   * for what is left out of it and why.
   *
   * @param int $run_a
   *   The first run id.
   * @param int $run_b
   *   The second run id.
   *
   * @return array
   *   The comparison payload: both runs' meta, the summary, and the pairs.
   */
  public function comparisonJson(int $run_a, int $run_b): array {
    $data = $this->runComparator->compare($run_a, $run_b);

    return [
      'run_a' => $data['run_a'],
      'run_b' => $data['run_b'],
      'summary' => $data['summary'],
      'pairs' => array_map([$this, 'withoutSourceText'], $data['pairs']),
    ];
  }

  /**
   * Builds a comparison as a spreadsheet-friendly CSV body.
   *
   * @param int $run_a
   *   The first run id.
   * @param int $run_b
   *   The second run id.
   *
   * @return string
   *   The CSV file body, including the leading BOM.
   */
  public function comparisonCsv(int $run_a, int $run_b): string {
    $data = $this->runComparator->compare($run_a, $run_b);

    $rows = [];
    foreach ($data['pairs'] as $pair) {
      $a = $pair['a'];
      $b = $pair['b'];
      $overlap = $pair['citation_overlap'];
      $rows[] = [
        $pair['question'],
        $pair['status'],
        $a['answer'] ?? '',
        $b['answer'] ?? '',
        (string) ($a['error'] ?? ''),
        (string) ($b['error'] ?? ''),
        (string) ($a['cited'] ?? ''),
        (string) ($b['cited'] ?? ''),
        (string) ($a['len'] ?? ''),
        (string) ($b['len'] ?? ''),
        $this->joinSourceUrls($overlap['both']),
        $this->joinSourceUrls($overlap['only_a']),
        $this->joinSourceUrls($overlap['only_b']),
      ];
    }

    return $this->buildCsv(self::COMPARISON_CSV_HEADER, $rows);
  }

  /**
   * Loads a run's result rows in delta order.
   *
   * @param int $run_id
   *   The run id.
   *
   * @return object[]
   *   Result rows, each with question, answer, citations and error properties.
   */
  protected function loadResultRows(int $run_id): array {
    return $this->database->query(
      'SELECT question, answer, citations, error FROM {ys_ai_tester_result}
       WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();
  }

  /**
   * Writes a header and rows into a hardened, Excel-safe CSV body.
   *
   * Prepends a UTF-8 BOM so Excel renders non-ASCII characters correctly rather
   * than as mojibake, and runs every cell through csvCell() to neutralize
   * spreadsheet formula injection. Multiline answers are quoted by fputcsv and
   * stay in one cell.
   *
   * Both CSV exports go through here. They previously each carried their own
   * copy of this loop, and had already drifted: the comparison export was
   * missing the BOM, so an answer containing a curly quote or an em dash -
   * which assistant answers routinely do - opened as mojibake in Excel.
   *
   * @param array $header
   *   The header row, written verbatim; these are code-controlled literals.
   * @param array $rows
   *   Rows of already-ordered cell values.
   *
   * @return string
   *   The CSV file body, including the leading BOM.
   */
  protected function buildCsv(array $header, array $rows): string {
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
      fputcsv($handle, array_map([$this, 'csvCell'], $row));
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return "\xEF\xBB\xBF" . $csv;
  }

  /**
   * Neutralizes spreadsheet formula injection in a CSV cell.
   *
   * Cells beginning with =, +, -, @ can be executed as formulas by a
   * spreadsheet, including when the trigger hides behind leading whitespace or
   * control characters. Prefixing a single quote forces the cell to be text.
   */
  protected function csvCell(string $value): string {
    if ($value === '') {
      return $value;
    }

    $trimmed = ltrim($value, " \t\r\n");
    if (in_array($value[0], ["\t", "\r", "\n"], TRUE)
      || ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], TRUE))) {
      return "'" . $value;
    }
    return $value;
  }

  /**
   * Joins citation URLs for a CSV cell.
   */
  protected function joinSourceUrls(array $sources): string {
    return implode(' | ', array_map(static fn (array $s): string => (string) $s['url'], $sources));
  }

  /**
   * Strips every retrieved source's full text from one comparison pair.
   *
   * CitationFormatter::format() stores both 'content' — the entire retrieved
   * chunk — and 'excerpt', that same text's first 300 characters. Exporting
   * both made the file grow with however long the indexed pages happened to be,
   * multiplied by up to top_k (default 10) sources per question per side, while
   * adding no category of information the excerpt does not already carry. That
   * is what put the download beyond what an LLM will accept in one go.
   *
   * Dropping 'content' bounds every source at its excerpt, so the export scales
   * with the number of questions instead of the length of the site's pages.
   * Nothing reads the field: the compare view never rendered it and the CSV
   * never emitted it. The stored citation keeps it, so this narrows the export
   * only.
   *
   * @param array $pair
   *   One comparison pair from the run comparator.
   *
   * @return array
   *   The pair with 'content' removed from every citation on both sides.
   */
  protected function withoutSourceText(array $pair): array {
    foreach (['a', 'b'] as $key) {
      // A question asked in only one run has a null side, which must stay null:
      // the prompt reads an absent side as "not asked in this run".
      if (!isset($pair[$key]['citations'])) {
        continue;
      }
      foreach (array_keys($pair[$key]['citations']) as $index) {
        unset($pair[$key]['citations'][$index]['content']);
      }
    }
    return $pair;
  }

}
