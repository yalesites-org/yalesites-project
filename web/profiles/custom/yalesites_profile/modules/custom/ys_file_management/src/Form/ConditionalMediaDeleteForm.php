<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_file_delete\Form\MediaDeleteForm;

/**
 * Provides a conditional media delete form.
 *
 * This form extends the media_file_delete module's MediaDeleteForm to
 * conditionally show file deletion options based on user permissions.
 *
 * Users with 'manage media files' permission see the file deletion checkbox
 * and usage warnings. Users without this permission see standard media
 * deletion (no file deletion options).
 */
class ConditionalMediaDeleteForm extends MediaDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if the current user has the 'manage media files' permission.
    if ($this->currentUser->hasPermission('manage media files')) {
      // User is a File Manager or Platform Admin.
      // Show the file deletion checkbox and usage warnings.
      return parent::buildForm($form, $form_state);
    }

    // User does not have file management permissions.
    // Use the standard Drupal media deletion form without file deletion
    // options by calling the grandparent class (ContentEntityDeleteForm).
    return ContentEntityDeleteForm::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Only process file deletion if user has 'manage media files' permission.
    if ($this->currentUser->hasPermission('manage media files')) {
      // Let the parent class handle file deletion logic.
      parent::submitForm($form, $form_state);
    }
    else {
      // Use standard media deletion (no file deletion).
      ContentEntityDeleteForm::submitForm($form, $form_state);
    }
  }

}
