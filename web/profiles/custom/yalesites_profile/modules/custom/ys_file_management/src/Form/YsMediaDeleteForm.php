<?php

namespace Drupal\ys_file_management\Form;

use Drupal\media_file_delete\Form\MediaDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom media delete form that marks files as temporary instead of deleting.
 */
class YsMediaDeleteForm extends MediaDeleteForm {

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The media usage detector service.
   *
   * @var \Drupal\ys_file_management\Service\MediaUsageDetector
   */
  protected $mediaUsageDetector;

  /**
   * The media file handler service.
   *
   * @var \Drupal\ys_file_management\Service\MediaFileHandler
   */
  protected $mediaFileHandler;

  /**
   * The media delete message builder service.
   *
   * @var \Drupal\ys_file_management\Service\MediaDeleteMessageBuilder
   */
  protected $messageBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->logger = $container->get('logger.factory')->get('ys_file_management');
    $instance->mediaUsageDetector = $container->get('ys_file_management.media_usage_detector');
    $instance->mediaFileHandler = $container->get('ys_file_management.media_file_handler');
    $instance->messageBuilder = $container->get('ys_file_management.media_delete_message_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // We'll override this per form state.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $media = $this->getEntity();
    assert($media instanceof MediaInterface);

    // Only apply our custom logic to file-based media bundles.
    if (!\ys_file_management_is_file_based_media($media)) {
      return parent::buildForm($form, $form_state);
    }

    $file = $this->getFile($media);

    // Get the base form from the grandparent (ContentEntityDeleteForm)
    $build = $this->buildContentEntityDeleteForm($form, $form_state);

    if (!$file) {
      // No file - standard deletion with warning.
      $build['description'] = $this->messageBuilder->buildActionWarningMessage();
      return $build;
    }

    // Check permission levels - only platform admins can delete files.
    $can_delete_file = $this->currentUser
      ->hasPermission('force delete media files');

    // Show "This action cannot be undone" message.
    $build['description'] = $this->messageBuilder
      ->buildActionWarningMessage();

    // Only platform administrators can delete files from filesystem.
    // All other users get standard Drupal behavior
    // (media deleted, file unreferenced).
    if (!$can_delete_file) {
      // Standard users and site admins - no file deletion UI.
      return $build;
    }

    // Platform admins: check if media/file is used elsewhere.
    $media_used_elsewhere = $this->mediaUsageDetector
      ->isMediaUsedElsewhere($media);
    $usages = $this->usageResolver->getFileUsages($file);
    $file_used_elsewhere = $usages > 1;

    // Debug logging.
    $this->logger->info('Media usage debug: media @mid, used_elsewhere=@used', [
      '@mid' => $media->id(),
      '@used' => $media_used_elsewhere ? 'TRUE' : 'FALSE',
    ]);

    $drupal_usage = \Drupal::service('file.usage')->listUsage($file);
    $drupal_usage_count = 0;
    foreach ($drupal_usage as $module_usages) {
      foreach ($module_usages as $type_usages) {
        foreach ($type_usages as $count) {
          $drupal_usage_count += $count;
        }
      }
    }

    $this->logger->info('File usage debug: file @fid - resolver: @usages, drupal: @drupal_count, file_used_elsewhere=@used', [
      '@fid' => $file->id(),
      '@usages' => $usages,
      '@drupal_count' => $drupal_usage_count,
      '@used' => $file_used_elsewhere ? 'TRUE' : 'FALSE',
    ]);

    // Platform admins get file deletion checkbox
    // (default checked, always shown).
    $has_usage = $media_used_elsewhere || $file_used_elsewhere;

    if ($has_usage) {
      $description = $this->t('When checked, the file %file will be marked as temporary and removed by cron cleanup (typically within 6 hours). <strong>WARNING: This file is used elsewhere and deleting it may break other content.</strong>', [
        '%file' => $file->getFilename(),
      ]);
    }
    else {
      $description = $this->t('When checked, the file %file will be marked as temporary and removed by cron cleanup (typically within 6 hours).', [
        '%file' => $file->getFilename(),
      ]);
    }

    $build['delete_file'] = [
      '#type' => 'checkbox',
      '#default_value' => TRUE,
      '#title' => $this->t('Delete the associated file'),
      '#description' => $description,
    ];

    return $build;
  }

  /**
   * Builds the base content entity delete form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  protected function buildContentEntityDeleteForm(array $form, FormStateInterface $form_state) {
    $form['#title'] = $this->getQuestion();
    $form['#attributes']['class'][] = 'confirmation';
    // Note: description is set dynamically in buildForm()
    $form[$this->getFormName()] = ['#type' => 'hidden', '#value' => 1];

    // Add actions.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->getConfirmText(),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->getCancelText(),
      '#attributes' => ['class' => ['button']],
      '#url' => $this->getCancelUrl(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store references before deletion.
    $media = $this->getEntity();
    $file = $this->getFile($media);

    // Check if user has permission and requested file deletion.
    $can_delete_file = $this->currentUser
      ->hasPermission('force delete media files');
    $delete_file_requested = $form_state->getValue('delete_file');

    // Store a better redirect URL before deletion.
    $redirect_url = $this->mediaFileHandler->getRedirectUrl();

    // Store success message before deletion.
    $success_message = $this->messageBuilder->buildSuccessMessage($media);

    // Delete the media entity first.
    $media->delete();

    // Set redirect to a safe location.
    $form_state->setRedirectUrl($redirect_url);

    // Add confirmation message.
    $this->messenger()->addMessage($success_message['#markup']);

    // Handle file processing if platform admin requested it.
    if ($can_delete_file && $delete_file_requested && $file) {
      // Platform admins can always delete files.
      $this->mediaFileHandler->processFile($file, TRUE, TRUE);
    }
  }

}
