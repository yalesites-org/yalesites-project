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
   * Processes a single question through the Beacon assistant.
   *
   * @param int $run_id
   *   The run ID to write the result into.
   * @param string $question
   *   The question text.
   * @param int $delta
   *   Position of this question within the run (0-based).
   * @param array $context
   *   Batch context array (passed by reference by Drupal).
   */
  public static function processQuestion(int $run_id, string $question, int $delta, array &$context): void {
    $answer = '';
    // Citations are derived per question from this one answer, so there is no
    // cross-question leak: nothing carries over between batch operations.
    $citations = [];

    try {
      $result = \Drupal::service('ys_beacon.beacon_answer')->answer($question);
      $answer = $result['answer'];
      // The formatter owns marker parsing, de-duplication, and the cited flag
      // for the tester; the chat widget derives the same fields client-side.
      $citations = \Drupal::service('ys_beacon.citation_formatter')
        ->format($answer, $result['citations']);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester error on run @run question @delta: @msg',
        ['@run' => $run_id, '@delta' => $delta, '@msg' => $e->getMessage()]
      );
      $context['results']['errors'][] = "Question {$delta}: " . $e->getMessage();
    }

    try {
      \Drupal::database()->insert('ys_ai_tester_result')
        ->fields([
          'run_id' => $run_id,
          'delta' => $delta,
          'question' => $question,
          'answer' => $answer,
          'citations' => json_encode($citations),
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
