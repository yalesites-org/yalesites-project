<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_ai_tester\AiTesterBatch;
use Drupal\ys_ai_tester\AnswerBackendInterface;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\RunProgress;
use Drupal\ys_ai_tester\StaleRunReconciler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for finishing an interrupted run in place.
 *
 * The counterpart to AiTesterRerunForm, and deliberately not the same thing: a
 * rerun asks every question again and records a new run, which is what you want
 * when comparing an assistant against its earlier self. A resume asks only the
 * questions this run never got to and writes them into the run itself, which is
 * what you want when a batch died partway and the answers it did collect are
 * still good.
 *
 * That distinction is the whole point on a long list. A 160-question run that
 * dropped at question 120 needs 40 questions to become usable; re-running it
 * costs all 160 again and leaves the partial run behind as clutter.
 *
 * Resume is insert-only: it touches deltas with no stored row and never
 * overwrites an answer, so it is safe to run repeatedly - including after a
 * resume itself gets interrupted.
 */
class AiTesterResumeForm extends ConfirmFormBase {

  /**
   * The run being resumed.
   *
   * @var object
   */
  protected object $run;

  /**
   * The run id being resumed.
   *
   * @var int
   */
  protected int $runId = 0;

  /**
   * The unanswered questions, keyed by delta.
   *
   * @var array
   */
  protected array $missing = [];

