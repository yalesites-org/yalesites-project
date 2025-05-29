<?php

namespace Drupal\ys_ai\Controller;

/**
 *
 */
class YsAiController {

  /**
   * Returns a render array for the AI content generation form.
   *
   * @return array
   *   A render array containing the form.
   */
  public function aiContentGenerationForm() {
    $form = \Drupal::formBuilder()->getForm('Drupal\ys_ai\Form\YsAiContentGenerationForm');
    return $form;
  }

}
