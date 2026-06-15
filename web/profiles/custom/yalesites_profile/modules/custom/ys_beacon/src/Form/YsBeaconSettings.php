<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-facing settings form for the Beacon chat widget.
 *
 * Mirrors the editor-facing options of the legacy ai_engine chat settings so
 * site administrators keep a familiar experience. Turning the chat on for the
 * first time provisions the site's Azure AI Search index automatically when
 * none is configured yet.
 */
class YsBeaconSettings extends ConfigFormBase {

  /**
   * Config name.
   *
   * @var string
   */
  const CONFIG_NAME = 'ys_beacon.settings';

  /**
   * The Beacon index manager.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexManager
   */
  protected BeaconIndexManager $indexManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->indexManager = $container->get('ys_beacon.index_manager');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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

    if (!$config->get('azure_index_name')) {
      $form['not_configured'] = [
        '#markup' => '<p>' . $this->t('No search index is assigned to this site yet. One will be created automatically (named %name) the first time the chat widget is enabled.', [
          '%name' => $this->indexManager->getDefaultIndexName(),
        ]) . '</p>',
        '#weight' => -20,
      ];
    }

    $form['enable_chat'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat widget'),
      '#default_value' => $config->get('enable_chat') ?? FALSE,
      '#description' => $this->t('Enable or disable the chat service across the site. Chat can be launched by using href="#launch-chat" on any link.'),
      '#weight' => -10,
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

    $form['floating_button_icon'] = [
      '#type' => 'select',
      '#title' => $this->t('Floating button icon'),
      '#description' => $this->t('Select the icon to display on the floating chat button.'),
      '#options' => [
        'fa-comments' => $this->t('Chat (default)'),
        'fa-sparkles' => $this->t('Sparkles'),
      ],
      '#default_value' => $config->get('floating_button_icon') ?? 'fa-comments',
      '#required' => TRUE,
      '#weight' => -7,
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Never allow two chat widgets at once: the legacy ai_engine chat must be
    // turned off before Beacon chat is enabled.
    if ($form_state->getValue('enable_chat') && ys_beacon_legacy_chat_active()) {
      $form_state->setErrorByName('enable_chat', $this->t('The legacy AI Engine chat widget is currently enabled. Disable it before enabling Beacon chat so visitors only see one chat widget.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $enable_chat = (bool) $form_state->getValue('enable_chat');
    // Capture the previously saved toggle before the config is mutated so the
    // index status is only changed on an actual on/off transition.
    $previous_enable = (bool) $this->config(self::CONFIG_NAME)->getOriginal('enable_chat');
    $this->config(self::CONFIG_NAME)
      ->set('enable_chat', $enable_chat)
      ->set('floating_button', (bool) $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->set('floating_button_icon', $form_state->getValue('floating_button_icon'))
      ->set('prompts', array_values(array_filter($form_state->getValue('prompts'))))
      ->set('disclaimer', $form_state->getValue('disclaimer'))
      ->set('footer', $form_state->getValue('footer'))
      ->save();

    // Keep the Search API index status in sync with the chat toggle. The
    // config override forces the index off while chat is disabled, so the
    // explicit status changes only take effect for the matching transition.
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('ys_beacon');
    if (!$index) {
      // Abort if the index does not exist; this should never happen.
      parent::submitForm($form, $form_state);
      return;
    }

    if ($enable_chat) {
      // Ensure the index exists. Runs on first enable and also retries on a
      // later re-save if an earlier provisioning attempt failed.
      if (!$this->config(self::CONFIG_NAME)->get('azure_index_name')) {
        $this->provisionIndex();
      }
      // Enable indexing and queue content when chat turns on, but only once an
      // index actually exists: a failed provision leaves the index off (the
      // config override forces it off while no index name is set), so we never
      // enable an index with no Azure backing.
      if (!$previous_enable && $this->config(self::CONFIG_NAME)->get('azure_index_name')) {
        $index->setStatus(TRUE)->save();
        $index->reindex();
      }
    }
    elseif ($previous_enable) {
      // Chat turned off: stop indexing.
      $index->setStatus(FALSE)->save();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Provisions the site's search index when chat is first enabled.
   *
   * Creates the per-site Azure AI Search index only when it does not exist
   * yet, stores its name, and queues all site content for indexing.
   */
  protected function provisionIndex(): void {
    try {
      $index_name = $this->indexManager->provision();
    }
    catch (\RuntimeException $e) {
      $this->logger('ys_beacon')->error('Automatic index provisioning failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addWarning($this->t('The chat widget is enabled, but the search index could not be created automatically. The assistant will not find site content until the index is configured in the Beacon administration settings.'));
      return;
    }
    $this->messenger()->addStatus($this->t('The search index %name is ready and site content has been queued for indexing.', ['%name' => $index_name]));
  }

}
