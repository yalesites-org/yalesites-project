<?php

namespace Drupal\ys_migrate\Form;

/**
 * Shared batch_set() wrapper for the CSV import forms.
 *
 * Isolated so tests can verify the batch definition a form's submitForm()
 * builds without going through the real batch_set(), which needs a full
 * Drupal batch/session stack unavailable in a unit test.
 */
trait BatchSubmitTrait {

  /**
   * Hands the batch definition to Drupal's Batch API.
   *
   * @param array $batch
   *   A batch definition suitable for batch_set().
   */
  protected function setBatch(array $batch) {
    batch_set($batch);
  }

}
