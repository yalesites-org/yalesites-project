<?php

namespace Drupal\ys_asset\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class AssetSettingsForm.
 *
 * @package Drupal\ys_asset\Form
 * @ingroup ys_asset
 */
class AssetSettingsForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'ys_asset_settings';
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Empty implementation of the abstract submit class.
  }

  /**
   * Define the form used for Asset settings.
   *
   * @return array
   *   Form definition array.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['asset_settings']['#markup'] = 'Settings form for Asset Content Entity. Manage field settings here.';
    return $form;
  }

}
