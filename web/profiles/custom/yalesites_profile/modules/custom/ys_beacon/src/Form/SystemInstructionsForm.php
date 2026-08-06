<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\MarkdownConverter;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing the Beacon system instructions.
 *
 * Instructions are authored in a CKEditor WYSIWYG (the restricted_html format)
 * but stored and consumed as Markdown: HTML is converted to Markdown on save
 * and back to HTML when the form loads. Saving creates a new version in local
 * storage; the active version is read at request time when the chat prompt is
 * built, so no synchronization step is needed.
 */
class SystemInstructionsForm extends FormBase {

  /**
   * The system instructions storage.
   *
   * @var \Drupal\ys_beacon\Service\SystemInstructionsStorage
   */
  protected SystemInstructionsStorage $storage;

  /**
   * The Markdown/HTML converter.
   *
   * @var \Drupal\ys_beacon\Service\MarkdownConverter
   */
  protected MarkdownConverter $markdownConverter;

  /**
   * Constructs a SystemInstructionsForm.
   *
   * @param \Drupal\ys_beacon\Service\SystemInstructionsStorage $storage
   *   The system instructions storage.
   * @param \Drupal\ys_beacon\Service\MarkdownConverter $markdown_converter
   *   The Markdown/HTML converter.
   */
  public function __construct(SystemInstructionsStorage $storage, MarkdownConverter $markdown_converter) {
    $this->storage = $storage;
    $this->markdownConverter = $markdown_converter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_beacon.system_instructions_storage'),
      $container->get('ys_beacon.markdown_converter')
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
      '#type' => 'text_format',
      '#title' => $this->t('System Instructions'),
      '#default_value' => $active ? $this->markdownConverter->toHtml($active['instructions']) : '',
      '#format' => 'restricted_html',
      '#allowed_formats' => ['restricted_html'],
      '#rows' => 15,
      '#required' => TRUE,
    ];

    // Character counter rendered below the editor; the JS keeps it in sync with
    // the CKEditor content (see js/system-instructions.js).
    $form['instructions_character_count'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Content recommended length set to @max characters.', ['@max' => number_format($max_length)]),
      '#attributes' => [
        'id' => 'instructions-character-count',
        'class' => ['character-count'],
        'aria-live' => 'polite',
      ],
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
      'maxLength' => $max_length,
      'warningThreshold' => $this->getWarningThreshold(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // The text_format element returns ['value' => html, 'format' => ...]; the
    // canonical stored form is the Markdown converted from that HTML, so all
    // validation (and the saved value) operates on the Markdown.
    $value = $form_state->getValue('instructions');
    $html = is_array($value) ? (string) ($value['value'] ?? '') : (string) $value;
    $instructions = $this->markdownConverter->toMarkdown($html);

    // Stash the converted Markdown so submitForm() does not re-convert.
    $form_state->setValue('instructions_markdown', $instructions);

    // Core's #required check only catches a truly empty value; reject
    // whitespace-only / markup-only input here without doubling the error.
    if ($instructions === '' && trim($html) !== '') {
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
    // Use the Markdown converted in validateForm() (canonical stored form).
    $instructions = trim((string) $form_state->getValue('instructions_markdown'));
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
