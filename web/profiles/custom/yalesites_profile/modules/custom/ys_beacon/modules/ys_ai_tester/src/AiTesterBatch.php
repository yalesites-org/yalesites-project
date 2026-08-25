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

    self::pace($delta);

    try {
      $backend = \Drupal::service('ys_ai_tester.answer_backend_registry')
        ->getAvailable($backend_id);
      if ($backend === NULL) {
        throw new \RuntimeException(sprintf('The "%s" assistant is not available on this site.', $backend_id));
      }
      $result = self::answerWithRetry($backend, $question, $run_id, $delta, $backend_id);
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
      // The row stores the plain message, which is what the run view shows in
      // its per-question error cell. The log gets the full detail instead:
      // status code, correlation headers, and the response body these clients
      // otherwise discard.
      $error = $e->getMessage();
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester error on run @run question @delta (@backend): @detail',
        [
          '@run' => $run_id,
          '@delta' => $delta,
          '@backend' => $backend_id,
          '@detail' => AiTesterFailure::describe($e),
        ]
      );
      // Keyed by delta so a question whose answer and whose DB write both fail
      // is still one failed question, not two — an inflated count would push a
      // partial run over into 'failed'.
      $context['results']['failed_deltas'][$delta] = $delta;
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
      $context['results']['failed_deltas'][$delta] = $delta;
    }

    // The submission's heartbeat, written even when the question failed: what
    // it records is that this batch is still alive, not that it is succeeding.
    // StaleRunReconciler reads it to tell a dead batch from a slow one, so it
    // has to advance on every operation or a run of nothing but failures would
    // be reconciled out from under itself.
    self::touch($run_id);

    $context['results']['run_id'] = $run_id;
    // Counted here rather than derived from the run's question_count, so the
    // status a partly-processed batch reports describes what actually ran.
    $context['results']['processed'] = ($context['results']['processed'] ?? 0) + 1;
    $context['message'] = t('Processing question @num...', ['@num' => $delta + 1]);
  }

  /**
   * Records that a submission just made progress.
   *
   * Deliberately refreshes every processing run of the submission, not only the
   * one that answered. A "run both assistants" submission inserts one row per
   * assistant in a single request and then queues one batch set each, and
   * Drupal runs those sets in sequence - so the second run writes nothing of
   * its own until every question of the first has been answered. Heartbeating
   * only the answering run would leave its sibling looking silent for that
   * whole time, and StaleRunReconciler would fail a run that had not started
   * yet: a 100-question first run at the observed few seconds per question is
   * already past ::STALE_AFTER_SECONDS.
   *
   * Runs of one submission are identified by sharing a uid and an insert
   * timestamp, both already stored. Two separate submissions colliding on both
   * would only keep runs that are all live alive for longer, which is the safe
   * direction to be wrong in.
   *
   * Failures here are swallowed deliberately: a heartbeat that does not land
   * must not fail a question that was answered. The cost of losing one is only
   * that a live run looks idle for longer, and the next question rewrites it.
   *
   * @param int $run_id
   *   The run that made progress.
   */
  protected static function touch(int $run_id): void {
    try {
      $database = \Drupal::database();
      $submission = $database->select('ys_ai_tester_run', 'r')
        ->fields('r', ['uid', 'created'])
        ->condition('id', $run_id)
        ->execute()
        ->fetchObject();
      if (!$submission) {
        return;
      }

      $database->update('ys_ai_tester_run')
        ->fields(['changed' => \Drupal::time()->getRequestTime()])
        ->condition('status', 'processing')
        ->condition('uid', (int) $submission->uid)
        ->condition('created', (int) $submission->created)
        ->execute();
    }
    catch (\Throwable $e) {
      \Drupal::logger('ys_ai_tester')->warning(
        'Could not record progress for AI tester run @run: @msg',
        ['@run' => $run_id, '@msg' => $e->getMessage()]
      );
    }
  }

  /**
   * Asks one question, retrying only failures that a retry could clear.
   *
   * A run against both assistants makes roughly 200 back-to-back calls to
   * outside services. At that volume a transient fault somewhere is more likely
   * than not, and without this every one of them permanently failed a question
   * and marked the whole run failed.
   *
   * @param \Drupal\ys_ai_tester\AnswerBackendInterface $backend
   *   The assistant answering.
   * @param string $question
   *   The question text.
   * @param int $run_id
   *   The run ID, for logging.
   * @param int $delta
   *   Position of this question within the run, for logging.
   * @param string $backend_id
   *   The assistant id, for logging.
   *
   * @return array
   *   The backend's ['answer', 'citations'] result.
   *
   * @throws \Throwable
   *   The last failure, when attempts are exhausted or the failure is one that
   *   retrying cannot clear.
   */
  protected static function answerWithRetry(AnswerBackendInterface $backend, string $question, int $run_id, int $delta, string $backend_id): array {
    $waited_ms = 0;

    for ($attempt = 1;; $attempt++) {
      try {
        return $backend->answer($question);
      }
      catch (\Throwable $e) {
        // A deterministic failure — a filtered completion, a malformed request,
        // a missing assistant — fails identically however many times it is
        // sent, so it is surfaced on the first attempt rather than the third.
        if ($attempt >= AiTesterRetry::MAX_ATTEMPTS || !AiTesterFailure::isTransient($e)) {
          throw $e;
        }

        $wait_ms = AiTesterRetry::backoffMs(
          $attempt,
          AiTesterFailure::retryAfterMs($e),
          AiTesterRetry::jitter()
        );

        // A gateway can ask to be retried later than this question can afford
        // to wait. Giving up on the question is the smaller loss: sleeping past
        // the platform's request ceiling would have the whole batch request
        // killed, losing every question still queued behind this one.
        if (!AiTesterRetry::withinWaitBudget($waited_ms, $wait_ms)) {
          throw $e;
        }
        $waited_ms += $wait_ms;

        // Logged even though the retry may succeed: a run that only passes on
        // its second attempt is a warning that an upstream ceiling is close,
        // and that is worth seeing before it starts failing outright.
        \Drupal::logger('ys_ai_tester')->warning(
          'AI tester retrying run @run question @delta (@backend) after a transient failure — attempt @attempt of @max, waiting @wait ms: @detail',
          [
            '@run' => $run_id,
            '@delta' => $delta,
            '@backend' => $backend_id,
            '@attempt' => $attempt,
            '@max' => AiTesterRetry::MAX_ATTEMPTS,
            '@wait' => $wait_ms,
            '@detail' => AiTesterFailure::describe($e),
          ]
        );

        usleep($wait_ms * 1000);
      }
    }
  }

  /**
   * Pauses before a question so a long run does not burst the upstream APIs.
   *
   * Skipped for the first question of a run, where there is nothing to pace
   * against.
   *
   * @param int $delta
   *   Position of this question within the run (0-based).
   */
  protected static function pace(int $delta): void {
    if ($delta < 1) {
      return;
    }

    $delay_ms = AiTesterRetry::questionDelayMs(
      \Drupal::config('ys_beacon.settings')->get('ai_tester_question_delay_ms')
    );
    if ($delay_ms > 0) {
      usleep($delay_ms * 1000);
    }
  }

  /**
   * Derives the status a finished run is recorded with.
   *
   * The distinction 'partial' draws is the point of it: before it existed, one
   * transient failure out of a hundred questions recorded the same 'failed' as
   * a run where nothing was answered at all, so a 99-percent-successful run
   * read as broken and its results got thrown away.
   *
   * @param bool $success
   *   TRUE when the batch itself completed, as Drupal reports it.
   * @param int $error_count
   *   How many questions were recorded with an error.
   * @param int $processed
   *   How many questions the batch actually processed.
   *
   * @return string
   *   'complete', 'partial', or 'failed'. All three fit the varchar(32) status
   *   column, so no schema change is involved.
   */
  public static function runStatus(bool $success, int $error_count, int $processed): string {
    // An aborted batch did not finish, whatever the per-question tally says.
    // Nothing processed is likewise not a success.
    if (!$success || $processed < 1) {
      return 'failed';
    }
    if ($error_count < 1) {
      return 'complete';
    }

    // Reserved for a genuine collapse: a bad credential, an assistant that is
    // gone, an upstream down for the whole run.
    return $error_count >= $processed ? 'failed' : 'partial';
  }

  /**
   * Derives the status of a run that was finished in place by a resume.
   *
   * ::runStatus() cannot be reused here. It reads the tallies one batch
   * accumulated, and a resume's batch only ever processed the questions that
   * were missing - so a resume of 40 questions out of 160 would report a run
   * "complete" on the strength of 40 answers. This decides from what the run
   * has stored in total instead.
   *
   * @param bool $success
   *   TRUE when the resume batch itself completed, as Drupal reports it.
   * @param int $expected
   *   How many questions the run's list holds.
   * @param int $attempted
   *   How many distinct questions have a stored outcome.
   * @param int $error_count
   *   How many of those recorded only errors.
   *
   * @return string
   *   'complete', 'partial', or 'failed'.
   */
  public static function wholeRunStatus(bool $success, int $expected, int $attempted, int $error_count): string {
    // Nothing stored, or nothing that worked: the run has no usable answers,
    // which is what 'failed' means everywhere else in the tester.
    if ($attempted < 1 || $error_count >= $attempted) {
      return 'failed';
    }

    // Only a run whose every question is answered cleanly, by a batch that
    // actually finished, is complete. Anything short of that is still worth
    // reading, so it stays resumable rather than being called failed.
    if ($success && $attempted >= $expected && $error_count < 1) {
      return 'complete';
    }

    return 'partial';
  }

  /**
   * Batch finished callback for a resume — restatuses the whole run.
   *
   * @param bool $success
   *   TRUE if no fatal errors occurred during the batch.
   * @param array $results
   *   Values accumulated in $context['results'] across operations.
   * @param array $operations
   *   Any unprocessed operations (non-empty only on failure).
   */
  public static function resumeFinished(bool $success, array $results, array $operations): void {
    $run_id = $results['run_id'] ?? NULL;
    if (!$run_id) {
      \Drupal::logger('ys_ai_tester')->error(
        'AI tester resumeFinished() called with no run_id — run status not updated.'
      );
      return;
    }

    $database = \Drupal::database();
    $source_content = (string) $database->query(
      'SELECT source_content FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchField();

    $run_progress = \Drupal::service('ys_ai_tester.run_progress');
    $progress = $run_progress->storedProgress((int) $run_id);
    // The outstanding questions are counted as a set difference rather than as
    // expected-minus-attempted, so a run can only be called finished when every
    // delta in its list really has a row. A count comparison would let a stray
    // delta stand in for a missing one.
    $remaining = count($run_progress->missingQuestions((int) $run_id, $source_content));
    $expected = $progress['attempted'] + $remaining;
    $status = self::wholeRunStatus($success, $expected, $progress['attempted'], $progress['errors']);

    $database->update('ys_ai_tester_run')
      ->fields(['status' => $status])
      ->condition('id', $run_id)
      ->execute();
    if ($status === 'complete') {
      \Drupal::messenger()->addStatus(t('Run #@id is now complete — all @total questions are answered.', [
        '@id' => $run_id,
        '@total' => $expected,
      ]));
      return;
    }

    if ($remaining > 0) {
      // Naming what is left is what keeps resume usable more than once: the
      // connection can drop again, and the next resume picks up from here.
      \Drupal::messenger()->addWarning(t('Run #@id was not finished — @remaining of @total questions are still unanswered. Resume it again to continue.', [
        '@id' => $run_id,
        '@remaining' => $remaining,
        '@total' => $expected,
      ]));
      return;
    }

    \Drupal::messenger()->addWarning(t('Every question in run #@id has been asked, but @errors could not be answered. Check the Drupal logs for the cause.', [
      '@id' => $run_id,
      '@errors' => $progress['errors'],
    ]));
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
    $failed_deltas = $results['failed_deltas'] ?? [];
    $processed = (int) ($results['processed'] ?? 0);
    $status = self::runStatus($success, count($failed_deltas), $processed);

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

    if ($status === 'complete') {
      \Drupal::messenger()->addStatus(t('All questions processed successfully.'));
      return;
    }

    if ($status === 'partial') {
      // Naming the questions is what makes a partial run actionable: they can
      // be re-asked individually instead of re-running all hundred.
      \Drupal::messenger()->addWarning(t(
        '@failed of @total questions could not be answered (question numbers: @list). The rest of the run completed and its answers are saved. Check the Drupal logs for the cause.',
        [
          '@failed' => count($failed_deltas),
          '@total' => $processed,
          // Deltas are 0-based; the progress message and the run view both
          // number questions from 1, so the label matches what a user saw.
          '@list' => implode(', ', array_map(static fn($delta) => $delta + 1, $failed_deltas)),
        ]
      ));
      return;
    }

    \Drupal::messenger()->addWarning(t('This run failed — no questions were answered. Check the Drupal logs for details.'));
  }

}
