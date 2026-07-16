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

    // Create a wrapper for AJAX updates.
    $form['form_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'system-instructions-form-wrapper'],
    ];

    // Check if we need to show loading state.
    // Show loading only on initial page load, not on AJAX rebuilds.
    $show_loading = $form_state->get('show_loading');
    $refreshed = $form_state->get('refreshed');

    if ($show_loading === NULL && !$refreshed) {
      // First time loading the form - show loading state.
      $show_loading = TRUE;
      $form_state->set('show_loading', TRUE);
    }
    else {
      // Already refreshed or explicitly set to FALSE.
      $show_loading = FALSE;
    }

    if ($show_loading) {
      $form['form_wrapper']['loading'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['system-instructions-loading']],
      ];

      $form['form_wrapper']['loading']['spinner'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['ajax-progress', 'ajax-progress-throbber']],
        '#value' => '<div class="throbber">&nbsp;</div>',
      ];

      $form['form_wrapper']['loading']['message'] = [
        '#markup' => '<div class="message">' . $this->t('Syncing system instructions, please wait...') . '</div>',
      ];

      // Add auto-refresh after a short delay.
      $form['form_wrapper']['refresh'] = [
        '#type' => 'submit',
        '#value' => $this->t('Loading...'),
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'system-instructions-form-wrapper',
          'progress' => [
            'type' => 'none',
          ],
        ],
        '#attributes' => [
          'style' => 'display: none;',
          'id' => 'system-instructions-refresh-btn',
        ],
        '#submit' => ['::ajaxRefreshSubmit'],
        '#limit_validation_errors' => [],
      ];

      // Use JavaScript to auto-trigger the refresh.
      $form['#attached']['drupalSettings']['ysAiSystemInstructions']['autoRefresh'] = TRUE;

      return $form;
    }

    // Get current instructions and sync status.
    try {
      $current = $this->instructionsManager->getCurrentInstructions();
      $stats = $this->instructionsManager->getVersionStats();
    }
    catch (\Exception $e) {
      // If an unexpected error occurs, fall back to local version.
      // The API service already handles known errors gracefully, but catch
      // any unexpected exceptions here.
      $active = $this->instructionsManager->getStorageService()->getActiveInstructions();
      $current = [
        'instructions' => $active ? $active['instructions'] : '',
        'version' => $active ? (int) $active['version'] : 0,
        'synced' => FALSE,
        'sync_error' => 'Unable to sync with API. Using local version.',
      ];
      $stats = $this->instructionsManager->getVersionStats();
    }

    // Display sync status and version info.
    $form['form_wrapper']['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-status']],
    ];

    if (!$current['synced']) {
      $form['form_wrapper']['status']['sync_warning'] = [
        '#type' => 'item',
        '#markup' => $this->t('<div class="messages messages--warning">Warning: Could not sync system instructions: @error</div>', [
          '@error' => $current['sync_error'],
        ]),
      ];
    }

    $form['form_wrapper']['status']['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p><strong>Current version:</strong> @version | <strong>Total versions:</strong> @total | <a href="@history_url">View version history</a></p>', [
        '@version' => $current['version'] ?: $this->t('None'),
        '@total' => $stats['total_versions'],
        '@history_url' => Url::fromRoute('ys_ai_system_instructions.versions')->toString(),
      ]),
    ];

    $max_length = $this->getMaxInstructionsLength();
    $form['form_wrapper']['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Instructions'),
      '#description' => '<span id="instructions-character-count" class="character-count">' . $this->t('Content recommended length set to @max characters.', ['@max' => number_format($max_length)]) . '</span>',
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

    $form['form_wrapper']['notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version Notes'),
      '#description' => $this->t('Optional notes about this version (e.g., "Updated for new features", "Fixed typo").'),
      '#maxlength' => 255,
    ];

    $form['form_wrapper']['actions'] = [
      '#type' => 'actions',
    ];

    $form['form_wrapper']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Sync Instructions'),
      '#button_type' => 'primary',
    ];

    $form['form_wrapper']['actions']['sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync'),
      '#submit' => ['::syncFromApi'],
      '#attributes' => [
        'title' => $this->t('Perform a manual sync'),
      ],
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
    // Skip validation during AJAX refresh.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element && isset($triggering_element['#id']) && $triggering_element['#id'] === 'system-instructions-refresh-btn') {
      return;
    }

    // Skip validation if we're still in loading state.
    if ($form_state->get('show_loading')) {
      return;
    }

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

  /**
   * Submit handler for sync from API.
   */
  public function syncFromApi(array &$form, FormStateInterface $form_state) {
    $result = $this->instructionsManager->syncFromApi();

    if ($result['success']) {
      $this->messenger()->addMessage($result['message']);
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

  /**
   * Submit handler for force sync from API.
   */
  public function forceSyncFromApi(array &$form, FormStateInterface $form_state) {
    $result = $this->instructionsManager->syncFromApi(TRUE);

    if ($result['success']) {
      $this->messenger()->addMessage($result['message']);
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

  /**
   * Submit handler for the AJAX refresh.
   */
  public function ajaxRefreshSubmit(array &$form, FormStateInterface $form_state) {
    // Clear the loading state and rebuild the form.
    $form_state->set('show_loading', FALSE);
    $form_state->set('refreshed', TRUE);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback to refresh the form after loading.
   */
  public function ajaxRefreshForm(array &$form, FormStateInterface $form_state) {
    // Return the updated wrapper.
    return $form['form_wrapper'];
  }

}
