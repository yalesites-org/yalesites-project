<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

/**
 * Static batch callbacks for the AI Tester.
 *
 * Methods must be static — Drupal Batch API serializes callback names as
 * strings and calls them in potentially separate PHP requests.
 */
class AiTesterBatch {

  /**
   * Processes a single question through one assistant.
   *
   * A failure here is contained to its own question: the error is logged and
   * recorded against the row so the rest of the batch still runs.
   *
   * @param int $run_id
   *   The run ID to write the result into.
   * @param string $question
   *   The question text.
   * @param int $delta
   *   Position of this question within the run (0-based).
   * @param string $backend_id
   *   The id of the assistant answering this run.
   * @param array $context
   *   Batch context array (passed by reference by Drupal).
   */
  public static function processQuestion(int $run_id, string $question, int $delta, string $backend_id, array &$context): void {
    $answer = '';
    // Citations are derived per question from this one answer, so there is no
    // cross-question leak: nothing carries over between batch operations.
    $citations = [];
    $error = '';

    try {
      $backend = \Drupal::service('ys_ai_tester.answer_backend_registry')
        ->getAvailable($backend_id);
      if ($backend === NULL) {
        throw new \RuntimeException(sprintf('The "%s" assistant is not available on this site.', $backend_id));
      }
      $result = $backend->answer($question);
      $answer = $result['answer'];
      // Normalizing here rather than in each backend is what makes the stored
      // shape uniform: the formatter owns marker parsing, de-duplication, and
      // the cited flag, so every assistant's rows carry a comparable cited
      // count no matter who answered. The chat widget derives the same fields
      // client-side.
      $citations = \Drupal::service('ys_beacon.citation_formatter')
        ->format($answer, $result['citations']);
    }
    catch (\Throwable $e) {
      $error = $e->getMessage();
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester error on run @run question @delta (@backend): @msg',
        [
          '@run' => $run_id,
          '@delta' => $delta,
          '@backend' => $backend_id,
          '@msg' => $error,
        ]
      );
      $context['results']['errors'][] = "Question {$delta}: " . $error;
    }

    try {
      \Drupal::database()->insert('ys_ai_tester_result')
        ->fields([
          'run_id' => $run_id,
          'delta' => $delta,
          'question' => $question,
          'answer' => $answer,
          'citations' => json_encode($citations),
          'error' => $error,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester DB write failed on run @run question @delta: @msg',
        ['@run' => $run_id, '@delta' => $delta, '@msg' => $e->getMessage()]
      );
      $context['results']['errors'][] = "Question {$delta}: DB write failed.";
    }

    $context['results']['run_id'] = $run_id;
    $context['message'] = t('Processing question @num...', ['@num' => $delta + 1]);
  }

  /**
   * Batch finished callback — updates run status and sets a user message.
   *
   * @param bool $success
   *   TRUE if no fatal errors occurred during the batch.
   * @param array $results
   *   Values accumulated in $context['results'] across operations.
   * @param array $operations
   *   Any unprocessed operations (non-empty only on failure).
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $run_id = $results['run_id'] ?? NULL;
    $has_errors = !empty($results['errors']);
    $status = ($success && !$has_errors) ? 'complete' : 'failed';

    if ($run_id) {
      \Drupal::database()->update('ys_ai_tester_run')
        ->fields(['status' => $status])
        ->condition('id', $run_id)
        ->execute();
    }
    else {
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester finished() called with no run_id — run status not updated.'
      );
    }

    if ($success && !$has_errors) {
      \Drupal::messenger()->addStatus(t('All questions processed successfully.'));
    }
    else {
      \Drupal::messenger()->addWarning(t('Some questions encountered errors. Check the Drupal logs for details.'));
    }
  }

}
