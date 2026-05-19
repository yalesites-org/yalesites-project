<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Form for batch testing an AI assistant with a YAML question file.
 */
class AiTesterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
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
      '#description' => $this->t('Upload a .yml file containing a flat list of question strings.'),
      '#required' => TRUE,
    ];

    if ($this->currentUser->hasPermission('administer ai providers')) {
      $assistants = $this->entityTypeManager->getStorage('ai_assistant')->loadMultiple();
      $options = [];
      foreach ($assistants as $id => $assistant) {
        $options[$id] = $assistant->label();
      }
      $form['assistant_id'] = [
        '#type' => 'select',
        '#title' => $this->t('AI Assistant'),
        '#options' => $options,
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select an assistant -'),
      ];
    }

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

    return $form;
  }

  /**
   * Builds the run history table render array.
   */
  protected function buildHistoryTable(): array {
    $query = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id', 'created', 'uid', 'assistant_id', 'yaml_filename', 'question_count', 'status'])
      ->orderBy('r.created', 'DESC')
      ->range(0, 50);
    $query->leftJoin('users_field_data', 'u', 'r.uid = u.uid');
    $query->addField('u', 'name');
    $rows = $query->execute()->fetchAll();

    $table_rows = [];
    foreach ($rows as $row) {
      $table_rows[] = [
        $this->dateFormatter->format($row->created, 'short'),
        $row->name ?? $this->t('Unknown'),
        $row->assistant_id,
        $row->yaml_filename,
        $row->question_count,
        $row->status,
        [
          'data' => Link::fromTextAndUrl(
            $this->t('View'),
            Url::fromRoute('ys_ai.tester_run', ['run_id' => $row->id])
          )->toRenderable(),
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Run History'),
      '#header' => [
        $this->t('Date'),
        $this->t('User'),
        $this->t('Assistant'),
        $this->t('File'),
        $this->t('Questions'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#rows' => $table_rows,
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

    // For non-admin users, ignore the submitted assistant_id and use the
    // server-side default to prevent POST tampering.
    if (!$this->currentUser->hasPermission('administer ai providers')) {
      $default = $this->config('ys_ai_tester.settings')->get('default_tester_assistant') ?? '';
      if (empty($default)) {
        $form_state->setError($form, $this->t('No default AI assistant is configured. Contact a site administrator.'));
        return;
      }
      $form_state->setValue('assistant_id', $default);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $questions = $form_state->get('yaml_questions');
    $assistant_id = $form_state->getValue('assistant_id');

    $run_id = $this->database->insert('ys_ai_tester_run')
      ->fields([
        'uid' => $this->currentUser->id(),
        'assistant_id' => $assistant_id,
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
        '\Drupal\ys_ai_tester\AiTesterBatch::processQuestion',
        [(int) $run_id, $assistant_id, $question, $delta],
      ];
    }

    batch_set([
      'title' => $this->t('Running AI tests'),
      'operations' => $operations,
      'finished' => '\Drupal\ys_ai_tester\AiTesterBatch::finished',
      'progress_message' => $this->t('Processed @current of @total questions.'),
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
