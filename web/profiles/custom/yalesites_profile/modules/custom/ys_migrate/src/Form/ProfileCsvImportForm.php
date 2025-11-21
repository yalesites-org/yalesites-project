<?php

namespace Drupal\ys_migrate\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\ProfileImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk importing profile content from CSV files.
 */
class ProfileCsvImportForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The CSV validator service.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

  /**
   * The profile import service.
   *
   * @var \Drupal\ys_migrate\Service\ProfileImportService
   */
  protected $profileImport;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a ProfileCsvImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\CsvValidatorService $csv_validator
   *   The CSV validator service.
   * @param \Drupal\ys_migrate\Service\ProfileImportService $profile_import
   *   The profile import service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    MessengerInterface $messenger,
    AccountInterface $current_user,
    CsvValidatorService $csv_validator,
    ProfileImportService $profile_import,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
  ) {
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->csvValidator = $csv_validator;
    $this->profileImport = $profile_import;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('messenger'),
          $container->get('current_user'),
          $container->get('ys_migrate.csv_validator'),
          $container->get('ys_migrate.profile_import'),
          $container->get('entity_type.manager'),
          $container->get('renderer')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_migrate_profile_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<div class="profile-csv-import-description">
        <h3>Bulk Import Profile Content</h3>
        <p>Upload a CSV file to bulk import profile content. The CSV should contain one profile per row with the following columns:</p>
        <ul>
          <li><strong>Display Name</strong> (required) - The main title for the profile</li>
          <li><strong>First Name</strong> - Person\'s first name</li>
          <li><strong>Last Name</strong> - Person\'s last name</li>
          <li><strong>Honorific Prefix</strong> - Title (e.g., Dr., Prof., Mr., Ms.)</li>
          <li><strong>Pronouns</strong> - Preferred pronouns</li>
          <li><strong>Position</strong> - Job title or role</li>
          <li><strong>Subtitle</strong> - Secondary title or role</li>
          <li><strong>Department</strong> - Department or unit</li>
          <li><strong>Email</strong> - Email address</li>
          <li><strong>Telephone</strong> - Phone number</li>
          <li><strong>Address</strong> - Physical address</li>
          <li><strong>Teaser Title</strong> - Short title for teaser displays</li>
          <li><strong>Teaser Text</strong> - Brief description (max 150 characters)</li>
          <li><strong>Affiliation</strong> - Comma-separated affiliation terms</li>
          <li><strong>Audience</strong> - Comma-separated audience terms</li>
          <li><strong>Tags</strong> - Comma-separated tag terms</li>
          <li><strong>Custom Vocabulary</strong> - Comma-separated custom terms</li>
        </ul>
        <p><strong>Note:</strong> The first row should contain column headers. All fields except Display Name are optional.</p>
        <p><a href="https://yalesites.yale.edu/profile#import" target="_blank">For more information and the CSV Template, visit the YaleSites Profile resource in our User Guide</a></p>

      </div>',
    ];

    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV File'),
      '#description' => $this->t('Upload a CSV file with profile data. Maximum file size: 10MB.'),
      '#upload_location' => 'private://csv_imports/',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      // 10MB
        'file_validate_size' => [10485760],
      ],
      '#required' => TRUE,
    ];

    $form['preview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preview only'),
      '#description' => $this->t('Check this to preview the import without creating any content.'),
      '#default_value' => TRUE,
    ];

    $form['skip_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip duplicates'),
      '#description' => $this->t('Skip profiles that already exist (based on email address).'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process CSV'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $csv_file = $form_state->getValue('csv_file');
    if (empty($csv_file)) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a CSV file.'));
      return;
    }

    // Load the file and validate its contents.
    $file = $this->entityTypeManager->getStorage('file')->load($csv_file[0]);
    if (!$file) {
      $form_state->setErrorByName('csv_file', $this->t('Unable to load the uploaded file.'));
      return;
    }

    $file_path = $file->getFileUri();
    if (!file_exists($file_path)) {
      $form_state->setErrorByName('csv_file', $this->t('The uploaded file could not be found.'));
      return;
    }

    // Validate CSV structure.
    $validation_result = $this->csvValidator->validateCsvStructure($file_path);
    if (!$validation_result['valid']) {
      $form_state->setErrorByName('csv_file', $validation_result['message']);
      return;
    }

    // Store validation results for use in submit.
    $form_state->set('csv_validation', $validation_result);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $csv_file = $form_state->getValue('csv_file');
    $preview_only = $form_state->getValue('preview');
    $skip_duplicates = $form_state->getValue('skip_duplicates');
    $validation_result = $form_state->get('csv_validation');

    $file = $this->entityTypeManager->getStorage('file')->load($csv_file[0]);

    if ($preview_only) {
      $this->previewImport($validation_result['data'], $skip_duplicates);
    }
    else {
      $this->processImport($validation_result['data'], $skip_duplicates);
    }

    // Clean up the uploaded file.
    $file->delete();
  }

  /**
   * Previews the import without creating content.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   */
  protected function previewImport(array $data, $skip_duplicates) {
    $preview_result = $this->profileImport->previewImport($data, $skip_duplicates);

    $message = $this->t('Preview: @valid valid profiles found.', ['@valid' => count($preview_result['valid_profiles'])]);
    if (!empty($preview_result['duplicates'])) {
      $message .= ' ' . $this->t('@duplicates would be skipped as duplicates.', ['@duplicates' => count($preview_result['duplicates'])]);
    }

    $this->messenger->addStatus($message);

    if (!empty($preview_result['valid_profiles'])) {
      $this->displayPreviewTable($preview_result['valid_profiles']);
    }
  }

  /**
   * Processes the import and creates profile nodes.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   */
  protected function processImport(array $data, $skip_duplicates) {
    $import_result = $this->profileImport->processImport($data, $skip_duplicates);

    // Display results.
    if ($import_result['created'] > 0) {
      $this->messenger->addStatus($this->t('Successfully created @count profile(s).', ['@count' => $import_result['created']]));
    }

    if ($import_result['skipped'] > 0) {
      $this->messenger->addWarning($this->t('Skipped @count duplicate profile(s).', ['@count' => $import_result['skipped']]));
    }

    if (!empty($import_result['errors'])) {
      foreach ($import_result['errors'] as $error) {
        $this->messenger->addError($error);
      }
    }
  }

  /**
   * Displays a preview table of the profiles to be created.
   *
   * @param array $profiles
   *   Array of profile data.
   */
  protected function displayPreviewTable(array $profiles) {
    $rows = [];

    // Show first 10.
    foreach (array_slice($profiles, 0, 100) as $profile) {
      $rows[] = [
        $profile['display_name'],
        $profile['email'] ?: '-',
        $profile['position'] ?: '-',
        $profile['department'] ?: '-',
      ];
    }

    if (count($profiles) > 100) {
      $rows[] = [
        '...',
        '...',
        '...',
        '...',
      ];
    }

    $build = [
      '#type' => 'table',
      '#header' => [
        $this->t('Display Name'),
        $this->t('Email'),
        $this->t('Position'),
        $this->t('Department'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['profile-preview-table']],
    ];

    $this->messenger->addStatus($this->renderer->render($build));
  }

}
