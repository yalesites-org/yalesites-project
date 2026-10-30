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
use Drupal\ys_migrate\Service\PostImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk importing post content from CSV files.
 */
class PostCsvImportForm extends FormBase {

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
   * The post import service.
   *
   * @var \Drupal\ys_migrate\Service\PostImportService
   */
  protected $postImport;

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
   * Constructs a PostCsvImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\ys_migrate\Service\CsvValidatorService $csv_validator
   *   The CSV validator service.
   * @param \Drupal\ys_migrate\Service\PostImportService $post_import
   *   The post import service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    MessengerInterface $messenger,
    CsvValidatorService $csv_validator,
    PostImportService $post_import,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
  ) {
    $this->messenger = $messenger;
    $this->csvValidator = $csv_validator;
    $this->postImport = $post_import;
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
      $container->get('ys_migrate.post_import'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_migrate_post_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Upload a CSV file to create posts in bulk, one per row. The first row must contain column headers. Title and Publish Date are required; any other column may be left out.'),
    ];

    $form['draft_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Posts are imported as drafts, so review and publish them once the import finishes.'),
    ];

    $form['body_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t("The Body column becomes a Text block on the post's page. Headings, links, lists and tables are kept, but images, video and embeds cannot be imported: the import summary lists the posts that had them, so you can add them in Layout Builder."),
    ];

    $form['reference_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Category, Tags, Audience and Custom Vocab only accept terms that already exist on this site. For Teaser Media, upload the image to the media library first and enter its ID, or leave the column empty and add the image to the post after the import.'),
    ];

    $form['columns'] = $this->columnReferenceTable($this->csvValidator->getExpectedPostColumns(), $this->columnNotes());

    $form['sample_download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download a sample CSV to see the expected columns and an example row.'),
      '#url' => Url::fromRoute('ys_migrate.post_csv_sample'),
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
      '#description' => $this->t('Skip rows whose title and publish date match an existing post. Uncheck to import them anyway.'),
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

    $validation_result = $this->csvValidator->validatePostCsvStructure($file_path);
    if (!$validation_result['valid']) {
      $form_state->setErrorByName('csv_file', $validation_result['message']);
      return;
    }

    // An unrecognised header is skipped in silence, so a mistyped column name
    // would drop its data with nothing to show for it. Warn rather than fail:
    // an extra column an editor keeps for their own notes is legitimate, and
    // a file exported from Manage Posts carries URL and Published columns.
    $unknown = $this->csvValidator->getUnknownPostColumns($validation_result['headers']);
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
        'import_service_id' => 'ys_migrate.post_import',
        'skip_duplicates' => $skip_duplicates,
        'entity_label' => 'post',
      ],
      $data,
      $fid,
      (string) $this->t('Importing posts...')
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
      'title' => $this->t('Required. Used with Publish Date to detect duplicates.'),
      'publish date' => $this->t('Required. YYYY-MM-DD or MM/DD/YYYY.'),
      'author' => $this->t('Plain text, shown as the byline.'),
      'body' => $this->t('HTML, or plain text with a blank line between paragraphs. Imported as one Text block; images, video and embeds are left out.'),
      'category' => $this->t('Comma-separated. Terms must already exist.'),
      'tags' => $this->t('Comma-separated. Terms must already exist.'),
      'audience' => $this->t('Comma-separated. Terms must already exist.'),
      'custom vocab' => $this->t('Comma-separated. Terms must already exist. "Custom Vocabulary" also works as a header.'),
      'teaser title' => $this->t('Plain text.'),
      'teaser text' => $this->t("Plain text. Character count is capped by the Teaser Text field's own limit."),
      'teaser media id' => $this->t('The ID of an image already in the media library: the number in its edit address, such as 123 in /media/123/edit.'),
      'external source' => $this->t('Full http:// or https:// URL. Visitors are sent there instead of to the post, so leave it empty for posts with a Body.'),
      'cas login required' => $this->t('Yes or No. "CAS Protected" also works as a header.'),
      'url alias' => $this->t('A path such as /news/my-post. Leave empty to have one made from the publish date and title.'),
    ];
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
    $result = $this->postImport->previewImport($data, $skip_duplicates);

    $this->messenger->addStatus($this->t(
      'Preview only, nothing was saved. @valid of @total row(s) would be imported.',
      [
        '@valid' => count($result['valid_posts']),
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

    if (!empty($result['valid_posts'])) {
      $this->displayPreviewTable($result['valid_posts']);
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
   * Displays a preview table of the posts to be created.
   *
   * @param array $posts
   *   Array of prepared post data.
   */
  protected function displayPreviewTable(array $posts) {
    $rows = [];

    foreach (array_slice($posts, 0, self::PREVIEW_LIMIT) as $post) {
      $rows[] = [
        $post['title'],
        $post['publish_date'],
        $post['category'] ? implode(', ', $post['category']) : '-',
        $post['body'] !== '' ? $this->t('Yes') : $this->t('No'),
        $post['teaser_media'] ?? '-',
        $post['body_has_media'] ? $this->t('Yes') : $this->t('No'),
      ];
    }

    if (count($posts) > self::PREVIEW_LIMIT) {
      $rows[] = [
        $this->t('...and @count more', ['@count' => count($posts) - self::PREVIEW_LIMIT]),
        '', '', '', '', '',
      ];
    }

    $build = [
      '#type' => 'table',
      '#caption' => $this->t('Posts to be created'),
      '#header' => [
        ['data' => $this->t('Title'), 'scope' => 'col'],
        ['data' => $this->t('Publish Date'), 'scope' => 'col'],
        ['data' => $this->t('Category'), 'scope' => 'col'],
        ['data' => $this->t('Body'), 'scope' => 'col'],
        ['data' => $this->t('Teaser Media ID'), 'scope' => 'col'],
        ['data' => $this->t('Needs images'), 'scope' => 'col'],
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['post-preview-table']],
    ];

    $this->messenger->addStatus($this->renderer->render($build));
  }

}
