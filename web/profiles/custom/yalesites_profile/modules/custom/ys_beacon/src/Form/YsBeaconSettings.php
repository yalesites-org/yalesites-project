<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\IndexingBatchHelperInterface;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drupal\ys_beacon\Service\SystemPromptBuilder;
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Search API indexing batch helper.
   *
   * @var \Drupal\search_api\Utility\IndexingBatchHelperInterface
   */
  protected IndexingBatchHelperInterface $indexingBatchHelper;

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

    $form['guardrail'] = [
      '#type' => 'details',
      '#title' => $this->t('Guardrail'),
      '#open' => FALSE,
      '#weight' => 20,
    ];
    $form['guardrail']['platform_guardrail'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform guardrail (fixed)'),
      '#open' => FALSE,
      '#description' => $this->t('Defined in code and prepended to every chat request. It is identical on every YaleSites site and cannot be changed here.'),
    ];
    $form['guardrail']['platform_guardrail']['text'] = [
      '#markup' => nl2br(Html::escape(SystemPromptBuilder::PLATFORM_GUARDRAIL)),
    ];
    $form['guardrail']['guardrail_supplement'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional guardrail rules for this site'),
      '#description' => $this->t('Appended after the platform guardrail on every chat request. Use this to make this specific site stricter; it can add rules but never relax the platform guardrail.'),
      '#default_value' => $config->get('guardrail_supplement'),
      '#rows' => 4,
    ];

    $form['indexing'] = [
      '#type' => 'details',
      '#title' => $this->t('Indexing'),
      '#open' => TRUE,
      '#weight' => 30,
    ];
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      // This site borrows another site's collection: content indexing is owned
      // by that site, so the local re-index / index-now controls are hidden and
      // the status is replaced with a short explanatory note.
      $form['indexing']['status'] = [
        '#markup' => '<p>' . $this->readOnlyNotice() . '</p>',
      ];
    }
    else {
      $form['indexing']['status'] = [
        '#markup' => '<p>' . $this->indexStatusSummary() . '</p>',
      ];
      $form['indexing']['reindex'] = [
        '#type' => 'submit',
        '#value' => $this->t('Re-index all content'),
        '#submit' => ['::reindexAll'],
        '#limit_validation_errors' => [],
      ];
      $form['indexing']['index_now'] = [
        '#type' => 'submit',
        '#value' => $this->t('Index now'),
        // Dedicated handler only: the main config submit must not run, so chat
        // settings are not saved when the user just wants to flush the queue.
        '#submit' => ['::indexNow'],
        '#limit_validation_errors' => [],
        // Disabled unless the Beacon index is enabled and has items waiting to
        // be indexed. Mirrors Search API's own "Index now" disabled behaviour.
        '#disabled' => $this->indexRemainingItems() < 1,
      ];
    }

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
      ->set('guardrail_supplement', $form_state->getValue('guardrail_supplement'))
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
   * Submit handler queueing all content for re-indexing.
   *
   * Replaces the legacy "Upsert All Documents" action: items are re-tracked
   * and re-embedded into the vector database on the next indexing runs.
   */
  public function reindexAll(array &$form, FormStateInterface $form_state): void {
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->readOnlyNotice());
      return;
    }
    if ($index && $index->status()) {
      // rebuildTracker() re-enumerates the datasources and marks every item
      // for indexing, so it repopulates a never-seeded tracker as well as
      // re-queueing tracked content; reindex() would only do the latter
      // (issue #1383).
      $index->rebuildTracker();
      $this->messenger()->addStatus($this->t('All content has been queued for re-indexing into the Beacon vector database.'));
    }
    else {
      $this->messenger()->addWarning($this->t('The Beacon index is not enabled on this site. Enable the chat widget first.'));
    }
  }

  /**
   * Submit handler running the Search API indexing batch for the Beacon index.
   *
   * Calls Search API's indexing batch helper directly so the only Search API
   * capability exposed to site administrators is indexing this one index; no
   * "administer search_api" permission or Search API route is required. Batch
   * size and limit are intentionally omitted so the index's own defaults are
   * used (all remaining items, in batches of the index cron_limit). Drupal's
   * Form API runs the queued batch and returns the user to this form.
   */
  public function indexNow(array &$form, FormStateInterface $form_state): void {
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->readOnlyNotice());
      return;
    }
    if (!$index || !$index->status()) {
      $this->messenger()->addWarning($this->t('The Beacon index is not enabled on this site. Enable the chat widget first.'));
      return;
    }
    // Re-check the queue server-side: the button's #disabled state is only
    // evaluated at render time, so a stale page or a queue drained by cron
    // between render and submit could otherwise start an empty batch, which
    // Search API reports as a failure rather than a no-op.
    if ($this->indexRemainingItems() < 1) {
      $this->messenger()->addStatus($this->t('There is no content waiting to be indexed.'));
      return;
    }
    try {
      $this->indexingBatchHelper->createBatch($index);
    }
    catch (SearchApiException $e) {
      $this->messenger()->addWarning($this->t('Unable to start indexing right now. Please try again shortly.'));
    }
  }

  /**
   * Builds a short indexing status summary.
   */
  protected function indexStatusSummary(): string {
    $index = $this->loadBeaconIndex();
    if (!$index || !$index->status()) {
      return (string) $this->t('The Beacon index is currently disabled. It enables automatically once the chat widget is turned on.');
    }
    try {
      $tracker = $index->getTrackerInstance();
      return (string) $this->t('@indexed of @total items indexed.', [
        '@indexed' => $tracker->getIndexedItemsCount(),
        '@total' => $tracker->getTotalItemsCount(),
      ]);
    }
    catch (\Throwable $e) {
      return (string) $this->t('Index status unavailable.');
    }
  }

  /**
   * Counts tracked items not yet indexed into the Beacon vector database.
   *
   * Returns 0 when the index is missing or disabled so the "Index now" button
   * stays disabled in those states.
   */
  protected function indexRemainingItems(): int {
    $index = $this->loadBeaconIndex();
    if (!$index || !$index->status()) {
      return 0;
    }
    try {
      return (int) $index->getTrackerInstance()->getRemainingItemsCount();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * The note shown when the Beacon index borrows another site's collection.
   *
   * Displayed in place of the indexing controls and returned by the indexing
   * submit handlers when they are blocked, so the wording lives in one place.
   */
  protected function readOnlyNotice(): TranslatableMarkup {
    return $this->t('This site uses a shared, read-only index; content indexing is managed by the owning site.');
  }

  /**
   * Loads the Search API index backing the Beacon chatbot.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index entity, or NULL when it does not exist.
   */
  protected function loadBeaconIndex(): ?IndexInterface {
    return $this->entityTypeManager->getStorage('search_api_index')->load($this->searchIndexId());
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

  /**
   * The Search API index machine name backing the chatbot.
   */
  protected function searchIndexId(): string {
    return $this->config(self::CONFIG_NAME)->get('search_index_id') ?: 'ys_beacon';
  }

}
