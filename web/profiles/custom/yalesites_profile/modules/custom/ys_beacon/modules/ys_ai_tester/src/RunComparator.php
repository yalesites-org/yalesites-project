<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\Core\Database\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Compares two AI Tester runs question-by-question.
 *
 * The comparison logic is split in two so it can be reasoned about and unit
 * tested without a database: compareResults() is pure and operates on already
 * loaded result arrays; compare() is the thin loader the controller calls.
 */
class RunComparator {

  /**
   * Constructs the run comparator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Loads two runs and returns their structured comparison.
   *
   * @param int $run_a
   *   The first (older, by convention) run id.
   * @param int $run_b
   *   The second run id.
   *
   * @return array
   *   The comparison structure produced by compareResults().
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When either run id does not exist.
   */
  public function compare(int $run_a, int $run_b): array {
    return $this->compareResults(
      $this->loadRun($run_a),
      $this->loadRun($run_b),
      $this->loadResults($run_a),
      $this->loadResults($run_b),
    );
  }

  /**
   * Compares two runs' results, aligning questions by text.
   *
   * @param array $meta_a
   *   Run A meta: id, created, source_filename, status.
   * @param array $meta_b
   *   Run B meta, same shape.
   * @param array $results_a
   *   Run A results in delta order, each: question, answer, citations (array).
   * @param array $results_b
   *   Run B results, same shape.
   *
   * @return array
   *   A structure with run_a, run_b, pairs, and summary keys.
   */
  public function compareResults(array $meta_a, array $meta_b, array $results_a, array $results_b): array {
    // Queue B's results per trimmed question so duplicate questions pair by
    // occurrence (the Nth "Q" in A matches the Nth "Q" in B).
    $b_by_question = [];
    foreach ($results_b as $result) {
      $b_by_question[trim((string) $result['question'])][] = $result;
    }

    $pairs = [];
    $summary = [
      'total_compared' => 0,
      'differ' => 0,
      'identical' => 0,
      'only_a' => 0,
      'only_b' => 0,
    ];
    $b_pointers = [];

    // Walk A in order, pairing each question with the next unused B match.
    foreach ($results_a as $a) {
      $key = trim((string) $a['question']);
      $pointer = $b_pointers[$key] ?? 0;
      $b = $b_by_question[$key][$pointer] ?? NULL;

      if ($b !== NULL) {
        $b_pointers[$key] = $pointer + 1;
        $status = trim((string) $a['answer']) === trim((string) $b['answer'])
          ? 'identical'
          : 'differs';
        $pairs[] = $this->buildPair($key, $a, $b, $status);
        $summary['total_compared']++;
        $summary[$status === 'identical' ? 'identical' : 'differ']++;
      }
      else {
        $pairs[] = $this->buildPair($key, $a, NULL, 'only_a');
        $summary['only_a']++;
      }
    }

    // Append B questions never matched above. The per-question queues keep B's
    // order; array_slice drops the prefix already consumed by matched pairs.
    foreach ($b_by_question as $key => $queue) {
      foreach (array_slice($queue, $b_pointers[$key] ?? 0) as $b) {
        $pairs[] = $this->buildPair($key, NULL, $b, 'only_b');
        $summary['only_b']++;
      }
    }

    return [
      'run_a' => $this->metaArray($meta_a),
      'run_b' => $this->metaArray($meta_b),
      'pairs' => $pairs,
      'summary' => $summary,
    ];
  }

  /**
   * Builds one comparison pair for a question.
   */
  protected function buildPair(string $question, ?array $a, ?array $b, string $status): array {
    $side_a = $a !== NULL ? $this->side($a) : NULL;
    $side_b = $b !== NULL ? $this->side($b) : NULL;

    return [
      'question' => $question,
      'status' => $status,
      'a' => $side_a,
      'b' => $side_b,
      'len_delta' => ($side_a !== NULL && $side_b !== NULL)
        ? $side_b['len'] - $side_a['len']
        : 0,
      'citation_overlap' => $this->overlap(
        $a['citations'] ?? [],
        $b['citations'] ?? [],
      ),
    ];
  }

  /**
   * Computes the per-side display signals for one result.
   */
  protected function side(array $result): array {
    $answer = (string) ($result['answer'] ?? '');
    $citations = $result['citations'] ?? [];

    $cited = 0;
    foreach ($citations as $citation) {
      if (!empty($citation['cited'])) {
        $cited++;
      }
    }

    return [
      'answer' => $answer,
      'citations' => $citations,
      'len' => mb_strlen($answer),
      'cited' => $cited,
      'retrieved' => count($citations),
      'empty' => trim($answer) === '',
    ];
  }

  /**
   * Partitions two citation lists into shared and run-unique sources by URL.
   */
  protected function overlap(array $citations_a, array $citations_b): array {
    $a_by_url = $this->indexByUrl($citations_a);
    $b_by_url = $this->indexByUrl($citations_b);

    $both = $only_a = $only_b = [];
    foreach ($a_by_url as $url => $citation) {
      $entry = ['url' => $url, 'title' => (string) ($citation['title'] ?? '')];
      if (isset($b_by_url[$url])) {
        $both[] = $entry;
      }
      else {
        $only_a[] = $entry;
      }
    }
    foreach ($b_by_url as $url => $citation) {
      if (!isset($a_by_url[$url])) {
        $only_b[] = ['url' => $url, 'title' => (string) ($citation['title'] ?? '')];
      }
    }

    return ['both' => $both, 'only_a' => $only_a, 'only_b' => $only_b];
  }

  /**
   * Indexes citations by URL, keeping the first per URL and skipping empties.
   */
  protected function indexByUrl(array $citations): array {
    $by_url = [];
    foreach ($citations as $citation) {
      // Sources without a URL are dropped from overlap: they cannot be matched
      // across runs. This intentionally diverges from CitationFormatter, which
      // keeps URL-less sources distinct for display.
      $url = $citation['url'] ?? '';
      if ($url === '') {
        continue;
      }
      $by_url[$url] ??= $citation;
    }
    return $by_url;
  }

  /**
   * Normalizes a run meta row to the keys the comparison exposes.
   */
  protected function metaArray(array $meta): array {
    return [
      'id' => (int) $meta['id'],
      'created' => (int) $meta['created'],
      'source_filename' => (string) $meta['source_filename'],
      'status' => (string) $meta['status'],
    ];
  }

  /**
   * Loads a run meta row, or throws a 404.
   */
  protected function loadRun(int $run_id): array {
    $row = $this->database->query(
      'SELECT id, created, source_filename, status FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchAssoc();

    if (!$row) {
      throw new NotFoundHttpException();
    }
    return $row;
  }

  /**
   * Loads a run's results in delta order with decoded citations.
   */
  protected function loadResults(int $run_id): array {
    $rows = $this->database->query(
      'SELECT question, answer, citations FROM {ys_ai_tester_result} WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

    $results = [];
    foreach ($rows as $row) {
      $results[] = [
        'question' => $row->question,
        'answer' => $row->answer,
        'citations' => json_decode($row->citations ?? '', TRUE) ?: [],
      ];
    }
    return $results;
  }

}
