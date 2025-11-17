<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
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
 */
class ConditionalMediaDeleteForm extends MediaDeleteForm {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->fileSystem = $container->get('file_system');
    return $instance;
  }

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
      // Get the media entity and its associated file.
      $media = $this->getEntity();
      $file = $this->getFile($media);

      // Check if the user wants to delete the file and file exists.
      if ($file && $form_state->getValue('also_delete_file')) {
        $file_uri = $file->getFileUri();
        $file_name = $file->getFilename();

        try {
          // Immediately delete the physical file from the filesystem.
          if ($this->fileSystem->delete($file_uri)) {
            // Delete the file entity from the database.
            $file->delete();
            $this->messenger()->addMessage($this->t('Deleted the associated file %name.', [
              '%name' => $file_name,
            ]));
          }
          else {
            // Physical deletion failed but don't block media deletion.
            $this->messenger()->addWarning($this->t('Could not delete the physical file %name from the filesystem, but the file record was removed.', [
              '%name' => $file_name,
            ]));
            // Still delete the file entity.
            $file->delete();
          }
        }
        catch (\Exception $e) {
          // Log the error and inform the user.
          $this->logger('ys_file_management')->error('Failed to delete file @file: @error', [
            '@file' => $file_uri,
            '@error' => $e->getMessage(),
          ]);
          $this->messenger()->addError($this->t('An error occurred while deleting the file %name: @error', [
            '%name' => $file_name,
            '@error' => $e->getMessage(),
          ]));
        }
      }

      // Delete the media entity using the grandparent's submit handler.
      // This avoids calling MediaDeleteForm::submitForm which would
      // try to delete the file again using the delayed cron method.
      ContentEntityDeleteForm::submitForm($form, $form_state);
    }
    else {
      // Use standard media deletion (no file deletion).
      ContentEntityDeleteForm::submitForm($form, $form_state);
    }
  }

}
