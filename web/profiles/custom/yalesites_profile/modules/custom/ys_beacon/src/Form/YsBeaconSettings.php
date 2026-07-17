<?php

namespace Drupal\ys_beacon\Form;

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
   * The Beacon index manager.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexManager
   */
  protected BeaconIndexManager $indexManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->indexManager = $container->get('ys_beacon.index_manager');
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

    // Only the "Index now" control is mirrored here; the "Re-index all content"
    // control lives on the Beacon administration form.
    $form['indexing'] = $this->buildIndexingControls(FALSE);

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
    $config = $this->config(self::CONFIG_NAME);
    // Capture the previously saved toggle before the config is mutated so the
    // index status is only changed on an actual on/off transition.
    $previous_enable = (bool) $config->getOriginal('enable_chat');
    // The AI metadata fields are always shown while the chatbot is on; their
    // visibility is otherwise a platform-admin setting and is not editable on
    // this form.
    $enable_metadata_fields = $enable_chat || (bool) $config->get('enable_metadata_fields');
    $config
      ->set('enable_chat', $enable_chat)
      ->set('floating_button', (bool) $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->set('floating_button_icon', self::FLOATING_BUTTON_ICON)
      ->set('prompts', array_values(array_filter($form_state->getValue('prompts'))))
      ->set('disclaimer', $form_state->getValue('disclaimer'))
      ->set('footer', $form_state->getValue('footer'))
      ->set('enable_metadata_fields', $enable_metadata_fields)
      ->save();

    // Keep the Search API index status in sync with the chat toggle. The
    // config override forces the index off while chat is disabled, so the
    // explicit status changes only take effect for the matching transition.
    $index = $this->loadBeaconIndex();
    if (!$index) {
      // Abort if the index does not exist; this should never happen.
      parent::submitForm($form, $form_state);
      return;
    }

    if ($enable_chat) {
      // Ensure the assigned index actually exists in Azure, creating it if it
      // does not: first enable (no name yet), a retry after a failed provision,
      // or an index that was deleted or whose configured name was changed to
      // one not yet created. An already-existing index is left untouched.
      if ($this->configuredIndexMissing()) {
        $this->provisionIndex();
      }
      // Enable indexing and queue content when chat turns on, but only once an
      // index actually exists: a failed provision leaves the index off (the
      // config override forces it off while no index name is set), so we never
      // enable an index with no Azure backing.
      if (!$previous_enable && $this->config(self::CONFIG_NAME)->get('azure_index_name')) {
        $this->saveIndexStatus(TRUE);
        // Rebuild the tracker so existing content is queued for indexing on
        // the freshly enabled index: reindex() only re-flags already-tracked
        // items and leaves a never-seeded tracker empty (issue #1383).
        $index->rebuildTracker();
      }
    }
    elseif ($previous_enable) {
      // Chat turned off: stop indexing.
      $this->saveIndexStatus(FALSE);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Provisions the site's search index, creating it when it does not exist.
   *
   * Targets the configured Azure index name, falling back to the per-site
   * default when none is assigned yet, and creates it only when it is missing,
   * then stores its name and queues existing content for indexing.
   */
  protected function provisionIndex(): void {
    // Provision the index this site is configured to use so a custom or shared
    // name is (re)created, not just the per-site default.
    $configured = (string) $this->config(self::CONFIG_NAME)->get('azure_index_name');
    try {
      $index_name = $this->indexManager->provision($configured ?: NULL);
    }
    catch (\RuntimeException $e) {
      $this->logger('ys_beacon')->error('Automatic index provisioning failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addWarning($this->t('The chat widget is enabled, but the search index could not be created automatically. The assistant will not find site content until the index is configured in the Beacon administration settings.'));
      return;
    }
    $this->messenger()->addStatus($this->t('The search index %name is ready and site content has been queued for indexing.', ['%name' => $index_name]));
  }

  /**
   * Whether the configured Azure index still needs to be created.
   *
   * TRUE when this site is writable and either has no index name assigned yet
   * or the assigned index no longer exists in Azure (deleted, or a newly chosen
   * name). A read-only borrower never provisions the collection it reads, and
   * an unreachable endpoint returns FALSE so a transient outage neither blocks
   * the save nor triggers a doomed provision on every re-save.
   *
   * @return bool
   *   TRUE when provisioning should run.
   */
  protected function configuredIndexMissing(): bool {
    $config = $this->config(self::CONFIG_NAME);
    // A read-only borrower must never create or write the collection it reads.
    if ($config->get('read_only')) {
      return FALSE;
    }
    $name = (string) $config->get('azure_index_name');
    if ($name === '') {
      return TRUE;
    }
    try {
      return !$this->indexManager->indexExists($name);
    }
    catch (\RuntimeException $e) {
      // Azure unreachable: don't block the save or attempt a doomed provision.
      return FALSE;
    }
  }

  /**
   * Persists the Beacon index enabled/disabled status.
   *
   * Saves an override-free copy of the index so the runtime status and
   * read-only overrides layered on by YsBeaconConfigOverrides are never baked
   * into the synced search_api.index config (which, unlike ys_beacon.settings,
   * is written back by config import). Mirrors Search API's own CommandHelper,
   * which reloads override-free before persisting a status change.
   */
  protected function saveIndexStatus(bool $status): void {
    $index = $this->entityTypeManager->getStorage('search_api_index')->loadOverrideFree($this->searchIndexId());
    if ($index) {
      $index->setStatus($status)->save();
    }
  }

}
