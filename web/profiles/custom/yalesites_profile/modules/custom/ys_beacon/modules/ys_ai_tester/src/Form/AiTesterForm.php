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
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_ai_tester\AiTesterBatch;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\RunProgress;
use Drupal\ys_ai_tester\StaleRunReconciler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for batch testing an AI assistant with a list of questions.
 *
 * Questions are supplied one per line, either as an uploaded plain-text file or
 * typed directly into a textarea (one input or the other, never both).
 *
 * When more than one assistant is available the run can target either one, or
 * both at once — which records two runs over an identical question list and
 * opens the existing side-by-side comparison.
 */
class AiTesterForm extends FormBase {

  /**
   * Maximum allowed size, in bytes, for an uploaded questions file.
   */
  const MAX_UPLOAD_BYTES = 262144;

  /**
   * Selector value meaning "run this list against every assistant".
   *
   * Prefixed so it cannot collide with a backend id.
   */
  const RUN_ALL = '__all';

  /**
   * Constructs the AI Tester form.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\ys_ai_tester\AnswerBackendRegistry $backendRegistry
   *   The answer backend registry.
   * @param \Drupal\ys_ai_tester\StaleRunReconciler $staleRunReconciler
   *   Releases runs whose batch died without reporting a status.
   * @param \Drupal\ys_ai_tester\RunProgress $runProgress
   *   Reports how many of a run's questions were answered.
   */
  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected AnswerBackendRegistry $backendRegistry,
    protected StaleRunReconciler $staleRunReconciler,
    protected RunProgress $runProgress,
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
      $container->get('ys_ai_tester.answer_backend_registry'),
      $container->get('ys_ai_tester.stale_run_reconciler'),
      $container->get('ys_ai_tester.run_progress'),
    );
  }

  /**
   * Builds the assistant selector options for the available backends.
   *
   * @param array $available_labels
   *   Labels keyed by backend id, as returned by the registry.
   *
   * @return array
   *   The radio options, or an empty array when there is nothing to choose
   *   between and no selector should be rendered at all.
   */
  public static function backendChoices(array $available_labels): array {
    // One assistant is not a choice: the form must look exactly as it did
    // before more than one backend existed — no selector, no warning.
    if (count($available_labels) < 2) {
      return [];
    }

    $choices = $available_labels;
    // The comparison view takes exactly two runs, so running "both" is only a
    // coherent option when exactly two assistants are available.
    if (count($available_labels) === 2) {
      $choices[self::RUN_ALL] = new TranslatableMarkup('Both — run the same questions against each assistant and compare');
    }

    return $choices;
  }

  /**
   * Resolves which assistants a submission runs against.
   *
   * @param string $choice
   *   The submitted selector value, which may be empty (no selector rendered)
   *   or stale (a backend that has since become unavailable).
   * @param string[] $available_ids
   *   The backend ids that can answer right now.
   *
   * @return string[]
   *   One backend id per run to create, in creation order.
   */
  public static function resolveRunBackends(string $choice, array $available_ids): array {
    if ($choice === self::RUN_ALL && $available_ids !== []) {
      return $available_ids;
    }

    // Anything unrecognised — an empty value, or a backend that went away
    // between rendering and submitting — falls back to the default assistant
    // rather than running something the user did not ask for.
    return in_array($choice, $available_ids, TRUE)
      ? [$choice]
      : [AnswerBackendInterface::DEFAULT_ID];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_ai_tester_form';
  }

  /**
   * Splits raw question text into a trimmed list, dropping blank lines.
   *
   * @param string $text
   *   The raw file or textarea content.
   *
   * @return string[]
   *   The non-empty, trimmed question lines, in order.
   */
  public static function parseQuestionLines(string $text): array {
    $questions = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
      $trimmed = trim($line);
      if ($trimmed !== '') {
        $questions[] = $trimmed;
      }
    }
    return $questions;
  }

  /**
   * Resolves which question input was supplied, or why it is invalid.
   *
   * A user must use exactly one of the two inputs.
   *
   * @param bool $has_file
   *   Whether a valid file was uploaded.
   * @param bool $has_text
   *   Whether the textarea has non-whitespace content.
   *
   * @return string
   *   'file' or 'text' for the chosen source, or 'both'/'neither' when the
   *   one-or-the-other rule is violated.
   */
  public static function classifyInput(bool $has_file, bool $has_text): string {
    return match (TRUE) {
      $has_file && $has_text => 'both',
      !$has_file && !$has_text => 'neither',
      $has_file => 'file',
      default => 'text',
    };
  }

  /**
   * Builds the accessible label for one history row's select checkbox.
   *
   * Core's tableselect leaves a row checkbox with an empty label unless the
   * option row carries a 'title' key (Tableselect::processTableselect()), and
   * an unlabelled checkbox is a WCAG 2.1 SC 4.1.2 failure — which is what
   * every row of this table shipped. Picking runs to compare is the whole
   * purpose of the table, so the checkbox has to say which run it selects.
   *
   * Core renders the result as "Update <label>". The verb is core's, shared by
   * every admin tableselect in Drupal, and it is left alone deliberately: the
   * alternative is hand-building the checkbox child, which also drops core's
   * '#default_value' handling and so loses a selection whenever the form
   * rebuilds after a validation error.
   *
   * @param int|string $id
   *   The run id.
   * @param string $file
   *   The run's source filename.
   * @param string $date
   *   The already-formatted run date.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label naming the run.
   */
  public static function historyRowLabel(int|string $id, string $file, string $date): TranslatableMarkup {
    // The id leads because both halves of a "run both" submission share a
    // source file and a request timestamp; it is the only part that always
    // tells them apart. "Run #@id" matches how every other surface in the
    // module names a run (runMetaBlock(), the rerun form), so a screen reader
    // user hears one vocabulary rather than two spellings of the same thing.
    return new TranslatableMarkup('Run #@id, @file, @date', [
      '@id' => $id,
      '@file' => $file,
      '@date' => $date,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $choices = self::backendChoices($this->backendRegistry->availableOptions());

    // Naming Beacon in the intro would contradict the selector, so the
    // assistant is a placeholder — one sentence to translate, and the guidance
    // below it cannot drift between the two wordings.
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Run a list of questions through @assistant, one question per line. Either upload a plain-text file or type the questions below — use one or the other, not both.', [
        '@assistant' => $choices
          ? $this->t('an AI assistant')
          : $this->t('the Beacon assistant'),
      ]) . '</p>',
    ];

    if ($choices) {
      $form['backend'] = [
        '#type' => 'radios',
        '#title' => $this->t('Assistant'),
        '#options' => $choices,
        '#default_value' => AnswerBackendInterface::DEFAULT_ID,
        '#description' => $this->t('Which assistant answers this run. Running both records two runs over the same question list and opens the comparison view.'),
      ];
    }

    $form['questions_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Questions file (.txt)'),
      '#description' => $this->t('Upload a plain-text file with one question per line. Blank lines are ignored.'),
    ];

    $form['questions_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or type questions'),
      '#description' => $this->t('One question per line. Blank lines are ignored. Use this or upload a file above — not both.'),
      '#rows' => 8,
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
   * to compare; per-row "View", "Rerun" and "Resume" links are preserved in the
   * actions column. Both actions are hidden while a run is still processing,
   * and Resume additionally only appears where questions are outstanding.
   */
  protected function buildHistoryTable(): array {
    // Before reading statuses, not after: a run whose batch died still says
    // 'processing', which is exactly the state that hides its Rerun link below.
    $this->staleRunReconciler->reconcile();

    $query = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id', 'created', 'uid', 'source_filename', 'question_count', 'status', 'backend'])
      ->orderBy('r.created', 'DESC')
      // Both runs of a "run both" submission share a request timestamp, so id
      // breaks the tie and keeps the pair's order stable across renders.
      ->orderBy('r.id', 'DESC')
      ->range(0, 50);
    $query->leftJoin('users_field_data', 'u', 'r.uid = u.uid');
    $query->addField('u', 'name');
    $rows = $query->execute()->fetchAll();

    // One grouped query for the whole page rather than one per row, and only
    // the counts - the resume form loads the questions themselves.
    $attempted = $this->runProgress->attemptedCounts(array_map(
      static fn(object $row): int => (int) $row->id,
      $rows
    ));

    $options = [];
    foreach ($rows as $row) {
      $actions = [
        'view' => Link::fromTextAndUrl(
          $this->t('View'),
          Url::fromRoute('ys_ai_tester.run', ['run_id' => $row->id])
        )->toRenderable(),
      ];
      // Re-running a still-processing run is refused server-side, and only a
      // finished run is a meaningful comparison baseline, so the action is
      // hidden until it completes.
      if ($row->status !== 'processing') {
        $actions['separator'] = ['#markup' => ' | '];
        $actions['rerun'] = Link::fromTextAndUrl(
          $this->t('Rerun'),
          Url::fromRoute('ys_ai_tester.rerun', ['run_id' => $row->id])
        )->toRenderable();

        // Offered only where there is something to finish, so a complete run
        // does not advertise an action that would decline. Rerun asks the whole
        // list again as a new run; resume fills in this run's gaps.
        if (($attempted[(int) $row->id] ?? 0) < (int) $row->question_count) {
          $actions['resume_separator'] = ['#markup' => ' | '];
          $actions['resume'] = Link::fromTextAndUrl(
            $this->t('Resume'),
            Url::fromRoute('ys_ai_tester.resume', ['run_id' => $row->id])
          )->toRenderable();
        }
      }

      $date = $this->dateFormatter->format($row->created, 'short');

      $options[$row->id] = [
        // Not a column: '#header' has no 'title' key, and
        // preRenderTableselect() only emits cells for header keys, so this
        // renders nothing. It exists to give the checkbox an accessible name.
        'title' => [
          'data' => [
            '#title' => self::historyRowLabel($row->id, (string) $row->source_filename, $date),
          ],
        ],
        'date' => $date,
        'user' => $row->name ?? $this->t('Unknown'),
        'file' => $row->source_filename,
        // Runs recorded before the tester supported more than one assistant
        // read back as Beacon via the column default.
        'backend' => $this->backendRegistry->labelFor((string) $row->backend),
        'questions' => $row->question_count,
        'status' => $row->status,
        'actions' => ['data' => $actions],
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
        'backend' => $this->t('Assistant'),
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
    $file = $this->getRequest()->files->get('files')['questions_file'] ?? NULL;
    $has_file = $file && $file->isValid();
    $has_text = trim((string) $form_state->getValue('questions_text')) !== '';

    switch (self::classifyInput($has_file, $has_text)) {
      case 'both':
        $form_state->setError($form, $this->t('Provide questions either by uploading a file or by typing them — not both.'));
        return;

      case 'neither':
        $form_state->setError($form, $this->t('Upload a questions file or type at least one question.'));
        return;

      case 'file':
        // Restrict to plain-text files by extension. MIME sniffing is not used:
        // a question list has no reliable magic bytes and is detected as
        // text/plain, so extension is the cheap boundary check.
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension !== 'txt') {
          $form_state->setErrorByName('questions_file', $this->t('The file must be a .txt file.'));
          return;
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
          $form_state->setErrorByName('questions_file', $this->t('The file is too large. The maximum size is @max KB.', [
            '@max' => (int) (self::MAX_UPLOAD_BYTES / 1024),
          ]));
          return;
        }
        $questions = self::parseQuestionLines((string) file_get_contents($file->getPathname()));
        $filename = $file->getClientOriginalName();
        $error_field = 'questions_file';
        break;

      default:
        $text = (string) $form_state->getValue('questions_text');
        // Cap the typed input at the same size as an uploaded file, so neither
        // path can enqueue an unbounded batch.
        if (strlen($text) > self::MAX_UPLOAD_BYTES) {
          $form_state->setErrorByName('questions_text', $this->t('The text is too large. The maximum size is @max KB.', [
            '@max' => (int) (self::MAX_UPLOAD_BYTES / 1024),
          ]));
          return;
        }
        $questions = self::parseQuestionLines($text);
        $filename = 'typed-questions.txt';
        $error_field = 'questions_text';
    }

    if (!$questions) {
      $form_state->setErrorByName($error_field, $this->t('No questions found. Enter at least one non-empty line.'));
      return;
    }

    $form_state->set('questions', $questions);
    $form_state->set('source_filename', $filename);
    // The stored source is the normalized question list, ready to download as a
    // .txt and re-upload or re-run.
    $form_state->set('source_content', implode("\n", $questions));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $questions = $form_state->get('questions');
    $backends = self::resolveRunBackends(
      (string) $form_state->getValue('backend'),
      $this->backendRegistry->availableIds(),
    );

    // Each assistant gets its own run over the identical question list, so the
    // two are directly comparable and either can be re-run on its own.
    $run_ids = [];
    foreach ($backends as $backend_id) {
      $run_id = $this->insertRun($form_state, count($questions), $backend_id);
      $run_ids[] = $run_id;

      $operations = [];
      foreach ($questions as $delta => $question) {
        $operations[] = [
          [AiTesterBatch::class, 'processQuestion'],
          [$run_id, $question, $delta, $backend_id],
        ];
      }

      // One batch SET per run, not one set for both. Each set carries its own
      // results array, so finished() records the status of the run it actually
      // belongs to, and one assistant's failure cannot mark the other
      // assistant's run failed. A single shared set would leave the first run
      // stuck at 'processing' forever, because every operation overwrites the
      // same results['run_id'].
      batch_set([
        'title' => $this->t('Running AI tests (@assistant)', [
          '@assistant' => $this->backendRegistry->labelFor($backend_id),
        ]),
        'operations' => $operations,
        'finished' => [AiTesterBatch::class, 'finished'],
        'progress_message' => $this->t('Processed @current of @total questions.'),
      ]);
    }

    // Two runs over one list exist to be read side by side, so the batch lands
    // on the comparison rather than back on this form. The form's redirect is
    // what the batch honours once every operation has completed.
    if (count($run_ids) === 2) {
      sort($run_ids);
      $form_state->setRedirect('ys_ai_tester.compare', [
        'run_a' => $run_ids[0],
        'run_b' => $run_ids[1],
      ]);
    }
  }

  /**
   * Inserts one run row and returns its id.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the validated question source.
   * @param int $question_count
   *   How many questions the run covers.
   * @param string $backend_id
   *   The assistant answering this run.
   *
   * @return int
   *   The new run id.
   */
  protected function insertRun(FormStateInterface $form_state, int $question_count, string $backend_id): int {
    return (int) $this->database->insert('ys_ai_tester_run')
      ->fields([
        'uid' => $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
        'source_filename' => $form_state->get('source_filename'),
        'source_content' => $form_state->get('source_content'),
        'source_run_id' => 0,
        'status' => 'processing',
        // Seeds the heartbeat StaleRunReconciler reads, so a run is never
        // born already looking abandoned.
        'changed' => $this->time->getRequestTime(),
        'question_count' => $question_count,
        'backend' => $backend_id,
      ])
      ->execute();
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
