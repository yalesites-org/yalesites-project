<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing the Beacon system instructions.
 *
 * Saving creates a new version in the local storage; the active version is
 * read at request time when the chat prompt is built, so no synchronization
 * step is needed.
 */
class SystemInstructionsForm extends FormBase {

  /**
   * The system instructions storage.
   *
   * @var \Drupal\ys_beacon\Service\SystemInstructionsStorage
   */
  protected SystemInstructionsStorage $storage;

  /**
   * Constructs a SystemInstructionsForm.
   *
   * @param \Drupal\ys_beacon\Service\SystemInstructionsStorage $storage
   *   The system instructions storage.
   */
  public function __construct(SystemInstructionsStorage $storage) {
    $this->storage = $storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_beacon.system_instructions_storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_beacon_system_instructions_form';
  }

  /**
   * Get the maximum instructions length from configuration.
   *
   * @return int
   *   The maximum instructions length.
   */
  protected function getMaxInstructionsLength(): int {
    return $this->config('ys_beacon.settings')->get('instructions_max_length') ?: 4000;
  }

  /**
   * Get the warning threshold from configuration.
   *
   * @return int
   *   The warning threshold.
   */
  protected function getWarningThreshold(): int {
    return $this->config('ys_beacon.settings')->get('instructions_warning_threshold') ?: 3500;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'ys_beacon/system_instructions';

    $active = $this->storage->getActiveInstructions();

    $form['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-instructions-status']],
    ];

    $form['status']['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p><strong>Current version:</strong> @version | <strong>Total versions:</strong> @total | <a href="@history_url">View version history</a></p>', [
        '@version' => $active ? $active['version'] : $this->t('None'),
        '@total' => $this->storage->getVersionCount(),
        '@history_url' => Url::fromRoute('ys_beacon.instructions_versions')->toString(),
      ]),
    ];

    $max_length = $this->getMaxInstructionsLength();
    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Instructions'),
      '#description' => '<span id="instructions-character-count" class="character-count">' . $this->t('Content recommended length set to @max characters.', ['@max' => number_format($max_length)]) . '</span>',
      '#default_value' => $active ? $active['instructions'] : '',
      '#rows' => 15,
      '#maxlength' => NULL,
      '#attributes' => [
        'data-maxlength' => $max_length,
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

    // Settings for the character counter behavior.
    $form['#attached']['drupalSettings']['ysBeaconSystemInstructions'] = [
      'warningThreshold' => $this->getWarningThreshold(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $raw = (string) $form_state->getValue('instructions');
    $instructions = trim($raw);

    // Core's #required check only catches a truly empty value; reject
    // whitespace-only input here without doubling the empty-field error.
    if ($instructions === '' && $raw !== '') {
      $form_state->setErrorByName('instructions', $this->t('System instructions cannot be empty.'));
    }

    $max_length = $this->getMaxInstructionsLength();
    if (mb_strlen($instructions) > $max_length) {
      $form_state->setErrorByName('instructions', $this->t('Instructions are @count characters, which exceeds the maximum of @max characters.', [
        '@count' => mb_strlen($instructions),
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

    if (!$this->storage->areInstructionsDifferent($instructions)) {
      $this->messenger()->addMessage($this->t('No changes detected. The instructions were not saved.'));
      return;
    }

    $version = $this->storage->createVersion($instructions, $notes);
    $this->messenger()->addMessage($this->t('System instructions saved as version @version.', [
      '@version' => $version,
    ]));
  }

}
