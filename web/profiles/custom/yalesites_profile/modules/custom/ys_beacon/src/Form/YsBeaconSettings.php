<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-facing settings form for the Beacon chat widget.
 *
 * Site administrators manage the chat widget's presentation here - the floating
 * button, initial prompts, disclaimer, and footer. Whether the chat widget is
 * enabled and the content indexing that backs it are platform-admin actions
 * (managed on the Platform Admin Settings page), so this form surfaces them
 * only as a read-only status.
 */
class YsBeaconSettings extends ConfigFormBase {

  use BeaconIndexingControlsTrait;

  /**
   * Config name.
   *
   * @var string
   */
  const CONFIG_NAME = 'ys_beacon.settings';

  /**
   * The Font Awesome icon class always used for the floating chat button.
   *
   * The icon is no longer site-configurable: every YaleSites site shows the
   * same "sparkles" mark. This constant is the single source of truth, written
   * on save and used as the render fallback.
   *
   * @var string
   */
  const FLOATING_BUTTON_ICON = 'fa-sparkles';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    // The entity type manager and indexing batch helper back the shared
    // indexing trait: the Platform Admin Settings page delegates its "Re-index
    // all content" / "Index now" buttons to a class-resolved instance of this
    // form, so the wiring must stay even though this form no longer renders
    // those controls.
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->indexingBatchHelper = $container->get('search_api.indexing_batch_helper');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_beacon_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    // Enabling the chat widget and indexing its content are platform-admin
    // actions (managed on the Platform Admin Settings page), so show them here
    // only as a read-only status for context.
    $form['beacon_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Beacon status'),
      '#weight' => -10,
    ];
    $form['beacon_status']['chat_state'] = [
      '#type' => 'item',
      '#title' => $this->t('Chat widget'),
      '#markup' => $config->get('enable_chat') ? $this->t('Enabled') : $this->t('Disabled'),
      '#description' => $this->t('Managed by a platform administrator; it cannot be changed here.'),
    ];
    $form['beacon_status']['index_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Content index'),
      '#markup' => $this->indexStatusMarkup(),
    ];

    $form['floating_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable floating chat button'),
      '#default_value' => $config->get('floating_button') ?? FALSE,
      '#weight' => -9,
    ];

    $form['floating_button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Floating button text'),
      '#default_value' => $config->get('floating_button_text') ?: $this->t('Beacon Chat'),
      '#required' => TRUE,
      '#weight' => -8,
    ];

    $form['prompts'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Initial Prompts'),
      '#description' => $this->t('A list of example prompts to show when the chat is initially launched'),
      '#tree' => TRUE,
    ];
    for ($i = 0; $i < 4; $i++) {
      $form['prompts'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Prompt'),
        '#default_value' => $config->get('prompts')[$i] ?? '',
      ];
    }

    $form['disclaimer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Disclaimer'),
      '#description' => $this->t('Appears below the chat form. No markup allowed, max of about 100 characters'),
      '#default_value' => $config->get('disclaimer') ?? NULL,
      '#rows' => 2,
    ];

    $form['footer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer'),
      '#description' => $this->t('Displays at the bottom of the modal window. May include links and basic HTML.'),
      '#default_value' => $config->get('footer') ?? NULL,
      '#rows' => 2,
    ];

    // Link to the per-site system instructions when the user has access.
    $instructions_url = Url::fromRoute('ys_beacon.instructions');
    if ($instructions_url->access($this->currentUser())) {
      $form['system_instructions_link'] = [
        '#type' => 'item',
        '#title' => $this->t('System Instructions Management'),
        '#description' => $this->t("Configure the AI assistant's behavior and responses."),
        '#weight' => 100,
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Manage System Instructions'),
          '#url' => $instructions_url,
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Only the presentation settings are editable here. Whether the chat widget
    // is enabled (enable_chat) and its metadata fields (enable_metadata_fields)
    // are platform-admin concerns, so this form never writes them: config save
    // leaves unset keys - and the platform admin's values - untouched.
    $this->config(self::CONFIG_NAME)
      ->set('floating_button', (bool) $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->set('floating_button_icon', self::FLOATING_BUTTON_ICON)
      ->set('prompts', array_values(array_filter($form_state->getValue('prompts'))))
      ->set('disclaimer', $form_state->getValue('disclaimer'))
      ->set('footer', $form_state->getValue('footer'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
