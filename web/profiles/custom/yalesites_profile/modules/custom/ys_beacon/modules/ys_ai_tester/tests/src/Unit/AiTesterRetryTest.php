<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterRetry;

/**
 * Tests the tester's retry timing and inter-question pacing policy.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\AiTesterRetry
 *
 * @group ys_beacon
 */
class AiTesterRetryTest extends UnitTestCase {

  /**
   * @covers ::backoffMs
   * @dataProvider provideBackoffCases
   */
  public function testBackoffMs(int $attempt, ?int $retry_after_ms, float $jitter, int $expected): void {
    $this->assertSame($expected, AiTesterRetry::backoffMs($attempt, $retry_after_ms, $jitter));
  }

  /**
   * Attempt number, Retry-After hint, jitter fraction, milliseconds to wait.
   *
   * Every value here has to stay small: this sleep happens inside a batch
   * request, and Pantheon terminates a web request at 120 seconds. A retry
   * policy that could outlast that ceiling would trade one failed question for
   * a whole killed batch.
   */
  public static function provideBackoffCases(): array {
    return [
      'first retry, no jitter' => [1, NULL, 0.0, 1000],
      'second retry doubles' => [2, NULL, 0.0, 2000],
      'full jitter adds a bounded spread' => [1, NULL, 1.0, 1250],
      'half jitter' => [2, NULL, 0.5, 2125],
      // A server-supplied hint wins over the computed backoff.
      'retry-after honored' => [1, 2000, 0.0, 2000],
      'retry-after of zero is honored' => [1, 0, 0.0, 0],
      // ... but never unboundedly: a gateway asking for five minutes would
      // otherwise stall the batch request until the platform killed it.
      'absurd retry-after is capped' => [1, 300000, 0.0, 10000],
      // Growth is capped even if MAX_ATTEMPTS is ever raised.
      'exponential growth is capped' => [9, NULL, 0.0, 8000],
    ];
  }

  /**
   * @covers ::backoffMs
   */
  public function testTotalRetryWaitStaysWellInsideTheRequestCeiling(): void {
    $worst = 0;
    for ($attempt = 1; $attempt < AiTesterRetry::MAX_ATTEMPTS; $attempt++) {
      $worst += AiTesterRetry::backoffMs($attempt, NULL, 1.0);
    }

    // The whole retry budget for one question, worst case, plus the capped
    // pacing delay - comfortably short of the 120s platform ceiling that the
    // question's own LLM latency also has to fit inside.
    $this->assertLessThan(10000, $worst);
  }

  /**
   * @covers ::withinWaitBudget
   * @covers ::backoffMs
   */
  public function testWaitIsBoundedEvenWhenEveryResponseAsksForFiveMinutes(): void {
    // The per-attempt cap alone does not bound this: two retries each honouring
    // a Retry-After clamped to MAX_RETRY_AFTER_MS would sleep 20 seconds.
    $waited = 0;
    for ($attempt = 1; $attempt < AiTesterRetry::MAX_ATTEMPTS; $attempt++) {
      $wait = AiTesterRetry::backoffMs($attempt, 300000, 1.0);
      if (!AiTesterRetry::withinWaitBudget($waited, $wait)) {
        break;
      }
      $waited += $wait;
    }

    $this->assertLessThanOrEqual(AiTesterRetry::MAX_TOTAL_RETRY_WAIT_MS, $waited);
    // Worst case overall: this budget plus the largest configurable pause,
    // still leaving the great majority of a 120s request for real work.
    $this->assertLessThan(30000, $waited + AiTesterRetry::MAX_QUESTION_DELAY_MS);
  }

  /**
   * @covers ::withinWaitBudget
   * @dataProvider provideBudgetCases
   */
  public function testWithinWaitBudget(int $waited, int $next, bool $expected): void {
    $this->assertSame($expected, AiTesterRetry::withinWaitBudget($waited, $next));
  }

  /**
   * Already waited, the next wait, and whether it is allowed.
   */
  public static function provideBudgetCases(): array {
    $budget = AiTesterRetry::MAX_TOTAL_RETRY_WAIT_MS;

    return [
      'first wait of a normal run' => [0, 1250, TRUE],
      'exactly on the budget is allowed' => [0, $budget, TRUE],
      'one millisecond over is refused' => [0, $budget + 1, FALSE],
      'second large wait tips it over' => [10000, 10000, FALSE],
      'budget already spent' => [$budget, 1, FALSE],
    ];
  }

  /**
   * @covers ::questionDelayMs
   * @dataProvider provideDelayCases
   */
  public function testQuestionDelayMs(mixed $configured, int $expected): void {
    $this->assertSame($expected, AiTesterRetry::questionDelayMs($configured));
  }

  /**
   * Configured value, and the delay actually applied.
   *
   * Config can hold anything - an unset key, a string from a form, a value
   * hand-edited into a YAML file - so the clamp is what keeps a fat-fingered
   * number from stalling every run on the site.
   */
  public static function provideDelayCases(): array {
    return [
      'unset falls back to the default' => [NULL, AiTesterRetry::DEFAULT_QUESTION_DELAY_MS],
      'zero disables pacing' => [0, 0],
      'a normal value passes through' => [250, 250],
      'numeric string from a form' => ['750', 750],
      'negative clamps to zero' => [-5, 0],
      'absurd value clamps to the ceiling' => [999999, AiTesterRetry::MAX_QUESTION_DELAY_MS],
      'non-numeric falls back to the default' => ['soon', AiTesterRetry::DEFAULT_QUESTION_DELAY_MS],
    ];
  }

  /**
   * @covers ::questionDelayMs
   */
  public function testDefaultPacingDoesNotMeaningfullySlowLongRuns(): void {
    // A hundred-question run is the case in the bug report. The default delay
    // must be a rounding error next to the LLM latency it sits beside, or the
    // cure is worse than the disease.
    $added_seconds = (AiTesterRetry::questionDelayMs(NULL) * 100) / 1000;

    $this->assertLessThanOrEqual(60, $added_seconds);
  }

}
