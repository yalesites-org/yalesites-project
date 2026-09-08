<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

/**
 * Timing policy for retrying and pacing the tester's upstream requests.
 *
 * Deliberately knows nothing about exceptions - AiTesterFailure decides whether
 * a failure is worth retrying, this decides only how long to wait. Keeping the
 * two apart is what lets both be unit tested without constructing a request.
 *
 * Every value here is bounded, for one reason: these waits happen inside a
 * batch request, alongside the question's own LLM latency, and the hosting
 * platform terminates a web request at 120 seconds. An unbounded backoff would
 * trade one failed question for a whole killed batch.
 *
 * This is the tester's policy and only the tester's. The live chat widget
 * answers one question per visitor and cannot burst, already rate limits it
 * per IP, and its provider documents a deliberate no-sleep contract so that a
 * request never holds a PHP-FPM worker while waiting. Pacing belongs here,
 * where the burst actually is.
 */
class AiTesterRetry {

  /**
   * Attempts allowed per question, including the first.
   *
   * Three means one initial call plus two retries.
   */
  const MAX_ATTEMPTS = 3;

  /**
   * First retry's wait, in milliseconds. Doubles per attempt.
   */
  const BASE_DELAY_MS = 1000;

  /**
   * Ceiling on the computed exponential wait, in milliseconds.
   */
  const MAX_BACKOFF_MS = 8000;

  /**
   * Ceiling on an honored Retry-After, in milliseconds.
   *
   * A gateway asking for five minutes gets waited on for ten seconds instead:
   * obeying it literally would stall the batch request until the platform
   * killed it, losing the whole run rather than one question.
   */
  const MAX_RETRY_AFTER_MS = 10000;

  /**
   * Maximum random spread added to a computed wait, in milliseconds.
   *
   * Keeps a run that tripped a per-minute ceiling from retrying every question
   * in lockstep and tripping it again together.
   */
  const JITTER_MS = 250;

  /**
   * Default pause between questions, in milliseconds.
   *
   * Small on purpose. Each question already costs seconds of model latency, so
   * this is a rounding error against a run's total time (about 50 seconds
   * across a hundred questions) while still giving a real knob to slow a run
   * down when an upstream ceiling is suspected.
   */
  const DEFAULT_QUESTION_DELAY_MS = 500;

  /**
   * Ceiling on the configured pause between questions, in milliseconds.
   */
  const MAX_QUESTION_DELAY_MS = 10000;

  /**
   * Ceiling on the total time one question may spend waiting to retry, in ms.
   *
   * The per-attempt caps above are not enough on their own. Two retries each
   * honouring a Retry-After capped at ::MAX_RETRY_AFTER_MS would sleep for 20
   * seconds, and with the pause between questions also set to its maximum that
   * is 30 seconds of a 120-second request budget spent not working - before the
   * three real upstream calls have had any of it. Past this budget the question
   * gives up instead, because failing one question is the smaller loss than
   * having the platform kill the request and take the rest of the batch with
   * it.
   */
  const MAX_TOTAL_RETRY_WAIT_MS = 12000;

  /**
   * Returns how long to wait before the next attempt at a question.
   *
   * @param int $attempt
   *   The attempt that just failed, 1-based.
   * @param int|null $retry_after_ms
   *   What the response's Retry-After header asked for, or NULL when it gave no
   *   usable hint.
   * @param float $jitter
   *   A 0-1 fraction of ::JITTER_MS to add. Passed in rather than generated
   *   here so the calculation stays deterministic under test.
   *
   * @return int
   *   Milliseconds to wait.
   */
  public static function backoffMs(int $attempt, ?int $retry_after_ms, float $jitter): int {
    // A server that said how long to wait knows better than a guess - within
    // the bound above.
    if ($retry_after_ms !== NULL) {
      return max(0, min($retry_after_ms, self::MAX_RETRY_AFTER_MS));
    }

    $base = min(
      self::BASE_DELAY_MS * (2 ** max(0, $attempt - 1)),
      self::MAX_BACKOFF_MS
    );

    return (int) $base + (int) round($jitter * self::JITTER_MS);
  }

  /**
   * Returns a random jitter fraction for ::backoffMs().
   *
   * @return float
   *   A fraction between 0 and 1.
   */
  public static function jitter(): float {
    return mt_rand(0, 100) / 100;
  }

  /**
   * Returns whether one more wait of this length fits the question's budget.
   *
   * @param int $waited_ms
   *   How long this question has already spent waiting to retry.
   * @param int $next_wait_ms
   *   How long the next wait would be.
   *
   * @return bool
   *   TRUE when the wait fits within ::MAX_TOTAL_RETRY_WAIT_MS.
   */
  public static function withinWaitBudget(int $waited_ms, int $next_wait_ms): bool {
    return ($waited_ms + $next_wait_ms) <= self::MAX_TOTAL_RETRY_WAIT_MS;
  }

  /**
   * Resolves the configured pause between questions.
   *
   * Config can hold anything - an unset key on a site that predates the
   * setting, a numeric string from a form, a value hand-edited into YAML - so
   * the value is clamped rather than trusted. A fat-fingered number cannot
   * stall every run on the site.
   *
   * @param mixed $configured
   *   The raw config value.
   *
   * @return int
   *   Milliseconds to pause, between 0 and ::MAX_QUESTION_DELAY_MS.
   */
  public static function questionDelayMs(mixed $configured): int {
    if ($configured === NULL || !is_numeric($configured)) {
      return self::DEFAULT_QUESTION_DELAY_MS;
    }

    return max(0, min((int) $configured, self::MAX_QUESTION_DELAY_MS));
  }

}
