<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media_file_delete\Form\MediaDeleteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a conditional media delete form.
 *
 * This form extends the media_file_delete module's MediaDeleteForm to
 * conditionally show file deletion options based on user permissions.
 *
 * Users with 'manage media files' permission see the file deletion checkbox
 * and usage warnings. Users without this permission see standard media
 * deletion (no file deletion options).
 *
 * The form implements a defense-in-depth security pattern by checking
 * permissions in both buildForm() and submitForm() to prevent unauthorized
 * file deletion even if the form is submitted directly via POST.
 *
 * @see \Drupal\media_file_delete\Form\MediaDeleteForm
 * @see \Drupal\Core\Entity\ContentEntityDeleteForm
 */
class ConditionalMediaDeleteForm extends MediaDeleteForm {

  /**
   * Permission required to manage media files.
   *
   * This permission gates access to file deletion functionality.
   */
  const PERMISSION_MANAGE_FILES = 'manage media files';

  /**
   * The media file deleter service.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileDeleter
   */
  protected $mediaFileDeleter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->mediaFileDeleter = $container->get('ys_file_management.media_file_deleter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Conditionally displays file deletion options based on user permissions.
   *
   * This method implements the first layer of permission checking (UI layer).
   * Users with the 'manage media files' permission see the enhanced form from
   * MediaDeleteForm (parent), while other users see the standard Drupal media
   * deletion form from ContentEntityDeleteForm (grandparent).
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   The modified form array with conditional file deletion options.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if the current user has the 'manage media files' permission.
    if ($this->currentUser->hasPermission(self::PERMISSION_MANAGE_FILES)) {
      // User is a File Manager or has elevated permissions.
      // Show the file deletion checkbox and usage warnings by calling
      // MediaDeleteForm::buildForm() (parent class).
      return parent::buildForm($form, $form_state);
    }

    // User does not have file management permissions.
    // Use the standard Drupal media deletion form without file deletion
    // options by calling ContentEntityDeleteForm::buildForm() directly
    // (grandparent class), which bypasses MediaDeleteForm's file deletion UI.
    return ContentEntityDeleteForm::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Handles form submission with immediate file deletion.
   *
   * This method implements the second layer of permission checking as
   * defense-in-depth security. Even if buildForm() was bypassed via
   * direct POST submission, this ensures unauthorized users cannot
   * delete files.
   *
   * For authorized users with the checkbox checked, files are deleted
   * immediately from the filesystem using FileSystemInterface::delete(),
   * rather than being marked for cron cleanup (Drupal's default behavior
   * via $file->delete()).
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object containing user input.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Defense-in-depth: Re-check permissions even though buildForm() checked.
    // This protects against direct form submission bypassing the UI layer.
    if ($this->currentUser->hasPermission(self::PERMISSION_MANAGE_FILES)) {
      // Get the media entity and its associated file.
      $media = $this->getEntity();
      $file = $this->getFile($media);

      // Check if the user wants to delete the file and file exists.
      if ($file instanceof FileInterface && $form_state->getValue('also_delete_file')) {
        // Delegate file deletion to the service layer.
        // The service handles validation, deletion, error handling, and
        // user feedback. This separates business logic from form handling.
        $this->mediaFileDeleter->deleteFile($file);
      }

      // Delete the media entity by calling ContentEntityDeleteForm directly
      // (grandparent class). This bypasses MediaDeleteForm::submitForm()
      // which would attempt to delete the file again using the cron-based
      // delayed deletion method instead of our immediate deletion above.
      ContentEntityDeleteForm::submitForm($form, $form_state);
    }
    else {
      // User does not have file management permissions.
      // Use standard media deletion (media entity removed, files retained).
      ContentEntityDeleteForm::submitForm($form, $form_state);
    }
  }

}
