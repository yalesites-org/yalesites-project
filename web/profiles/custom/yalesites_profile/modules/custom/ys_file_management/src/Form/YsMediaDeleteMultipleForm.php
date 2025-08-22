<?php

namespace Drupal\ys_file_management\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
  public function __construct(
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    PrivateTempStoreFactory $temp_store_factory,
    MessengerInterface $messenger,
  ) {
    parent::__construct($current_user, $entity_type_manager, $temp_store_factory, $messenger);
  }

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
  public function getDescription() {
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
      'ownership_issues' => 0,
      'no_file_attached' => 0,
      'can_force_delete' => $this->currentUser->hasPermission('force delete media files'),
      'can_delete_any_file' => $this->currentUser->hasPermission('delete media files regardless of owner'),
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

      // Check ownership unless user can bypass.
      $can_bypass_ownership = $analysis['can_delete_any_file'] || $analysis['can_force_delete'];
      $user_owns_media = $entity->getOwnerId() == $this->currentUser->id();

      if (!$can_bypass_ownership && !$user_owns_media) {
        $analysis['ownership_issues']++;
        $analysis['blocked_entities'][] = [
          'entity' => $entity,
          'reason' => 'ownership',
          'file' => $file,
        ];
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
    // If there are access or ownership issues that can't be bypassed, block submission.
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

    // Handle usage issues based on permissions.
    if ($usage_issues && !$analysis['can_force_delete']) {
      unset($form['actions']['submit']);
      $form['usage_issues'] = [
        '#type' => 'item',
        '#markup' => $this->buildUsageIssuesMessage($analysis),
      ];
      return $form;
    }

    // Handle different scenarios based on usage and permissions.
    if ($usage_issues && $analysis['can_force_delete']) {
      // Force delete users: Show usage info, hide button until confirmed.
      $user_level = $this->messageBuilder->getUserLevel($analysis['can_delete_any_file'], $analysis['can_force_delete']);
      $form['usage_info'] = [
        '#type' => 'item',
        '#markup' => $this->messageBuilder->buildUsageMessage(
          $analysis['files_with_usage'],
          $user_level,
          TRUE
        )['#markup'],
        '#weight' => -10,
      ];
      $form = $this->addForceDeleteOptions($form, $analysis);
    }
    elseif (!$usage_issues) {
      // Safe deletion: Show "Are you sure?" message.
      $form['description'] = [
        '#type' => 'item',
        '#markup' => $this->buildSuccessMessage($analysis),
        '#weight' => 0,
      ];
    }

    return $form;
  }

  /**
   * Builds message for blocking issues (access/ownership).
   *
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return string
   *   The formatted message.
   */
  protected function buildBlockingIssuesMessage(array $analysis): string {
    $messages = [];

    $access_denied_count = 0;
    $ownership_issues_count = 0;

    foreach ($analysis['blocked_entities'] as $blocked) {
      if ($blocked['reason'] === 'access_denied') {
        $access_denied_count++;
      }
      elseif ($blocked['reason'] === 'ownership') {
        $ownership_issues_count++;
      }
    }

    if ($access_denied_count > 0) {
      $messages[] = $this->formatPlural(
        $access_denied_count,
        'You do not have permission to delete @count of the selected media items.',
        'You do not have permission to delete @count of the selected media items.'
      );
    }

    if ($ownership_issues_count > 0) {
      $messages[] = $this->formatPlural(
        $ownership_issues_count,
        '@count of the selected media items cannot be deleted because you do not own them. You need the "Delete media files regardless of owner" permission to delete files you do not own.',
        '@count of the selected media items cannot be deleted because you do not own them. You need the "Delete media files regardless of owner" permission to delete files you do not own.'
      );
    }

    $total_accessible = $analysis['accessible_count'] - $ownership_issues_count;
    if ($total_accessible > 0) {
      $messages[] = $this->formatPlural(
        $total_accessible,
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
    $safe_count = $analysis['accessible_count'] - $analysis['files_with_usage'];
    $user_level = $this->messageBuilder->getUserLevel($analysis['can_delete_any_file'], $analysis['can_force_delete']);

    $messages = [
      $this->formatPlural(
        $analysis['files_with_usage'],
        'Cannot delete: @count media item is used in other locations on the site.',
        'Cannot delete: @count media items are used in other locations on the site.'
      ),
    ];

    // Add user-level specific guidance using the consistent messaging.
    $additional_message = match ($user_level) {
      'site_admin' => $this->t('Remove references first, or contact a platform administrator for force deletion.'),
      'platform_admin' => $this->t('Use force delete option below to override.'),
      default => $this->t('Please remove those references first, then try again.'),
    };
    $messages[] = $additional_message;

    if ($safe_count > 0) {
      $messages[] = $this->formatPlural(
        $safe_count,
        'Only @count media item can be safely deleted.',
        'Only @count media items can be safely deleted.'
      );
      $messages[] = 'Please adjust your selection to exclude media items that are in use.';
    }

    return '<div class="messages messages--error">' . implode(' ', $messages) . '</div>';
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
   * Adds force delete options to the form for users with permissions.
   *
   * @param array $form
   *   The form array.
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return array
   *   The modified form array.
   */
  protected function addForceDeleteOptions(array $form, array $analysis): array {
    // Hide submit button until force delete is confirmed.
    $form['actions']['submit']['#states'] = [
      'visible' => [
        ':input[name="force_delete_bulk"]' => ['checked' => TRUE],
      ],
    ];

    // Add force delete warning (matching single form pattern).
    $form['force_delete_warning'] = [
      '#type' => 'item',
      '#markup' => $this->buildForceDeleteWarning($analysis),
      '#weight' => 5,
    ];

    // Add confirmation checkbox.
    $form['force_delete_bulk'] = [
      '#type' => 'checkbox',
      '#default_value' => FALSE,
      '#title' => $this->t('Force delete files with usage'),
      '#description' => $this->t('<strong>WARNING:</strong> Files will be permanently deleted immediately instead of being marked temporary. This may break other content that references these files. <strong>This action cannot be undone.</strong>'),
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * Builds force delete warning message.
   *
   * @param array $analysis
   *   The batch analysis results.
   *
   * @return string
   *   The formatted warning message.
   */
  protected function buildForceDeleteWarning(array $analysis): string {
    $messages = [];

    $messages[] = '<div class="messages messages--warning">';
    $messages[] = '<strong>Warning:</strong> ';
    $messages[] = $this->formatPlural(
      $analysis['files_with_usage'],
      '@count of the selected files is used in other locations. Force deleting could break other content.',
      '@count of the selected files are used in other locations. Force deleting could break other content.'
    );
    $messages[] = '</div>';

    return implode('', $messages);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId);
    $entities = $storage->loadMultiple(array_keys($this->selection));

    // Check permission levels.
    $can_force_delete = $this->currentUser->hasPermission('force delete media files');
    $force_delete_requested = $form_state->getValue('force_delete_bulk', FALSE);

    $deleted_count = 0;
    $file_processed_count = 0;
    $file_skipped_count = 0;

    // Store redirect URL before processing.
    $redirect_url = $this->mediaFileHandler->getRedirectUrl();

    foreach ($entities as $entity) {
      if (!($entity instanceof MediaInterface) || !$entity->access('delete', $this->currentUser)) {
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

      // Process associated file.
      if ($file) {
        $can_process = $this->canProcessFile($entity, $file);

        if ($can_process) {
          $this->mediaFileHandler->processFile($file, $force_delete_requested, $can_force_delete);
          $file_processed_count++;
        }
        else {
          $file_skipped_count++;
        }
      }
    }

    // Set redirect.
    $form_state->setRedirectUrl($redirect_url);

    // Add status messages.
    $this->addCompletionMessages($deleted_count, $file_processed_count, $file_skipped_count);
  }

  /**
   * Determines if a file can be processed based on permissions and usage.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return bool
   *   TRUE if file can be processed, FALSE otherwise.
   */
  protected function canProcessFile(MediaInterface $media, FileInterface $file): bool {
    // Check basic file access.
    if (!$file->access('delete', $this->currentUser)) {
      return FALSE;
    }

    // Check permissions.
    $can_force_delete = $this->currentUser->hasPermission('force delete media files');
    $can_delete_any_file = $this->currentUser->hasPermission('delete media files regardless of owner');
    $can_bypass_ownership = $can_delete_any_file || $can_force_delete;

    // Check ownership.
    $user_owns_media = $media->getOwnerId() == $this->currentUser->id();
    if (!$can_bypass_ownership && !$user_owns_media) {
      return FALSE;
    }

    return TRUE;
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
