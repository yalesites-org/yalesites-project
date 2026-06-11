<?php

namespace Drupal\ys_ai_system_instructions\Form;

use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing system instructions.
 */
class SystemInstructionsForm extends FormBase {

  /**
   * The system instructions manager.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService
   */
  protected $instructionsManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SystemInstructionsForm.
   *
   * @param \Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService $instructions_manager
   *   The system instructions manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(SystemInstructionsManagerService $instructions_manager, ConfigFactoryInterface $config_factory) {
    $this->instructionsManager = $instructions_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_ai_system_instructions.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_ai_system_instructions_form';
  }

  /**
   * Get the maximum instructions length from configuration.
   *
   * @return int
   *   The maximum instructions length.
   */
  protected function getMaxInstructionsLength(): int {
    return $this->configFactory->get('ys_ai_system_instructions.settings')->get('system_instructions_max_length') ?: 4000;
  }

  /**
   * Get the warning threshold from configuration.
   *
   * @return int
   *   The warning threshold.
   */
  protected function getWarningThreshold(): int {
    return $this->configFactory->get('ys_ai_system_instructions.settings')->get('system_instructions_warning_threshold') ?: 3500;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'ys_ai_system_instructions/system_instructions';

    $current = $this->instructionsManager->getCurrentInstructions();
    $stats = $this->instructionsManager->getVersionStats();

    // Explain what these instructions are and what they control. This scopes
    // the form to this site's specific chatbot assistant so admins understand
    // it is not a global/platform-wide override.
    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t("<p><strong>System instructions</strong> are the standing guidance that shapes how this site's chatbot assistant behaves &mdash; its role, tone, and the guardrails it follows on every conversation. They apply only to the assistant powering this site's chatbot, not to any platform-wide or global behavior. Saving an update here makes it the active instruction the live chatbot uses.</p>"),
    ];

    $form['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-status']],
    ];

    $form['status']['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p><strong>Current version:</strong> @version | <strong>Total versions:</strong> @total | <a href="@history_url">View version history</a></p>', [
        '@version' => $current['version'] ?: $this->t('None'),
        '@total' => $stats['total_versions'],
        '@history_url' => Url::fromRoute('ys_ai_system_instructions.versions')->toString(),
      ]),
    ];

    $max_length = $this->getMaxInstructionsLength();
    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Instructions'),
      '#description' => $this->t("Describe the assistant's purpose, scope, tone, and any limits it should respect. This text is sent to the model with every chat, so clear, specific guidance produces more consistent answers.") . ' <span id="instructions-character-count" class="character-count">' . $this->t('Content recommended length set to @max characters.', ['@max' => number_format($max_length)]) . '</span>',
      '#default_value' => $current['instructions'],
      '#rows' => 15,
      '#maxlength' => NULL,
      '#attributes' => [
        'data-maxlength' => $max_length,
        'data-maxlength-warning-class' => 'warning',
        'data-maxlength-limit-reached-class' => 'error',
      ],
      '#required' => TRUE,
    ];

    $form['notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version Notes'),
      '#description' => $this->t('Optional notes about this version (e.g., "Updated for new features", "Fixed typo").'),
      '#maxlength' => 255,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Instructions'),
      '#button_type' => 'primary',
    ];

    // Add JavaScript for character counting.
    $form['#attached']['drupalSettings']['ysAiSystemInstructions'] = [
      'maxLength' => $max_length,
      'warningThreshold' => $this->getWarningThreshold(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $instructions = $form_state->getValue('instructions');
    $instructions = $instructions ? trim($instructions) : '';

    if (empty($instructions)) {
      $form_state->setErrorByName('instructions', $this->t('System instructions cannot be empty.'));
    }

    // Soft validation - warn but don't prevent submission for large content.
    $max_length = $this->getMaxInstructionsLength();
    if (strlen($instructions) > $max_length) {
      $this->messenger()->addWarning($this->t('Instructions are @count characters, which exceeds the recommended maximum of @max characters. This may impact AI performance.', [
        '@count' => strlen($instructions),
        '@max' => number_format($max_length),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $instructions = trim($form_state->getValue('instructions'));
    $notes = trim($form_state->getValue('notes'));

    $result = $this->instructionsManager->saveInstructions($instructions, $notes);

    if ($result['success']) {
      $this->messenger()->addMessage($result['message']);
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

}
