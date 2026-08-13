<?php

namespace Drupal\ys_migrate\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\ys_migrate\Batch\CsvImportBatch;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\ResourceImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk importing resource content from CSV files.
 */
class ResourceCsvImportForm extends FormBase {

  use BatchSubmitTrait;

  /**
   * Maximum upload size, in bytes.
   */
  const MAX_FILE_SIZE = 10485760;

  /**
   * How many preview rows to show before truncating.
   */
  const PREVIEW_LIMIT = 100;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The CSV validator service.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

  /**
   * The resource import service.
   *
   * @var \Drupal\ys_migrate\Service\ResourceImportService
   */
  protected $resourceImport;

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
   * Constructs a ResourceCsvImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\ys_migrate\Service\CsvValidatorService $csv_validator
   *   The CSV validator service.
   * @param \Drupal\ys_migrate\Service\ResourceImportService $resource_import
   *   The resource import service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    MessengerInterface $messenger,
    CsvValidatorService $csv_validator,
    ResourceImportService $resource_import,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
  ) {
    $this->messenger = $messenger;
    $this->csvValidator = $csv_validator;
    $this->resourceImport = $resource_import;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('ys_migrate.csv_validator'),
      $container->get('ys_migrate.resource_import'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_migrate_resource_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Upload a CSV file to create resources in bulk, one per row. The first row must contain column headers. Any column may be left out; only Title is required.'),
    ];

    $form['media_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Resources are imported as drafts, so review and publish them once the import finishes. Resource Media cannot be set from a CSV file: rows with an External Source are ready to use straight away, and the import summary lists the rest, which you will need to open and attach media to.'),
    ];

    $form['columns'] = [
      '#type' => 'table',
      '#caption' => $this->t('Recognised columns'),
      '#header' => [$this->t('Column'), $this->t('Notes')],
      '#rows' => $this->columnReferenceRows(),
    ];

    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t('Maximum file size: 10MB.'),
      '#upload_location' => 'private://csv_imports/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'csv'],
        'FileSizeLimit' => ['fileLimit' => self::MAX_FILE_SIZE],
      ],
      '#required' => TRUE,
    ];

    $form['preview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preview only'),
      '#description' => $this->t('Show what would be created without saving anything.'),
      '#default_value' => TRUE,
    ];

    $form['skip_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip duplicates'),
      '#description' => $this->t('Skip rows whose title already belongs to an existing resource. Uncheck to import them anyway.'),
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

    $validation_result = $this->csvValidator->validateResourceCsvStructure($file_path);
    if (!$validation_result['valid']) {
      $form_state->setErrorByName('csv_file', $validation_result['message']);
      return;
    }

    // An unrecognised header is skipped in silence, so a mistyped column name
    // would drop its data with nothing to show for it. Warn rather than fail:
    // an extra column an editor keeps for their own notes is legitimate.
    $unknown = $this->csvValidator->getUnknownResourceColumns($validation_result['headers']);
    if (!empty($unknown)) {
      $this->messenger->addWarning($this->t(
        'These columns are not recognised and will be ignored: @columns',
        ['@columns' => implode(', ', $unknown)]
      ));
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
    $fid = $csv_file[0];

    if ($preview_only) {
      $this->previewImport($validation_result['data'], $skip_duplicates);
      // Preview renders its own response and never touches the batch queue,
      // so the upload is cleaned up immediately rather than by a batch
      // operation.
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file) {
        $file->delete();
      }
      return;
    }

    // A real import runs through the Batch API, one request per chunk, so a
    // large CSV cannot time out a single request. File cleanup becomes the
    // batch's own trailing operation instead of happening here, since
    // submitForm() returns before any row is processed.
    $data = $validation_result['data'];
    // Drupal's FormSubmitter attaches $form_state to the batch it persists,
    // so the parsed CSV would otherwise be serialized twice: once chunked
    // into the batch operations below, and again here.
    $form_state->set('csv_validation', NULL);

    $this->setBatch(CsvImportBatch::build(
      [
        'import_service_id' => 'ys_migrate.resource_import',
        'skip_duplicates' => $skip_duplicates,
        'entity_label' => 'resource',
      ],
      $data,
      $fid,
      (string) $this->t('Importing resources...')
    ));
  }

  /**
   * Builds the rows of the recognised-columns reference table.
   *
   * @return array
   *   Table rows of column label and guidance.
   */
  protected function columnReferenceRows() {
    // Columns absent from this list need no explanation beyond their name.
    $notes = [
      'title' => $this->t('Required. Also used to detect duplicates.'),
      'resource category' => $this->t('Comma-separated. Terms are created if missing.'),
      'audience' => $this->t('Comma-separated. Terms are created if missing.'),
      'custom vocab' => $this->t('Comma-separated. "Custom Vocabulary" also works as a header.'),
      'resource publication date' => $this->t('YYYY-MM-DD or MM/DD/YYYY.'),
      'date format' => $this->t('Year, Month/Year, or Month/Day/Year.'),
      'tags' => $this->t('Comma-separated. Terms are created if missing.'),
      'external source' => $this->t('Full http:// or https:// URL.'),
      'cas login required' => $this->t('Yes or No.'),
      'pin to beginning of list' => $this->t('Yes or No.'),
    ];

    $rows = [];

    foreach ($this->csvValidator->getExpectedResourceColumns() as $key => $label) {
      $rows[] = [$label, $notes[$key] ?? ''];
    }

    return $rows;
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
    $result = $this->resourceImport->previewImport($data, $skip_duplicates);

    $this->messenger->addStatus($this->t(
      'Preview only, nothing was saved. @valid of @total row(s) would be imported.',
      [
        '@valid' => count($result['valid_resources']),
        '@total' => $result['total'],
      ]
    ));

    if (!empty($result['duplicates'])) {
      $this->messenger->addWarning($this->t(
        '@count row(s) would be skipped as duplicates: @titles',
        [
          '@count' => count($result['duplicates']),
          '@titles' => implode(', ', $result['duplicates']),
        ]
      ));
    }

    $this->reportErrors($result['errors']);

    if (!empty($result['valid_resources'])) {
      $this->displayPreviewTable($result['valid_resources']);
    }
  }

  /**
   * Surfaces per-row errors, which never stop the rest of the run.
   *
   * @param array $errors
   *   The row error messages.
   */
  protected function reportErrors(array $errors) {
    foreach ($errors as $error) {
      $this->messenger->addError($error);
    }
  }

  /**
   * Displays a preview table of the resources to be created.
   *
   * @param array $resources
   *   Array of prepared resource data.
   */
  protected function displayPreviewTable(array $resources) {
    $rows = [];

    foreach (array_slice($resources, 0, self::PREVIEW_LIMIT) as $resource) {
      $rows[] = [
        $resource['title'],
        $resource['category'] ? implode(', ', $resource['category']) : '-',
        $resource['publish_date'] ?: '-',
        $resource['external_source'] ?: '-',
        $resource['external_source'] ? $this->t('No') : $this->t('Yes'),
      ];
    }

    if (count($resources) > self::PREVIEW_LIMIT) {
      $rows[] = [
        $this->t('...and @count more', ['@count' => count($resources) - self::PREVIEW_LIMIT]),
        '', '', '', '',
      ];
    }

    $build = [
      '#type' => 'table',
      '#caption' => $this->t('Resources to be created'),
      '#header' => [
        $this->t('Title'),
        $this->t('Resource Category'),
        $this->t('Publication Date'),
        $this->t('External Source'),
        $this->t('Needs media'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['resource-preview-table']],
    ];

    $this->messenger->addStatus($this->renderer->render($build));
  }

}
