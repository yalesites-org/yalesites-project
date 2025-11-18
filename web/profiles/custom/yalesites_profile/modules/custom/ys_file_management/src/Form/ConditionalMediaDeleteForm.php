<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media_file_delete\Form\MediaDeleteForm;
use Drupal\ys_file_management\Service\MediaFileDeleterInterface;
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
  public const PERMISSION_MANAGE_FILES = 'manage media files';

  /**
   * The media file deleter service.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileDeleterInterface
   */
  protected MediaFileDeleterInterface $mediaFileDeleter;

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
   * Users with the 'manage media files' permission can delete files regardless
   * of ownership or usage count. They see informational warnings but are never
   * blocked. Other users see standard Drupal media deletion with no file
   * deletion options.
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
    if (!$this->currentUser->hasPermission(self::PERMISSION_MANAGE_FILES)) {
      // User does not have file management permissions.
      // Use the standard Drupal media deletion form without file deletion
      // options by calling ContentEntityDeleteForm::buildForm() directly
      // (grandparent class), which bypasses all file deletion UI.
      return ContentEntityDeleteForm::buildForm($form, $form_state);
    }

    // User has 'manage media files' permission (File Manager role).
    // Build custom form that allows deletion regardless of ownership/usage.
    $media = $this->getEntity();
    $file = $this->getFile($media);
    $build = ContentEntityDeleteForm::buildForm($form, $form_state);

    // If no file is associated with this media, return the base form.
    if (!$file) {
      return $build;
    }

    // Get file information for warnings and descriptions.
    $file_owner = $file->getOwner();
    $file_name = $file->getFilename();
    $usages = $this->usageResolver->getFileUsages($file);

    // Build the checkbox description with appropriate warnings.
    $description = $this->t('After deleting the media item, this will also remove the associated file %file from the file system.', [
      '%file' => $file_name,
    ]);

    // Add ownership warning if file is owned by another user.
    // File Managers can still delete it, but we inform them.
    if (!$file->access('delete', $this->currentUser)) {
      $description = $this->t('After deleting the media item, this will also remove the associated file %file from the file system. <strong>Note:</strong> This file is owned by %owner.', [
        '%file' => $file_name,
        '%owner' => $file_owner->getDisplayName(),
      ]);
    }

    // Add usage warning if file is used in multiple places.
    // File Managers can still delete it, but we warn about consequences.
    if ($usages > 1) {
      $usage_warning = $this->formatPlural(
        $usages - 1,
        'This file is used in 1 other place. Deleting it will cause broken references.',
        'This file is used in @count other places. Deleting it will cause broken references.'
      );

      $description = $this->t('After deleting the media item, this will also remove the associated file %file from the file system. <strong>Warning:</strong> @usage_warning', [
        '%file' => $file_name,
        '@usage_warning' => $usage_warning,
      ]);
    }

    // Get module configuration for checkbox defaults.
    $config = $this->configFactory()->get('media_file_delete.settings');

    // Always show the checkbox for File Managers, with appropriate warnings.
    $build['also_delete_file'] = [
      '#type' => 'checkbox',
      '#default_value' => $config->get('delete_file_default'),
      '#title' => $this->t('Also delete the associated file?'),
      '#description' => $description,
      '#access' => !$config->get('disable_delete_control'),
    ];

    return $build;
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
