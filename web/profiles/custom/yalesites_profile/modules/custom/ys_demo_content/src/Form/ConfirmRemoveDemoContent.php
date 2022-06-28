<?php

namespace Drupal\ys_demo_content\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_demo_content\DemoContent;

/**
 * Defines a confirmation form to confirm removal of demo content
 */
class ConfirmRemoveDemoContent extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $id = NULL) {
    $this->id = $id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    \Drupal::classResolver()->getInstanceFromDefinition(DemoContent::class)->deleteImportedContent();
    \Drupal::messenger()->addStatus("All demo content removed.");
    $form_state->setRedirect('ys_demo_content.settings');

  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return "confirm_remove_demo_content";
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('ys_demo_content.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove Demo Content?');
  }

  /**
   * {@inheritdoc}
  */
  public function getDescription() {
    return $this->t('Do you want to remove all demo content? This will also remove original demo content <strong>even if it has been edited.</strong> This action cannot be undone.');
  }

}