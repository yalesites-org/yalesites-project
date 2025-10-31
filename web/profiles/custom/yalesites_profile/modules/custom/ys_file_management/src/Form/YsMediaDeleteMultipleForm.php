<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom media bulk delete form with enhanced safety checks.
 */
class YsMediaDeleteMultipleForm extends DeleteMultipleForm {

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
    $instance = new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('messenger')
    );
    $instance->logger = $container->get('logger.factory')->get('ys_file_management');
    $instance->mediaUsageDetector = $container->get('ys_file_management.media_usage_detector');
    $instance->mediaFileHandler = $container->get('ys_file_management.media_file_handler');
    $instance->messageBuilder = $container->get('ys_file_management.media_delete_message_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore -- Override needed to prevent default description, we build dynamic ones.
  public function getDescription() {
    // We build our own descriptions dynamically based on analysis.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    $form = parent::buildForm($form, $form_state, $entity_type_id);

    if (empty($this->selection)) {
      return $form;
    }

    // Load all selected media entities to analyze.
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId);
    $entities = $storage->loadMultiple(array_keys($this->selection));

    // Filter to only file-based media entities.
    $file_based_entities = array_filter($entities, function ($entity) {
      return \ys_file_management_is_file_based_media($entity);
    });

    // If no file-based media entities, use parent form.
    if (empty($file_based_entities)) {
      return $form;
    }

    // If mixed selection, update entities to only file-based ones.
    if (count($file_based_entities) < count($entities)) {
      $entities = $file_based_entities;
      // Update selection to match filtered entities.
      $this->selection = array_intersect_key($this->selection, array_flip(array_keys($entities)));
    }

    // Analyze the batch for permission and usage issues.
    $batch_analysis = $this->analyzeBatch($entities);

    // Build form based on batch analysis.
    return $this->buildBatchForm($form, $batch_analysis);
  }

  /**
   * Analyzes a batch of media entities for permission and usage constraints.
   *
   * @param array $entities
   *   Array of media entities to analyze.
   *
   * @return array
   *   Analysis results with permission levels and usage information.
   */
  protected function analyzeBatch(array $entities): array {
    $analysis = [
      'total_count' => count($entities),
      'accessible_count' => 0,
      'files_with_usage' => 0,
      'no_file_attached' => 0,
      'can_force_delete' => $this->currentUser->hasPermission('force delete media files'),
      'blocked_entities' => [],
      'problematic_files' => [],
    ];

    foreach ($entities as $entity) {
      if (!($entity instanceof MediaInterface)) {
        continue;
      }

      // Check basic access.
      if (!$entity->access('delete', $this->currentUser)) {
        $analysis['blocked_entities'][] = [
          'entity' => $entity,
          'reason' => 'access_denied',
        ];
        continue;
      }

      $analysis['accessible_count']++;
      $file = $this->getFile($entity);

      if (!$file) {
        $analysis['no_file_attached']++;
        continue;
      }

      // Check for usage.
      $media_used_elsewhere = $this->mediaUsageDetector->isMediaUsedElsewhere($entity);
      if ($media_used_elsewhere) {
        $analysis['files_with_usage']++;
        $analysis['problematic_files'][] = [
          'entity' => $entity,
          'file' => $file,
          'usage_type' => 'media_usage',
        ];
      }
    }

    return $analysis;
  }

  /**
   * Builds the form based on batch analysis results.
   *
   * @param array $form
   *   The base form array.
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return array
   *   The modified form array.
   */
  protected function buildBatchForm(array $form, array $analysis): array {
    // If there are access or ownership issues that can't be bypassed, block
    // submission.
    $blocking_issues = !empty($analysis['blocked_entities']);
    $usage_issues = !empty($analysis['problematic_files']);

    if ($blocking_issues) {
      unset($form['actions']['submit']);
      $form['blocking_issues'] = [
        '#type' => 'item',
        '#markup' => $this->buildBlockingIssuesMessage($analysis),
      ];
      return $form;
    }

    // Show description message.
    $form['description'] = [
      '#type' => 'item',
      '#markup' => $usage_issues ?
      $this->messageBuilder->buildActionWarningMessage()['#markup'] :
      $this->buildSuccessMessage($analysis),
      '#weight' => 0,
    ];

    // Only platform admins can delete files from filesystem.
    if (!$analysis['can_force_delete']) {
      // Standard users and site admins get no file deletion UI.
      return $form;
    }

    // Platform admins get file deletion checkbox.
    $files_to_process = $analysis['accessible_count'] -
      $analysis['no_file_attached'];
    if ($files_to_process > 0) {
      $description = $this->formatPlural(
        $files_to_process,
        'When checked, the associated file will be marked as temporary and removed by cron cleanup (typically within 6 hours).',
        'When checked, the associated files will be marked as temporary and removed by cron cleanup (typically within 6 hours).'
      );

      if ($usage_issues) {
        $description .= ' <strong>WARNING: Some files are used ';
        $description .= 'elsewhere and deleting them may break ';
        $description .= 'other content.</strong>';
      }

      $form['delete_files_bulk'] = [
        '#type' => 'checkbox',
        '#default_value' => TRUE,
        '#title' => $this->t('Delete the associated files'),
        '#description' => $description,
        '#weight' => 10,
      ];
    }

    return $form;
  }

  /**
   * Builds message for blocking issues (access denied).
   *
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return string
   *   The formatted message.
   */
  protected function buildBlockingIssuesMessage(array $analysis): string {
    $messages = [];

    $access_denied_count = count($analysis['blocked_entities']);

    if ($access_denied_count > 0) {
      $messages[] = $this->formatPlural(
        $access_denied_count,
        'You do not have permission to delete @count of the selected media items.',
        'You do not have permission to delete @count of the selected media items.'
      );
    }

    if ($analysis['accessible_count'] > 0) {
      $messages[] = $this->formatPlural(
        $analysis['accessible_count'],
        'Only @count media item can be deleted.',
        'Only @count media items can be deleted.'
      );
      $messages[] = 'Please adjust your selection and try again.';
    }
    else {
      $messages[] = '<strong>No media items in your selection can be deleted.</strong>';
    }

    return '<div class="messages messages--error">' . implode(' ', $messages) . '</div>';
  }

  /**
   * Builds message for usage issues when user cannot force delete.
   *
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return string
   *   The formatted message.
   */
  protected function buildUsageIssuesMessage(array $analysis): string {
    $messages = [
      '<strong>Warning:</strong> ',
      $this->formatPlural(
        $analysis['files_with_usage'],
        '@count media item is used in other locations on the site.',
        '@count media items are used in other locations on the site.'
      ),
    ];

    $messages[] = "We strongly recommend using file usage to ensure they're not associated with any blocks before deleting.";

    // Add the standard "cannot be undone" warning.
    $undone_message = $this->messageBuilder->buildActionWarningMessage();

    return '<div class="messages messages--warning">' . implode(' ', $messages) . '</div>' . $undone_message['#markup'];
  }

  /**
   * Builds success message for cases where deletion can proceed.
   *
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return string
   *   The formatted message.
   */
  protected function buildSuccessMessage(array $analysis): string {
    $messages = [];

    $messages[] = $this->formatPlural(
      $analysis['accessible_count'],
      'Are you sure you want to delete this media item?',
      'Are you sure you want to delete these @count media items?'
    );

    if ($analysis['no_file_attached'] > 0) {
      $messages[] = $this->formatPlural(
        $analysis['no_file_attached'],
        '@count item has no file attached.',
        '@count items have no files attached.'
      );
    }

    $files_to_process = $analysis['accessible_count'] - $analysis['no_file_attached'];
    if ($files_to_process > 0) {
      $messages[] = $this->formatPlural(
        $files_to_process,
        'The associated file will be marked as temporary for cleanup.',
        'The associated files will be marked as temporary for cleanup.'
      );
    }

    // Use the consistent warning message from MessageBuilder.
    $warning_message = $this->messageBuilder->buildActionWarningMessage();
    $messages[] = $warning_message['#markup'];

    return implode(' ', $messages);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId);
    $entities = $storage->loadMultiple(array_keys($this->selection));

    // Check if user is platform admin.
    $is_platform_admin = $this->currentUser
      ->hasPermission('force delete media files');

    // Determine if files should be deleted.
    // Standard users: always delete files.
    // Platform admins: delete if checkbox is checked (default: checked).
    $should_delete_files = !$is_platform_admin ||
      $form_state->getValue('delete_files_bulk', TRUE);

    $deleted_count = 0;
    $file_processed_count = 0;
    $file_skipped_count = 0;

    // Store redirect URL before processing.
    $redirect_url = $this->mediaFileHandler->getRedirectUrl();

    foreach ($entities as $entity) {
      if (!($entity instanceof MediaInterface) ||
          !$entity->access('delete', $this->currentUser)) {
        continue;
      }

      // Get file before deleting media.
      $file = $this->getFile($entity);

      // Delete the media entity.
      $entity->delete();
      $deleted_count++;

      $this->logger->info('Bulk deleted media @mid (@label)', [
        '@mid' => $entity->id(),
        '@label' => $entity->label(),
      ]);

      // Process file if should delete.
      if ($file && $should_delete_files) {
        // Delete file (always for site admins,
        // optional for platform admins).
        $this->mediaFileHandler->processFile($file, TRUE, TRUE);
        $file_processed_count++;
      }
    }

    // Set redirect.
    $form_state->setRedirectUrl($redirect_url);

    // Add status messages.
    $this->addCompletionMessages($deleted_count, $file_processed_count, $file_skipped_count);
  }

  /**
   * Adds completion messages to the user.
   *
   * @param int $deleted_count
   *   Number of media entities deleted.
   * @param int $file_processed_count
   *   Number of files processed.
   * @param int $file_skipped_count
   *   Number of files skipped.
   */
  protected function addCompletionMessages(int $deleted_count, int $file_processed_count, int $file_skipped_count): void {
    if ($deleted_count > 0) {
      $this->messenger->addStatus($this->formatPlural(
        $deleted_count,
        'Deleted @count media item.',
        'Deleted @count media items.'
      ));
    }

    if ($file_processed_count > 0) {
      $this->messenger->addStatus($this->formatPlural(
        $file_processed_count,
        'Processed @count associated file.',
        'Processed @count associated files.'
      ));
    }

    if ($file_skipped_count > 0) {
      $this->messenger->addWarning($this->formatPlural(
        $file_skipped_count,
        'Could not process @count file due to permission restrictions.',
        'Could not process @count files due to permission restrictions.'
      ));
    }
  }

  /**
   * Gets the file for the given media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity or NULL if not found.
   */
  protected function getFile(MediaInterface $media): ?FileInterface {
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);

    if ($source_field->getType() !== 'file' && $source_field->getType() !== 'image') {
      return NULL;
    }

    $fid = $media->getSource()->getSourceFieldValue($media);
    if (!$fid) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('file')->load($fid);
  }

}
