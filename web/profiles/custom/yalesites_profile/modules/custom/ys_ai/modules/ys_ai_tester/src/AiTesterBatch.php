<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_assistant_api\Entity\AiAssistant;

/**
 * Static batch callbacks for the AI Tester.
 *
 * Methods must be static — Drupal Batch API serializes callback names as
 * strings and calls them in potentially separate PHP requests.
 */
class AiTesterBatch {

  /**
   * Processes a single question through the AI assistant.
   *
   * @param int $run_id
   *   The run ID to write the result into.
   * @param string $assistant_id
   *   The AiAssistant entity ID.
   * @param string $question
   *   The question text.
   * @param int $delta
   *   Position of this question within the run (0-based).
   * @param array $context
   *   Batch context array (passed by reference by Drupal).
   */
  public static function processQuestion(int $run_id, string $assistant_id, string $question, int $delta, array &$context): void {
    $text = '';
    $citations = [];

    try {
      $assistant = \Drupal::entityTypeManager()
        ->getStorage('ai_assistant')
        ->load($assistant_id);

      if (!$assistant instanceof AiAssistant) {
        throw new \Exception("Invalid assistant ID: {$assistant_id}");
      }

      $runner = \Drupal::service('ai_assistant_api.runner');
      // Give each question its own thread so history does not bleed.
      // setThreadsKey must precede setAssistant so the runner's internal
      // guards (which only set threadId when empty) do not overwrite the key.
      $runner->setThreadsKey("tester-{$run_id}-{$delta}");
      $runner->setAssistant($assistant);
      $runner->setUserMessage(new UserMessage($question));
      $runner->setThrowException(TRUE);

      $response = $runner->process();
      $text = $response->getNormalized()->getText();

      preg_match_all('/\[.*?\]\((https?:\/\/[^)]+)\)/', $text, $matches);
      $citations = $matches[1] ?? [];
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
          'answer' => $text,
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

    $context['results']['run_id'] ??= $run_id;
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
