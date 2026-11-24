<?php

namespace Drupal\ys_migrate\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\ys_migrate\Batch\CsvImportBatch;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Drupal\ys_migrate\Service\EventImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk importing event content from CSV files.
 */
class EventCsvImportForm extends FormBase {

  use BatchSubmitTrait;
  use ColumnReferenceTableTrait;

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
   * The event import service.
   *
   * @var \Drupal\ys_migrate\Service\EventImportService
   */
  protected $eventImport;

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
   * Constructs an EventCsvImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\ys_migrate\Service\CsvValidatorService $csv_validator
   *   The CSV validator service.
   * @param \Drupal\ys_migrate\Service\EventImportService $event_import
   *   The event import service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    MessengerInterface $messenger,
    CsvValidatorService $csv_validator,
    EventImportService $event_import,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
  ) {
    $this->messenger = $messenger;
    $this->csvValidator = $csv_validator;
    $this->eventImport = $event_import;
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
      $container->get('ys_migrate.event_import'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_migrate_event_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $can_publish = $this->eventImport->canPublish();

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Upload a CSV file to create events in bulk, one per row. The first row must contain column headers. Title and Start are required; any other column may be left out.'),
    ];

    $form['draft_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $can_publish
        ? $this->t('Events are imported as drafts unless you choose "Publish imported events" below. Review drafts and publish them once the import finishes.')
        : $this->t('Events are imported as drafts, so review and publish them once the import finishes.'),
    ];

    $form['dates_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Each row creates one event with one date, and times are read as Eastern time. Recurring events cannot be imported: import the first date, then add the repeat rule when you edit the event.'),
    ];

    $form['description_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Headings, links, lists and tables in the Description are kept, but images, video and embeds cannot be imported: the import summary lists the events that had them, so you can add them by hand.'),
    ];

    $form['reference_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Event Category, Tags, Audience, Custom Vocab and Event Experience only accept terms that already exist on this site. Teaser images are not imported; add them to each event after the import.'),
    ];

    $form['localist_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Imported events are marked with the "CSV import" event source, and the Localist sync never changes them. If your events are also in Localist, import only the ones Localist does not have: a row with the same title and start as an existing event is skipped, but the Localist sync does not check for imported copies.'),
    ];

    $form['columns'] = $this->columnReferenceTable($this->csvValidator->getExpectedEventColumns(), $this->columnNotes());

    $form['sample_download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download a sample CSV to see the expected columns and an example row.'),
      '#url' => Url::fromRoute('ys_migrate.event_csv_sample'),
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
      '#description' => $this->t('Skip rows whose title and start match an existing event, or whose Event ID matches an event imported earlier. Uncheck to import them anyway.'),
      '#default_value' => TRUE,
    ];

    // Hidden from roles that cannot publish an event by hand, so they cannot
    // publish one by importing it either.
    $form['publish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish imported events'),
      '#description' => $this->t('Leave unchecked to import the events as drafts, so you can review them before they go live.'),
      '#default_value' => FALSE,
      '#access' => $can_publish,
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

    $validation_result = $this->csvValidator->validateEventCsvStructure($file_path);
    if (!$validation_result['valid']) {
      $form_state->setErrorByName('csv_file', $validation_result['message']);
      return;
    }

    // An unrecognised header is skipped in silence, so a mistyped column name
    // would drop its data with nothing to show for it. Warn rather than fail:
    // an extra column an editor keeps for their own notes is legitimate, and
    // a file exported from Manage Events carries Dates, URL and Published.
    $unknown = $this->csvValidator->getUnknownEventColumns($validation_result['headers']);
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
    $publish = (bool) $form_state->getValue('publish');
    $validation_result = $form_state->get('csv_validation');
    $fid = $csv_file[0];

    if ($preview_only) {
      $this->previewImport($validation_result['data'], $skip_duplicates, $publish);
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
        'import_service_id' => 'ys_migrate.event_import',
        'skip_duplicates' => $skip_duplicates,
        'entity_label' => 'event',
        'import_options' => ['publish' => $publish],
      ],
      $data,
      $fid,
      (string) $this->t('Importing events...')
    ));
  }

  /**
   * Guidance text for the recognised-columns reference table.
   *
   * @return array
   *   Notes keyed by column machine name.
   */
  protected function columnNotes() {
    return [
      'title' => $this->t('Required. Used with Start to detect duplicates.'),
      'start' => $this->t('Required. A date and time, such as 2026-03-04 14:00, 3/4/2026 2:00 PM or March 4, 2026 - 2:00pm. A date alone means an all-day event.'),
      'end' => $this->t('A date and time, or just a time for an event that ends the same day. Must not be before Start. Leave empty for an event with no end time.'),
      'all day' => $this->t('Yes or No. Leave empty to decide from Start: a date with no time is all day. For a multi-day event, put the last day in End.'),
      'description' => $this->t('HTML, or plain text with a blank line between paragraphs. Images, video and embeds are left out.'),
      'location details' => $this->t('Plain text, such as the building name and address on separate lines.'),
      'event experience' => $this->t('One of the existing Event Experience terms, such as In-person, Online or Hybrid.'),
      'event website' => $this->t('Full http:// or https:// URL, or a path on this site. Shown as a button on the event page.'),
      'event website text' => $this->t('The button label, such as Register or RSVP. Defaults to "Event Website".'),
      'ticket cost' => $this->t('Plain text, such as Free or $10.'),
      'ticket registration url' => $this->t('Full http:// or https:// URL. "Registration URL" also works as a header.'),
      'stream url' => $this->t('Full http:// or https:// URL for an online event.'),
      'event category' => $this->t('Comma-separated. Terms must already exist. "Category" also works as a header.'),
      'tags' => $this->t('Comma-separated. Terms must already exist.'),
      'audience' => $this->t('Comma-separated. Terms must already exist.'),
      'custom vocab' => $this->t('Comma-separated. Terms must already exist. "Custom Vocabulary" also works as a header.'),
      'teaser title' => $this->t('Plain text.'),
      'teaser text' => $this->t("Plain text. Character count is capped by the Teaser Text field's own limit."),
      'cas login required' => $this->t('Yes or No. "CAS Protected" also works as a header.'),
      'url alias' => $this->t('A path such as /events/my-event. Leave empty to have one made from the start date and title.'),
      'event id' => $this->t("The event's id on the old site. Stored on the event, and used to skip it if the file is imported again."),
    ];
  }

  /**
   * Previews the import without creating content.
   *
   * @param array $data
   *   The CSV data.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   * @param bool $publish
   *   Whether the events would be published rather than left as drafts.
   */
  protected function previewImport(array $data, $skip_duplicates, $publish = FALSE) {
    $result = $this->eventImport->previewImport($data, $skip_duplicates);
    $counts = [
      '@valid' => count($result['valid_events']),
      '@total' => $result['total'],
    ];

    $this->messenger->addStatus($publish
      ? $this->t('Preview only, nothing was saved. @valid of @total row(s) would be imported and published.', $counts)
      : $this->t('Preview only, nothing was saved. @valid of @total row(s) would be imported as drafts.', $counts));

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

    if (!empty($result['valid_events'])) {
      $this->displayPreviewTable($result['valid_events']);
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
   * Displays a preview table of the events to be created.
   *
   * @param array $events
   *   Array of prepared event data.
   */
  protected function displayPreviewTable(array $events) {
    $rows = [];

    foreach (array_slice($events, 0, self::PREVIEW_LIMIT) as $event) {
      $rows[] = [
        $event['title'],
        $event['start_text'],
        $event['end_text'],
        $event['all_day'] ? $this->t('Yes') : $this->t('No'),
        $event['category'] ? implode(', ', $event['category']) : '-',
        $event['description_has_media'] ? $this->t('Yes') : $this->t('No'),
      ];
    }

    if (count($events) > self::PREVIEW_LIMIT) {
      $rows[] = [
        $this->t('...and @count more', ['@count' => count($events) - self::PREVIEW_LIMIT]),
        '', '', '', '', '',
      ];
    }

    $build = [
      '#type' => 'table',
      '#caption' => $this->t('Events to be created'),
      '#header' => [
        ['data' => $this->t('Title'), 'scope' => 'col'],
        ['data' => $this->t('Start'), 'scope' => 'col'],
        ['data' => $this->t('End'), 'scope' => 'col'],
        ['data' => $this->t('All day'), 'scope' => 'col'],
        ['data' => $this->t('Event Category'), 'scope' => 'col'],
        ['data' => $this->t('Needs images'), 'scope' => 'col'],
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['event-preview-table']],
    ];

    $this->messenger->addStatus($this->renderer->render($build));
  }

}
