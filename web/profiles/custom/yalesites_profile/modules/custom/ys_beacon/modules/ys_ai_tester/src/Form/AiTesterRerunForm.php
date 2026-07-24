<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\ys_ai_tester\AiTesterBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for re-running a stored run's questions as a new run.
 *
 * The original run is left untouched so its answers can be compared against the
 * rerun. A double-fire is refused server-side, not just by client-side
 * JavaScript: a rerun is blocked while the source run is still processing, or
 * while a previous rerun of it is still in progress. This check-then-insert
 * guard covers the realistic sequential case (a reload or a second tab
 * confirming after the first rerun has started); it does not lock against two
 * exactly-simultaneous confirms — acceptable for an admin-only tester.
 */
class AiTesterRerunForm extends ConfirmFormBase {

  /**
   * The run being re-run.
   */
  protected object $run;

  /**
   * The id of the run being re-run.
   */
  protected int $runId = 0;

  /**
   * Constructs the AI Tester rerun form.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_ai_tester_rerun_form';
  }

  /**
   * Decides whether a rerun must be refused, and why.
   *
   * @param string|null $source_status
   *   The status of the run being re-run.
   * @param int $in_flight_count
   *   How many reruns of that run are already processing.
   *
   * @return string|null
   *   'source_processing' or 'already_running' when the rerun must be refused,
   *   or NULL when it may proceed.
   */
  public static function isBlocked(?string $source_status, int $in_flight_count): ?string {
    if ($source_status === 'processing') {
      return 'source_processing';
    }
    if ($in_flight_count > 0) {
      return 'already_running';
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable|string {
    return $this->t('Re-run the @count question(s) from run #@id?', [
      '@count' => (int) $this->run->question_count,
      '@id' => $this->runId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Stringable|string {
    return $this->t('This runs the same questions through Beacon again and records them as a new run. Run #@id is kept so you can compare the old and new answers. This may take a while.', [
      '@id' => $this->runId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Stringable|string {
    return $this->t('Re-run');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ys_ai_tester.tester');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $run_id = NULL): array {
    $this->runId = (int) $run_id;
    $this->run = $this->loadRun($this->runId);
    $form_state->set('rerun_run_id', $this->runId);

    $blocked = self::isBlocked($this->run->status, $this->countInFlight($this->runId));
    if ($blocked !== NULL) {
      $this->messenger()->addWarning($this->blockedMessage($blocked));
      return [
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to tester'),
          '#url' => $this->getCancelUrl(),
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $run_id = (int) $form_state->get('rerun_run_id');
    $this->runId = $run_id;
    $run = $this->loadRun($run_id);

    // Re-check the guard at the point of mutation. This is what actually
    // stops a double-fire: a reload or a second tab reaching submit finds the
    // first rerun already processing and is refused.
    $blocked = self::isBlocked($run->status, $this->countInFlight($run_id));
    if ($blocked !== NULL) {
      $this->messenger()->addWarning($this->blockedMessage($blocked));
      $form_state->setRedirect('ys_ai_tester.tester');
      return;
    }

    $questions = AiTesterForm::parseQuestionLines((string) $run->source_content);
    if (!$questions) {
      $this->messenger()->addError($this->t('Run #@id has no questions to re-run.', ['@id' => $run_id]));
      $form_state->setRedirect('ys_ai_tester.tester');
      return;
    }

    $new_run_id = $this->database->insert('ys_ai_tester_run')
      ->fields([
        'uid' => $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
        'source_filename' => $run->source_filename,
        'source_content' => $run->source_content,
        'source_run_id' => $run_id,
        'status' => 'processing',
        'question_count' => count($questions),
      ])
      ->execute();

    $operations = [];
    foreach ($questions as $delta => $question) {
      $operations[] = [
        [AiTesterBatch::class, 'processQuestion'],
        [(int) $new_run_id, $question, $delta],
      ];
    }

    batch_set([
      'title' => $this->t('Re-running AI tests'),
      'operations' => $operations,
      'finished' => [AiTesterBatch::class, 'finished'],
      'progress_message' => $this->t('Processed @current of @total questions.'),
    ]);

    $this->messenger()->addStatus($this->t('Re-running @count question(s) from run #@id as a new run.', [
      '@count' => count($questions),
      '@id' => $run_id,
    ]));
    $form_state->setRedirect('ys_ai_tester.tester');
  }

  /**
   * Counts reruns of a run that are still processing.
   */
  protected function countInFlight(int $run_id): int {
    return (int) $this->database->select('ys_ai_tester_run', 'r')
      ->condition('source_run_id', $run_id)
      ->condition('status', 'processing')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Loads a run row for re-running, or throws a 404.
   */
  protected function loadRun(int $run_id): object {
    $run = $this->database->query(
      'SELECT id, source_filename, source_content, source_run_id, status, question_count FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }
    return $run;
  }

  /**
   * Returns the warning message for a blocked rerun reason.
   */
  protected function blockedMessage(string $reason): \Stringable|string {
    return match ($reason) {
      'source_processing' => $this->t('Run #@id is still processing. Wait until it finishes before re-running it.', ['@id' => $this->runId]),
      'already_running' => $this->t('A rerun of run #@id is already in progress. Wait for it to finish.', ['@id' => $this->runId]),
      default => $this->t('This run cannot be re-run right now.'),
    };
  }

}