  /**
   * Constructs the AI Tester resume form.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\ys_ai_tester\AnswerBackendRegistry $backendRegistry
   *   The answer backend registry.
   * @param \Drupal\ys_ai_tester\RunProgress $runProgress
   *   Reports which of a run's questions are still unanswered.
   * @param \Drupal\ys_ai_tester\StaleRunReconciler $staleRunReconciler
   *   Releases runs whose batch died without reporting a status.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected Connection $database,
    protected AnswerBackendRegistry $backendRegistry,
    protected RunProgress $runProgress,
    protected StaleRunReconciler $staleRunReconciler,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('ys_ai_tester.answer_backend_registry'),
      $container->get('ys_ai_tester.run_progress'),
      $container->get('ys_ai_tester.stale_run_reconciler'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_ai_tester_resume_form';
  }

  /**
   * Returns why a resume must be refused, or NULL when it may proceed.
   *
   * Kept static and dependency-free so the decision is unit testable, matching
   * AiTesterRerunForm::isBlocked().
   *
   * @param string|null $status
   *   The run's current status.
   * @param int $missing_count
   *   How many of its questions have no stored outcome.
   * @param bool $backend_available
   *   Whether the assistant that answered the run still exists.
   *
   * @return string|null
   *   A reason key, or NULL when the resume may proceed.
   */
  public static function isBlocked(?string $status, int $missing_count, bool $backend_available): ?string {
    if (!$backend_available) {
      return 'backend_unavailable';
    }
    // A run still processing is either live or not yet reconciled; either way
    // its own batch is the thing that should be writing these rows.
    if ($status === AiTesterBatch::STATUS_PROCESSING) {
      return 'still_processing';
    }
    if ($missing_count < 1) {
      return 'nothing_missing';
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $run_id = NULL): array {
    $blocked = $this->guard((int) $run_id);
    $form_state->set('resume_run_id', $this->runId);
    if ($blocked !== NULL) {
      $this->messenger()->addWarning($this->blockedMessage($blocked));
      return $this->blockedBackLink();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Re-read at the point of mutation, as the rerun form does: a reload or a
    // second tab must not queue the same questions twice.
    $run_id = (int) $form_state->get('resume_run_id');
    $blocked = $this->guard($run_id);
    if ($blocked !== NULL) {
      $this->messenger()->addWarning($this->blockedMessage($blocked));
      $form_state->setRedirect('ys_ai_tester.tester');
      return;
    }

    // Claim the run before queueing anything, in one statement that both moves
    // it to 'processing' and requires that it was not already there. Two
    // confirms arriving together therefore cannot both proceed: only one update
    // matches, and the loser is refused having queued nothing. The check above
    // narrows the window but cannot close it, and duplicate deltas written into
    // the very run being repaired are the corruption this feature exists to
    // work around - so resume claims rather than checks. AiTesterRerunForm has
    // no equivalent because it has no row yet to claim.
    $claimed = (int) $this->database->update('ys_ai_tester_run')
      ->fields(['status' => AiTesterBatch::STATUS_PROCESSING, 'changed' => $this->time->getRequestTime()])
      ->condition('id', $run_id)
      ->condition('status', AiTesterBatch::STATUS_PROCESSING, '<>')
      ->execute();
    if ($claimed < 1) {
      $this->messenger()->addWarning($this->blockedMessage('still_processing'));
      $form_state->setRedirect('ys_ai_tester.tester');
      return;
    }

    $backend_id = $this->backendId();
    $operations = [];
    // The delta is preserved from the original list, so a resumed answer lands
    // in the same position it would have had, and the run stays comparable
    // question-for-question with its sibling.
    foreach ($this->missing as $delta => $question) {
      $operations[] = [
        [AiTesterBatch::class, 'processQuestion'],
        [$run_id, $question, (int) $delta, $backend_id],
      ];
    }

    batch_set([
      'title' => $this->t('Resuming AI test run #@id (@assistant)', [
        '@id' => $run_id,
        '@assistant' => $this->backendRegistry->labelFor($backend_id),
      ]),
      'operations' => $operations,
      // Its own callback, not finished(): the resume batch only processed the
      // missing questions, so the run's status has to come from everything the
      // run has stored rather than from this batch's tally.
      'finished' => [AiTesterBatch::class, 'resumeFinished'],
      'progress_message' => $this->t('Processed @current of @total remaining questions.'),
    ]);

    $this->messenger()->addStatus($this->t('Resuming run #@id — asking @count remaining question(s).', [
      '@id' => $run_id,
      '@count' => count($operations),
    ]));
    $form_state->setRedirect('ys_ai_tester.run', ['run_id' => $run_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable|string {
    return $this->t('Resume run #@id and ask its @count remaining question(s)?', [
      '@id' => $this->runId,
      '@count' => count($this->missing),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Stringable|string {
    return $this->t('Only the questions this run never answered are asked, through @backend. Answers already recorded are kept as they are, including the ones that failed — re-run the whole list instead if you want those asked again.', [
      '@backend' => $this->backendRegistry->labelFor($this->backendId()),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Stringable|string {
    return $this->t('Resume');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ys_ai_tester.tester');
  }

  /**
   * Loads a run, reconciling first, and reports why a resume is refused.
   *
   * The impure half of the guard, shared by both entry points so the inputs to
   * ::isBlocked() are assembled once. Reconciling has to come before the load:
   * a run whose batch died still reads 'processing', which would block the very
   * resume that exists to recover it.
   *
   * @param int $run_id
   *   The run to guard.
   *
   * @return string|null
   *   A reason key from ::isBlocked(), or NULL when the resume may proceed.
   */
  protected function guard(int $run_id): ?string {
    $this->runId = $run_id;
    $this->staleRunReconciler->reconcile();
    $this->loadRun($run_id);

    return static::isBlocked(
      $this->run->status,
      count($this->missing),
      $this->backendIsAvailable(),
    );
  }

  /**
   * Returns the message shown when a resume is refused.
   *
   * @param string $reason
   *   A reason key from ::isBlocked().
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   */
  protected function blockedMessage(string $reason): \Stringable|string {
    return match ($reason) {
      'backend_unavailable' => $this->t('Run #@id cannot be resumed because @backend is no longer available on this site.', [
        '@id' => $this->runId,
        '@backend' => $this->backendRegistry->labelFor($this->backendId()),
      ]),
      'still_processing' => $this->t('Run #@id is still running. Wait for it to finish before resuming it.', [
        '@id' => $this->runId,
      ]),
      'nothing_missing' => $this->t('Run #@id has an answer recorded for every question, so there is nothing to resume.', [
        '@id' => $this->runId,
      ]),
      // Named arms above cover every reason ::isBlocked() returns; this only
      // catches a reason added there and not here, where saying nothing precise
      // beats asserting something wrong about the run.
      default => $this->t('Run #@id cannot be resumed right now.', [
        '@id' => $this->runId,
      ]),
    };
  }

  /**
   * Returns the id of the assistant that answered the run.
   *
   * @return string
   *   The stored backend id.
   */
  protected function backendId(): string {
    return (string) ($this->run->backend ?? AnswerBackendInterface::DEFAULT_ID);
  }

  /**
   * Returns whether the run's assistant can still answer questions.
   *
   * @return bool
   *   TRUE when the assistant is registered and available.
   */
  protected function backendIsAvailable(): bool {
    return $this->backendRegistry->getAvailable($this->backendId()) !== NULL;
  }

  /**
   * Builds the refused-state form: a link back and nothing to confirm.
   *
   * Styled to match AiTesterRerunForm's own refusal, so the two actions in one
   * flow do not present the same dead end differently.
   *
   * @return array
   *   A render array with a single link back.
   */
  protected function blockedBackLink(): array {
    return [
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to tester'),
        '#url' => $this->getCancelUrl(),
        '#attributes' => ['class' => ['button']],
      ],
    ];
  }

  /**
   * Loads the run and its outstanding questions, or throws a 404.
   *
   * @param int $run_id
   *   The run to load.
   */
  protected function loadRun(int $run_id): void {
    $run = $this->database->query(
      'SELECT source_content, status, backend FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

    $this->run = $run;
    $this->missing = $this->runProgress->missingQuestions($run_id, (string) $run->source_content);
  }

}
