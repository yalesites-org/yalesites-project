<?php

namespace Drupal\ys_beacon\Plugin\PlatformAdminSetting;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Form\YsBeaconSettings;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\PlatformAdminSettingBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform admin Beacon (AI Chat) section.
 *
 * Contributes the per-site Beacon controls to the Platform Admin Settings page.
 * Only platform admins reach that page, so this is where Beacon is switched on
 * for a site (the authorization flag), where the chat widget is toggled, and
 * where indexing can be driven without navigating into the per-site Beacon
 * forms.
 *
 * The indexing actions reuse the site settings form verbatim: the "Re-index all
 * content" and "Index now" buttons delegate to
 * YsBeaconSettings::reindexAll() / ::indexNow() through the class resolver, so
 * the tracker-rebuild and search_api.indexing_batch_helper batch paths (and
 * their read-only / disabled / empty-queue guards) are shared, not duplicated.
 * The chat toggle writes ys_beacon.settings:enable_chat - the flag the chat
 * widget reads across the site - and manages its on/off index side effects via
 * BeaconIndexManager. It is the only place the widget is enabled or disabled;
 * the per-site settings form shows that state read-only.
 */
#[PlatformAdminSetting(
  id: 'ys_beacon',
  label: new TranslatableMarkup('Beacon (AI Chat)'),
  weight: 0,
)]
class BeaconPlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Beacon index manager.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexManager
   */
  protected BeaconIndexManager $indexManager;

  /**
   * The Beacon logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a BeaconPlatformAdminSetting object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ys_beacon\Service\BeaconIndexManager $index_manager
   *   The Beacon index manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Beacon logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    BeaconIndexManager $index_manager,
    MessengerInterface $messenger,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $current_user);
    $this->entityTypeManager = $entity_type_manager;
    $this->indexManager = $index_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('ys_beacon.index_manager'),
      $container->get('messenger'),
      $container->get('logger.channel.ys_beacon'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    // Read the site's own saved values override-free so the toggles reflect
    // the stored state: the config override forces enable_chat off at runtime
    // for an unauthorized site, which would otherwise misreport the value.
    $settings = $this->configFactory->getEditable(BeaconAuthorization::CONFIG_NAME);

    $form['platform_authorized'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow site admins to configure and use Beacon'),
      '#description' => $this->t('When enabled, site administrators can turn on and configure the Beacon AI chat for this site. When disabled, all Beacon features are hidden and inactive for this site.'),
      '#default_value' => (bool) $settings->get(BeaconAuthorization::FLAG),
    ];

    $form['enable_chat'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat widget'),
      '#description' => $this->t('Turn the Beacon chat widget on or off for this site.'),
      '#default_value' => (bool) $settings->get('enable_chat'),
    ];

    $form['indexing'] = [
      '#type' => 'container',
    ];
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      // A borrowing site's content indexing is owned by the site it borrows
      // from, so the local indexing controls are hidden and replaced with a
      // short explanatory note, matching the site settings form.
      $form['indexing']['read_only_notice'] = [
        '#markup' => '<p>' . $this->t('This site uses a shared, read-only index; content indexing is managed by the owning site.') . '</p>',
      ];
    }
    else {
      // Show the "X of Y items indexed" count with the controls so platform
      // admins can tell whether indexing succeeded, mirroring the read-only
      // status the per-site settings form shows site admins.
      $form['indexing']['status'] = [
        '#markup' => '<p>' . $this->indexStatusSummary($index) . '</p>',
      ];
      $form['indexing']['reindex'] = [
        '#type' => 'submit',
        '#name' => 'ys_beacon_reindex_all',
        '#value' => $this->t('Re-index all content'),
        // Reuse the site form's handler; a dedicated #submit isolates the
        // action so the shared host-form config save does not run (see the
        // static handler docblock), and empty validation lets it run without
        // touching the other platform-admin sections on the page.
        '#submit' => [[static::class, 'reindexAllSubmit']],
        '#limit_validation_errors' => [],
      ];
      $form['indexing']['index_now'] = [
        '#type' => 'submit',
        '#name' => 'ys_beacon_index_now',
        '#value' => $this->t('Index now'),
        '#submit' => [[static::class, 'indexNowSubmit']],
        '#limit_validation_errors' => [],
        // Disabled unless the index is enabled and has items waiting, mirroring
        // Search API's own "Index now" and the site settings form.
        '#disabled' => $this->indexRemainingItems($index) < 1,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSettings(array &$form, FormStateInterface $form_state): void {
    // Never allow two chat widgets at once: mirror the site settings form and
    // block enabling Beacon chat while the legacy ai_engine chat is still on.
    if ($form_state->getValue([$this->getPluginId(), 'enable_chat']) && ys_beacon_legacy_chat_active()) {
      $form_state->setErrorByName($this->getPluginId() . '][enable_chat', $this->t('The legacy AI Engine chat widget is currently enabled. Disable it before enabling Beacon chat so visitors only see one chat widget.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $authorized = (bool) $form_state->getValue([$this->getPluginId(), 'platform_authorized']);
    $enable_chat = (bool) $form_state->getValue([$this->getPluginId(), 'enable_chat']);

    $settings = $this->configFactory->getEditable(BeaconAuthorization::CONFIG_NAME);
    // Capture the stored toggle before the config is mutated so the index
    // status only changes on an actual on/off transition.
    $previous_enable = (bool) $settings->get('enable_chat');
    $settings
      ->set(BeaconAuthorization::FLAG, $authorized)
      ->set('enable_chat', $enable_chat);
    // The AI metadata fields (AI Description/Tags and the per-page index
    // exclusion) back the live chatbot, so enabling chat forces them on -
    // preserving the invariant the site settings form enforced before enabling
    // moved here. Their visibility is otherwise a platform-admin concern.
    if ($enable_chat) {
      $settings->set('enable_metadata_fields', TRUE);
    }
    $settings->save();

    // Keep the Search API index in sync with the chat toggle. Beacon is only
    // active when a platform admin has authorized the site AND chat is on (the
    // config override forces it off otherwise), so verify - and provision when
    // needed - on every save while both hold, not only the off->on transition:
    // re-saving re-checks and can recreate an index removed in Azure. If it
    // cannot be made functional, turn the widget back off so it never reports
    // enabled while broken. Verifying is skipped for de-authorized sites (which
    // the override keeps offline anyway); turning off only disables the local
    // index and makes no Azure call.
    if ($authorized && $enable_chat) {
      if (!$this->enableIndex($settings, $previous_enable)) {
        $settings->set('enable_chat', FALSE)->save();
      }
    }
    elseif ($previous_enable) {
      $this->setIndexStatus(FALSE);
    }
  }

  /**
   * Reuses the site form's re-index handler.
   *
   * Static because a plugin-contributed button cannot own an instance
   * `#submit` handler (Form API resolves `::method` against the host form
   * object, which is Beacon-agnostic). The class resolver builds a fully wired
   * YsBeaconSettings instance whose reindexAll() operates on config and
   * services - not the passed form/state - so the tracker-rebuild path and its
   * read-only / disabled guards are shared verbatim rather than reimplemented
   * here.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public static function reindexAllSubmit(array &$form, FormStateInterface $form_state): void {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::classResolver(YsBeaconSettings::class)->reindexAll($form, $form_state);
  }

  /**
   * Reuses the site form's "Index now" handler.
   *
   * See reindexAllSubmit() for why this is static and delegates through the
   * class resolver. The delegated handler runs the
   * search_api.indexing_batch_helper batch; Drupal's Form API processes the
   * queued batch and returns the user to this page.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public static function indexNowSubmit(array &$form, FormStateInterface $form_state): void {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::classResolver(YsBeaconSettings::class)->indexNow($form, $form_state);
  }

  /**
   * Ensures the Beacon index is functional while the chat toggle is on.
   *
   * Runs on every save while chat is on (not only the off->on transition), so a
   * re-save re-verifies the index and can recreate one removed in Azure. The
   * outcome is always reported, and always logged on failure:
   * - Index present: enabled; the tracker is seeded only when the index was
   *   just created or was not already enabled, so a routine save never
   *   re-queues already-indexed content.
   * - Index missing: provisioned (created and queued); or, when creation fails
   *   (for example the Azure service is at its index cap), the operator is
   *   warned and this returns FALSE so the caller turns the widget back off.
   * - Index unverifiable (Azure unreachable / auth error - inconclusive, not a
   *   definitive "missing"): the prior enabled state is kept, so a transient
   *   blip never disables a working site while a first-time enable that could
   *   not be confirmed is left off. The operator is warned to try again.
   * A read-only borrower never provisions, pins, or writes the collection it
   * reads; it just enables the local index to query it.
   *
   * @param \Drupal\Core\Config\Config $settings
   *   The editable ys_beacon.settings config.
   * @param bool $previously_enabled
   *   Whether the chat widget was already enabled before this save. Decides the
   *   inconclusive-check outcome: keep a working site on, leave a fresh enable
   *   off.
   *
   * @return bool
   *   TRUE when the widget should remain enabled; FALSE when the index could
   *   not be made functional and the caller must turn the widget back off.
   */
  protected function enableIndex(Config $settings, bool $previously_enabled): bool {
    if ($settings->get('read_only')) {
      // A borrowing site does not own the collection; it just enables the local
      // index to query the owner-managed, shared collection.
      $this->setIndexStatus(TRUE);
      return TRUE;
    }
    $name = (string) $settings->get('azure_index_name');

    // Establish whether the configured index exists. A clean "missing" (or no
    // name yet) drops through to provisioning; an error means the endpoint
    // could not be reached or authenticated, which is inconclusive.
    if ($name !== '') {
      try {
        $exists = $this->indexManager->indexExists($name);
      }
      catch (\RuntimeException $e) {
        $this->logger->error('Beacon could not verify the Azure AI Search index: @message', ['@message' => $e->getMessage()]);
        $this->messenger->addWarning($this->t('The Beacon search index could not be verified right now: @message Please try again later.', ['@message' => $e->getMessage()]));
        // Inconclusive: keep the prior state. A working site stays enabled (a
        // transient outage must not disable it); a first-time enable that could
        // not be confirmed is left off.
        return $previously_enabled;
      }
    }
    else {
      $exists = FALSE;
    }

    // Missing: create it, or report why it could not be created and leave the
    // widget off.
    $created = FALSE;
    if (!$exists) {
      try {
        $name = $this->indexManager->provision($name ?: NULL);
        $created = TRUE;
        $this->messenger->addStatus($this->t('The search index %name is ready and site content has been queued for indexing.', ['%name' => $name]));
      }
      catch (\RuntimeException $e) {
        // A capacity failure needs an ops action (a new Azure service +
        // Pantheon secret), so surface the specific reason rather than a
        // generic notice (yalesites-org/YaleSites-Internal#1440).
        $this->logger->error('Automatic index provisioning failed: @message', ['@message' => $e->getMessage()]);
        $this->messenger->addWarning($this->t('The chat widget could not be enabled because the search index could not be created: @message', ['@message' => $e->getMessage()]));
        return FALSE;
      }
    }

    // The index now exists (created or pre-existing): pin the resolved endpoint
    // so an adopted index is not moved by a later Pantheon-secret change, and
    // enable + seed the tracker only when it was created or was not already
    // enabled, so a routine re-save never re-queues indexed content
    // (rebuildTracker() re-enumerates datasources rather than only re-flagging,
    // issue #1383).
    $this->indexManager->pinSearchUrl();
    $index = $this->loadBeaconIndex();
    if ($created || !($index && $index->status())) {
      $this->setIndexStatus(TRUE);
      $this->loadBeaconIndex()?->rebuildTracker();
    }
    return TRUE;
  }

  /**
   * Persists the Beacon index enabled/disabled status override-free.
   *
   * Loads the index override-free so the runtime status/read-only overrides
   * layered on by YsBeaconConfigOverrides are never baked into the synced
   * search_api.index config.
   *
   * @param bool $status
   *   The index status to persist.
   */
  protected function setIndexStatus(bool $status): void {
    $index = $this->entityTypeManager->getStorage('search_api_index')->loadOverrideFree($this->searchIndexId());
    if ($index instanceof IndexInterface) {
      $index->setStatus($status)->save();
    }
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
   * Builds a short indexing status summary for the platform admin display.
   *
   * Mirrors the per-site settings form's read-only status (via
   * BeaconIndexingControlsTrait::indexStatusSummary()) so platform admins see
   * the same "@indexed of @total items indexed" count next to the indexing
   * controls. Kept as a parallel copy because this plugin extends
   * PlatformAdminSettingBase, not the ConfigFormBase the trait requires.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The Beacon index, or NULL when it does not exist.
   *
   * @return string
   *   The status text.
   */
  protected function indexStatusSummary(?IndexInterface $index): string {
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
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The Beacon index, or NULL when it does not exist.
   *
   * @return int
   *   The remaining item count, or 0 when the index is missing, disabled, or
   *   unavailable.
   */
  protected function indexRemainingItems(?IndexInterface $index): int {
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
   * The Search API index machine name backing the chatbot.
   *
   * @return string
   *   The index machine name.
   */
  protected function searchIndexId(): string {
    return $this->configFactory->get(BeaconAuthorization::CONFIG_NAME)->get('search_index_id') ?: 'ys_beacon';
  }

}
