<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\Core\Database\Connection;
use Drupal\ys_ai_tester\Form\AiTesterForm;

/**
 * Reports how much of a run was actually answered.
 *
 * A run's question list lives in its source_content and its answers live one
 * row per question in ys_ai_tester_result, so "what is still missing" is the
 * difference between the two. That difference is what lets an interrupted run
 * be finished in place rather than re-asked from the top: a run that died at
 * question 120 of 160 needs 40 questions, not 160.
 *
 * The stored deltas are the authority, never the run's own question_count. A
 * batch operation that ran twice writes two rows for one delta - which has
 * happened in production - so counting rows would understate what is missing
 * and skip questions.
 */
class RunProgress {

  /**
   * Constructs the run progress reader.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected Connection $database,
  ) {
  }

  /**
   * Returns the questions of a run that have no recorded attempt.
   *
   * @param int $run_id
   *   The run to inspect.
   * @param string $source_content
   *   The run's stored question list.
   *
   * @return array
   *   Unanswered questions keyed by their delta within the run, empty when the
   *   run has an attempt recorded for every question.
   */
  public function missingQuestions(int $run_id, string $source_content): array {
    return static::missing(
      AiTesterForm::parseQuestionLines($source_content),
      $this->attemptedDeltas($run_id)
    );
  }

  /**
   * Returns the deltas of a run that already have a row.
   *
   * Includes deltas recorded with an error: the question was asked and its
   * outcome stored, so resuming must not ask it again. Re-asking a question
   * that failed is what the rerun action is for, and keeping resume to
   * never-attempted deltas is what makes it insert-only - it never has to
   * decide whether to overwrite an existing answer.
   *
   * @param int $run_id
   *   The run to inspect.
   *
   * @return int[]
   *   The distinct deltas with a stored row.
   */
  public function attemptedDeltas(int $run_id): array {
    $deltas = $this->database->select('ys_ai_tester_result', 'r')
      ->distinct()
      ->fields('r', ['delta'])
      ->condition('run_id', $run_id)
      ->execute()
      ->fetchCol();

    return array_map('intval', $deltas);
  }

  /**
   * Counts what a run has stored, for deriving its status.
   *
   * Counted over distinct deltas rather than rows so a duplicated batch
   * operation cannot inflate either number.
   *
   * @param int $run_id
   *   The run to inspect.
   *
   * @return array
   *   An array with 'attempted' (deltas with a row) and 'errors' (deltas whose
   *   every row recorded an error).
   */
  public function storedProgress(int $run_id): array {
    $rows = $this->database->query(
      'SELECT delta, MIN(COALESCE(error, :empty)) AS best FROM {ys_ai_tester_result} WHERE run_id = :run GROUP BY delta',
      [':empty' => '', ':run' => $run_id]
    )->fetchAll();

    $errors = 0;
    foreach ($rows as $row) {
      // MIN() over the delta's rows returns '' when any attempt succeeded, so a
      // delta only counts as an error when every attempt at it failed.
      if ((string) $row->best !== '') {
        $errors++;
      }
    }

    return ['attempted' => count($rows), 'errors' => $errors];
  }

  /**
   * Counts attempted questions for several runs at once.
   *
   * Exists so the run list can decide which rows offer a Resume link without
   * loading every row's source_content, which is a big blob. The listing
   * compares this against the run's stored question_count; the resume form
   * itself re-derives the outstanding questions from source_content and
   * refuses if there are none, so an inaccurate question_count can only ever
   * offer a link that then declines, never skip a question.
   *
   * @param int[] $run_ids
   *   The runs to count.
   *
   * @return array
   *   Distinct attempted delta counts keyed by run id. Runs with no rows at all
   *   are absent rather than zero.
   */
  public function attemptedCounts(array $run_ids): array {
    if ($run_ids === []) {
      return [];
    }

    $rows = $this->database->query(
      'SELECT run_id, COUNT(DISTINCT delta) AS attempted FROM {ys_ai_tester_result} WHERE run_id IN (:ids[]) GROUP BY run_id',
      [':ids[]' => array_map('intval', $run_ids)]
    )->fetchAll();

    $counts = [];
    foreach ($rows as $row) {
      $counts[(int) $row->run_id] = (int) $row->attempted;
    }

    return $counts;
  }

  /**
   * Picks the questions with no stored delta.
   *
   * Takes the parsed list and the stored deltas rather than querying, so the
   * difference is testable without a database - the same split RunComparator
   * and StaleRunReconciler use.
   *
   * @param array $questions
   *   The run's questions, in delta order.
   * @param int[] $stored_deltas
   *   Deltas that already have a row.
   *
   * @return array
   *   The unanswered questions, keyed by delta.
   */
  public static function missing(array $questions, array $stored_deltas): array {
    $stored = array_flip(array_map('intval', $stored_deltas));

    $missing = [];
    foreach ($questions as $delta => $question) {
      if (!isset($stored[$delta])) {
        $missing[$delta] = $question;
      }
    }

    return $missing;
  }

}
