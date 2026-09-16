<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester;

/**
 * Answers a tester question with one assistant implementation.
 *
 * This is the seam that lets the tester run the same question list against more
 * than one assistant. Implementations register themselves with the
 * `ys_ai_tester.answer_backend` service tag, so a backend appears and
 * disappears with the module that provides it, and no tester-core class needs
 * to know a specific backend exists.
 *
 * An implementation returns the assistant's answer and its raw retrieved
 * sources. Normalizing those into the tester's stored citation shape is the
 * batch's job, not a backend's — so a backend cannot accidentally store a shape
 * `RunComparator` would silently miscount.
 */
interface AnswerBackendInterface {

  /**
   * The backend a run is attributed to when none was recorded.
   *
   * Runs created before the tester supported more than one assistant were all
   * answered by Beacon, so this is also the value the run table backfills to.
   */
  const DEFAULT_ID = 'beacon';

  /**
   * Returns the stable machine id stored on a run.
   *
   * @return string
   *   The backend id, e.g. 'beacon'.
   */
  public function id(): string;

  /**
   * Returns the human-readable assistant name.
   *
   * @return string|\Stringable
   *   The label, shown in the run history and the comparison view.
   */
  public function label(): string|\Stringable;

  /**
   * Returns whether this backend can answer a question right now.
   *
   * An unavailable backend is not offered for new runs, but stays resolvable so
   * runs it already produced remain viewable and correctly labelled.
   *
   * @return bool
   *   TRUE when the backend is configured and usable.
   */
  public function isAvailable(): bool;

  /**
   * Answers one question.
   *
   * @param string $question
   *   The question text.
   *
   * @return array
   *   An array with two keys: 'answer' (string) and 'citations' (the sources
   *   the assistant retrieved, in the Azure "on your data" shape that
   *   \Drupal\ys_beacon\Service\CitationFormatter consumes — the batch formats
   *   them before storing).
   *
   * @throws \Throwable
   *   When the assistant cannot be reached or fails to answer. The batch
   *   records the failure against the question and continues.
   */
  public function answer(string $question): array;

}
