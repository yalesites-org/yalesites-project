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

/**
 * Form for batch testing the Beacon assistant with a list of questions.
 *
 * Questions are supplied one per line, either as an uploaded plain-text file or
 * typed directly into a textarea (one input or the other, never both).
 */
class AiTesterForm extends FormBase {

  /**
   * Maximum allowed size, in bytes, for an uploaded questions file.
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
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Run a list of questions through the Beacon assistant, one question per line. Either upload a plain-text file or type the questions below — use one or the other, not both.') . '</p>',
    ];

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
   * to compare; per-row "View" and "Rerun" links are preserved in the actions
   * column. Rerun is hidden while a run is still processing.
   */
  protected function buildHistoryTable(): array {
    $query = $this->database->select('ys_ai_tester_run', 'r')
      ->fields('r', ['id', 'created', 'uid', 'source_filename', 'question_count', 'status'])
      ->orderBy('r.created', 'DESC')
      ->range(0, 50);
    $query->leftJoin('users_field_data', 'u', 'r.uid = u.uid');
    $query->addField('u', 'name');
    $rows = $query->execute()->fetchAll();

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
      }

      $options[$row->id] = [
        'date' => $this->dateFormatter->format($row->created, 'short'),
        'user' => $row->name ?? $this->t('Unknown'),
        'file' => $row->source_filename,
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

    $run_id = $this->database->insert('ys_ai_tester_run')
      ->fields([
        'uid' => $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
        'source_filename' => $form_state->get('source_filename'),
        'source_content' => $form_state->get('source_content'),
        'source_run_id' => 0,
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
