<?php

namespace Drupal\ys_file_management\Form;

use Drupal\media_file_delete\Form\MediaDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
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

    // Check permission levels.
    $can_force_delete = $this->currentUser->hasPermission('force delete media files');
    $can_delete_any_file = $this->currentUser->hasPermission('delete media files regardless of owner');
    $can_bypass_ownership = $can_delete_any_file || $can_force_delete;

    // Check if media entity is used elsewhere using entity reference fields.
    $media_used_elsewhere = $this->mediaUsageDetector->isMediaUsedElsewhere($media);

    // Debug media usage detection.
    $this->logger->info('Media usage debug: media @mid, used_elsewhere=@used', [
      '@mid' => $media->id(),
      '@used' => $media_used_elsewhere ? 'TRUE' : 'FALSE',
    ]);

    // Also check file usage for completeness.
    $usages = $this->usageResolver->getFileUsages($file);
    $file_used_elsewhere = $usages > 1;

    // Debug logging to help identify usage detection issues.
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

    // Check media ownership (unless user can bypass ownership).
    $user_owns_media = $media->getOwnerId() == $this->currentUser->id();
    if (!$can_bypass_ownership && !$user_owns_media) {
      // Remove submit button and show only cancel.
      unset($build['actions']['submit']);
      $build['description'] = $this->messageBuilder->buildOwnershipMessage($media);
      return $build;
    }

    // Check media and file usage.
    if ($media_used_elsewhere || $file_used_elsewhere) {
      // Entity Usage will show the warning, our form_alter adds the recommendation.
      // Just show "This action cannot be undone" for users who can still delete.
      if (!$can_force_delete) {
        $build['description'] = $this->messageBuilder->buildActionWarningMessage();
        return $build;
      }
      // Force delete users continue to buildForceDeleteForm.
    }

    // User can proceed - show "This action cannot be undone" only when deletion
    // is allowed.
    if (!($media_used_elsewhere || $file_used_elsewhere) || $can_force_delete) {
      $build['description'] = $this->messageBuilder->buildActionWarningMessage();
    }

    // Platform administrators with force delete permission get special options.
    if ($can_force_delete) {
      return $this->buildForceDeleteForm($build, $file, $usages);
    }

    // Regular users and site administrators get automatic file temp marking.
    // No additional options needed - file will be automatically marked as
    // temporary.
    return $build;
  }

  /**
   * Builds the form for users with force delete permissions.
   *
   * @param array $build
   *   The base form build.
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   * @param int $usages
   *   Number of places the file is used.
   *
   * @return array
   *   The form build.
   */
  protected function buildForceDeleteForm(array $build, FileInterface $file, int $usages) {
    $media = $this->getEntity();
    $media_used_elsewhere = $this->mediaUsageDetector->isMediaUsedElsewhere($media);
    $file_used_elsewhere = $usages > 1;
    $has_usage = $media_used_elsewhere || $file_used_elsewhere;

    // Entity Usage will show the main warning, just show "cannot be undone".
    if ($has_usage) {
      $build['description'] = $this->messageBuilder->buildActionWarningMessage();
    }

    return $build + [
      'force_delete_file' => [
        '#type' => 'checkbox',
        '#default_value' => FALSE,
        '#title' => $this->t('Delete the associated file'),
        '#description' => $this->t('When checked, the file %file will be marked as temporary for cron cleanup, bypassing usage checks. <strong>This action cannot be undone and may break other content.</strong>', [
          '%file' => $file->getFilename(),
        ]),
      ],
    ];
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

    // Check permission levels.
    $can_force_delete = $this->currentUser->hasPermission('force delete media files');
    $force_delete_requested = $form_state->getValue('force_delete_file');

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

    // Handle file processing.
    $this->mediaFileHandler->processFile($file, $force_delete_requested, $can_force_delete);
  }

}
