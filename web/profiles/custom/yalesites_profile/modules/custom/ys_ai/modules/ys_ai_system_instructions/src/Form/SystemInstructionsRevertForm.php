<?php

namespace Drupal\ys_ai_system_instructions\Form;

use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for reverting to a previous system instructions version.
 */
class SystemInstructionsRevertForm extends ConfirmFormBase {

  /**
   * The system instructions manager.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService
   */
  protected $instructionsManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The version to revert to.
   *
   * @var int
   */
  protected $version;

  /**
   * Constructs a SystemInstructionsRevertForm.
   *
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService $instructions_manager
   *   The system instructions manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(SystemInstructionsManagerService $instructions_manager, RequestStack $request_stack) {
    $this->instructionsManager = $instructions_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_ai_system_instructions.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_ai_system_instructions_revert_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $version = NULL) {
    // Check if the feature is enabled.
    $config = $this->config('ys_ai_system_instructions.settings');
    if (!$config->get('system_instructions_enabled')) {
      throw new AccessDeniedHttpException('System instruction modification is not enabled.');
    }

    $this->version = $version;

    // Validate that the version exists and is not already active.
    // Get full version data including instructions field.
    $target_version = $this->instructionsManager->getStorageService()->getVersion($version);
    $active_version = $this->instructionsManager->getStorageService()->getActiveInstructions();

    if (!$target_version) {
      $form['error'] = [
        '#markup' => $this->t('Version @version not found.', ['@version' => $version]),
      ];
      return $form;
    }

    if ($target_version['is_active']) {
      $form['error'] = [
        '#markup' => $this->t('Version @version is already active.', ['@version' => $version]),
      ];
      return $form;
    }

    // Show preview of what will change.
    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Preview Changes'),
      '#open' => TRUE,
    ];

    $form['preview']['current'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Instructions (Version @version)', ['@version' => $active_version['version']]),
      '#open' => FALSE,
    ];

    $form['preview']['current']['content'] = [
      '#type' => 'textarea',
      '#value' => $active_version['instructions'],
      '#rows' => 8,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    $form['preview']['target'] = [
      '#type' => 'details',
      '#title' => $this->t('Target Instructions (Version @version)', ['@version' => $version]),
      '#open' => TRUE,
    ];

    $form['preview']['target']['content'] = [
      '#type' => 'textarea',
      '#value' => $target_version['instructions'],
      '#rows' => 8,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to revert to version @version?', [
      '@version' => $this->version,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will make version @version the active system instructions and push the changes to the API. This action cannot be undone, but you can revert to the current version later if needed.', [
      '@version' => $this->version,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('ys_ai_system_instructions.versions');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Revert to Version @version', ['@version' => $this->version]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $result = $this->instructionsManager->revertToVersion($this->version);

    if ($result['success']) {
      $this->messenger()->addMessage($result['message']);
      $form_state->setRedirect('ys_ai_system_instructions.form');
    }
    else {
      $this->messenger()->addError($result['message']);
      $form_state->setRedirect('ys_ai_system_instructions.versions');
    }
  }

}
