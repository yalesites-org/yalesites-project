<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for reverting to a previous system instructions version.
 */
class SystemInstructionsRevertForm extends ConfirmFormBase {

  /**
   * The system instructions storage.
   *
   * @var \Drupal\ys_beacon\Service\SystemInstructionsStorage
   */
  protected SystemInstructionsStorage $storage;

  /**
   * The version to revert to.
   *
   * @var int
   */
  protected $version;

  /**
   * Constructs a SystemInstructionsRevertForm.
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
    return 'ys_beacon_system_instructions_revert_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $version = NULL) {
    $this->version = $version;

    // Validate that the version exists and is not already active.
    $target_version = $this->storage->getVersion($version);
    $active_version = $this->storage->getActiveInstructions();

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

    if ($active_version) {
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
    }

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
    return $this->t('This will make version @version the active system instructions. This action cannot be undone, but you can revert to the current version later if needed.', [
      '@version' => $this->version,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('ys_beacon.instructions_versions');
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
    if ($this->storage->setActiveVersion($this->version)) {
      $this->messenger()->addMessage($this->t('Reverted to system instructions version @version.', [
        '@version' => $this->version,
      ]));
      $form_state->setRedirect('ys_beacon.instructions');
    }
    else {
      $this->messenger()->addError($this->t('Could not revert to version @version.', [
        '@version' => $this->version,
      ]));
      $form_state->setRedirect('ys_beacon.instructions_versions');
    }
  }

}
