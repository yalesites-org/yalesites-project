<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\ys_ai_tester\AiTesterBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Form for batch testing the Beacon assistant with a YAML question file.
 */
class AiTesterForm extends FormBase {

  /**
   * Maximum allowed size, in bytes, for an uploaded YAML question file.
   */
  const MAX_UPLOAD_BYTES = 262144;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_ai_tester_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['yaml_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Questions YAML file'),
      '#description' => $this->t('Upload a .yml file containing a flat list of question strings. Each question is run through the Beacon assistant.'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run test'),
    ];

    $form['prune'] = [
      '#type' => 'details',
      '#title' => $this->t('Prune run history'),
      '#open' => FALSE,
    ];
    $form['prune']['keep_last'] = [
      '#type' => 'select',
      '#title' => $this->t('Keep the last'),
      '#options' => [
        5 => $this->t('5 runs'),
        10 => $this->t('10 runs'),
        15 => $this->t('15 runs'),
      ],
      '#default_value' => 10,
    ];
    $form['prune']['prune_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Prune old runs'),
      '#submit' => ['::pruneSubmit'],
      '#limit_validation_errors' => [['keep_last']],
    ];

    $form['history'] = $this->buildHistoryTable();

    $form['compare_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Compare selected'),
      '#submit' => ['::compareSubmit'],
      '#limit_validation_errors' => [['history']],
    ];

    return $form;
  }

  /**
   * Builds the run history tableselect render array.
   *
   * A tableselect (rather than a plain table) lets a user pick exactly two runs
   * to compare; the per-row "View" link to a single run is preserved.
   */
  protected function buildHistoryTable(): array {
    $query = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id', 'created', 'uid', 'yaml_filename', 'question_count', 'status'])
      ->orderBy('r.created', 'DESC')
      ->range(0, 50);
    $query->leftJoin('users_field_data', 'u', 'r.uid = u.uid');
    $query->addField('u', 'name');
    $rows = $query->execute()->fetchAll();

    $options = [];
    foreach ($rows as $row) {
      $options[$row->id] = [
        'date' => $this->dateFormatter->format($row->created, 'short'),
        'user' => $row->name ?? $this->t('Unknown'),
        'file' => $row->yaml_filename,
        'questions' => $row->question_count,
        'status' => $row->status,
        'actions' => [
          'data' => Link::fromTextAndUrl(
            $this->t('View'),
            Url::fromRoute('ys_ai_tester.run', ['run_id' => $row->id])
          )->toRenderable(),
        ],
      ];
    }

    return [
      '#type' => 'tableselect',
      // Exactly two runs are compared, so the "Select all" affordance would
      // only invite a selection the compare handler rejects.
      '#js_select' => FALSE,
      '#caption' => $this->t('Run History'),
      '#header' => [
        'date' => $this->t('Date'),
        'user' => $this->t('User'),
        'file' => $this->t('File'),
        'questions' => $this->t('Questions'),
        'status' => $this->t('Status'),
        'actions' => $this->t('Actions'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No test runs yet.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $file = $this->getRequest()->files->get('files')['yaml_file'] ?? NULL;

    if (!$file || !$file->isValid()) {
      $form_state->setErrorByName('yaml_file', $this->t('Please upload a valid YAML file.'));
      return;
    }

    // Restrict to YAML files by extension. The content is only ever parsed as
    // YAML (never stored or executed), but rejecting non-YAML uploads at the
    // boundary is cheap defense in depth. MIME sniffing is intentionally not
    // used: YAML has no reliable magic bytes and is commonly detected as
    // text/plain, which would reject legitimate uploads.
    $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
    if (!in_array($extension, ['yml', 'yaml'], TRUE)) {
      $form_state->setErrorByName('yaml_file', $this->t('The file must be a .yml or .yaml file.'));
      return;
    }

    if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
      $form_state->setErrorByName('yaml_file', $this->t('The file is too large. The maximum size is @max KB.', [
        '@max' => (int) (self::MAX_UPLOAD_BYTES / 1024),
      ]));
      return;
    }

    $content = file_get_contents($file->getPathname());

    try {
      $questions = Yaml::parse($content);
    }
    catch (ParseException $e) {
      $form_state->setErrorByName('yaml_file', $this->t('Invalid YAML: @msg', ['@msg' => $e->getMessage()]));
      return;
    }

    if (!is_array($questions) || empty($questions)) {
      $form_state->setErrorByName('yaml_file', $this->t('YAML must contain a non-empty list of questions.'));
      return;
    }

    foreach ($questions as $q) {
      if (!is_string($q)) {
        $form_state->setErrorByName('yaml_file', $this->t('All YAML values must be strings.'));
        return;
      }
    }

    $form_state->set('yaml_questions', array_values($questions));
    $form_state->set('yaml_filename', $file->getClientOriginalName());
    $form_state->set('yaml_content', $content);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $questions = $form_state->get('yaml_questions');

    $run_id = $this->database->insert('ys_ai_tester_run')
      ->fields([
        'uid' => $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
        'yaml_filename' => $form_state->get('yaml_filename'),
        'yaml_content' => $form_state->get('yaml_content'),
        'status' => 'processing',
        'question_count' => count($questions),
      ])
      ->execute();

    $operations = [];
    foreach ($questions as $delta => $question) {
      $operations[] = [
        [AiTesterBatch::class, 'processQuestion'],
        [(int) $run_id, $question, $delta],
      ];
    }

    batch_set([
      'title' => $this->t('Running AI tests'),
      'operations' => $operations,
      'finished' => [AiTesterBatch::class, 'finished'],
      'progress_message' => $this->t('Processed @current of @total questions.'),
    ]);
  }

  /**
   * Redirects to the comparison view for exactly two selected runs.
   */
  public function compareSubmit(array &$form, FormStateInterface $form_state): void {
    $selected = array_keys(array_filter($form_state->getValue('history')));

    if (count($selected) !== 2) {
      $this->messenger()->addWarning($this->t('Select exactly two runs to compare.'));
      $form_state->setRebuild();
      return;
    }

    // Order ascending so the older run is always Run A (canonical URL).
    sort($selected);
    $form_state->setRedirect('ys_ai_tester.compare', [
      'run_a' => $selected[0],
      'run_b' => $selected[1],
    ]);
  }

  /**
   * Deletes all runs beyond the configured keep limit.
   */
  public function pruneSubmit(array &$form, FormStateInterface $form_state): void {
    $keep = (int) $form_state->getValue('keep_last');

    $keep_ids = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id'])
      ->orderBy('created', 'DESC')
      ->range(0, $keep)
      ->execute()
      ->fetchCol();

    if (empty($keep_ids)) {
      $this->messenger()->addStatus($this->t('No runs to prune.'));
      return;
    }

    $deleted_results = $this->database->delete('ys_ai_tester_result')
      ->condition('run_id', $keep_ids, 'NOT IN')
      ->execute();

    $deleted_runs = $this->database->delete('ys_ai_tester_run')
      ->condition('id', $keep_ids, 'NOT IN')
      ->execute();

    if ($deleted_runs === 0) {
      $this->messenger()->addStatus($this->t('Nothing to prune — fewer than @keep runs exist.', ['@keep' => $keep]));
    }
    else {
      $this->messenger()->addStatus($this->t(
        'Pruned @runs run(s) and @results result(s).',
        ['@runs' => $deleted_runs, '@results' => $deleted_results]
      ));
    }
  }

}
