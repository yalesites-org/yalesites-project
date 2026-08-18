<?php

namespace Drupal\ys_core\Plugin\PlatformAdminSetting;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\PlatformAdminSettingBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform admin control for whether this site publishes an announcements feed.
 *
 * Deciding which site publishes `/api/dashboard-announcements` is a platform
 * call, so this section used to sit on Dashboard settings behind an `#access`
 * check with a matching guard in the submit handler - the same
 * hide-the-field-and-guard-the-write pair the Site Name Image had
 * (yalesites-org/YaleSites-Internal#1560). It now lives here, where the route
 * permission does the gating, so Dashboard settings is the same form for
 * everyone who can reach it and the guard is deleted rather than relocated.
 *
 * It sits directly after the "Dashboard Announcements Feed" section, which
 * already owned the consume-side override, so every announcements control a
 * platform admin owns is on one page instead of split across two.
 *
 * The config keys are unchanged - they still live in
 * ys_core.dashboard_settings, which is what
 * \Drupal\ys_core\Controller\AnnouncementsFeedController reads - so existing
 * values keep working with no update hook or data migration.
 */
#[PlatformAdminSetting(
  id: 'announcements_source',
  label: new TranslatableMarkup('Announcements Source'),
  weight: 20,
)]
class AnnouncementsSourcePlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * The config object these settings have always been stored in.
   */
  const CONFIG_NAME = 'ys_core.dashboard_settings';

  /**
   * The tag name used when the site has not chosen one.
   */
  const DEFAULT_TERM = 'Dashboard Announcement';

  /**
   * The dashboard announcements service.
   *
   * @var \Drupal\ys_core\DashboardAnnouncements
   */
  protected DashboardAnnouncements $announcements;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an AnnouncementsSourcePlatformAdminSetting object.
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
   * @param \Drupal\ys_core\DashboardAnnouncements $announcements
   *   The dashboard announcements service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    DashboardAnnouncements $announcements,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $current_user);
    $this->announcements = $announcements;
    $this->entityTypeManager = $entity_type_manager;
    // PluginBase already carries $messenger through MessengerTrait, so this
    // goes through the trait's setter rather than redeclaring the property.
    $this->setMessenger($messenger);
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
      $container->get('ys_core.dashboard_announcements'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);

    $form['#description'] = $this->t('Most sites leave this off and only <em>consume</em> the feed configured above. The platform site (yalesites.yale.edu) turns this on to <em>publish</em> the feed at <code>/api/dashboard-announcements</code> from its tagged posts.');

    $form['announcements_source_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish an announcements feed from this site'),
      '#default_value' => $config->get('announcements_source_enabled'),
      '#description' => $this->t('When enabled, published posts tagged with the term below are exposed as a JSON feed at <code>/api/dashboard-announcements</code>.'),
    ];

    $form['announcements_source_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Announcement tag'),
      '#default_value' => $config->get('announcements_source_term') ?: self::DEFAULT_TERM,
      // Taxonomy term names are varchar(255); without the cap an over-long
      // value throws on term save instead of failing validation.
      '#maxlength' => 255,
      '#description' => $this->t('The name of the tag (in the Tags vocabulary) used to mark posts as announcements.'),
      '#states' => [
        'visible' => [
          ':input[name="announcements_source[announcements_source_enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $enabled = (bool) $form_state->getValue([$this->getPluginId(), 'announcements_source_enabled']);
    // A cleared field would otherwise store an empty tag and leave
    // AnnouncementsFeedController falling back to a default name that nothing
    // guarantees exists - a 200 with zero items and no warning. Normalizing
    // here matches the fallback buildSettings() and the controller already
    // apply.
    $term = trim((string) $form_state->getValue([$this->getPluginId(), 'announcements_source_term'])) ?: self::DEFAULT_TERM;

    // Deliberately ahead of the short-circuit below, and re-run on every save
    // while publishing is on: the tag is the one thing here that something
    // else can delete out from under the config. Re-running is how a deleted
    // tag gets restored - it was self-healing on the old form only because
    // that form re-ran this on every platform-admin save.
    if ($enabled) {
      $this->ensureAnnouncementTerm($term);
    }

    $config = $this->configFactory->getEditable(self::CONFIG_NAME);

    // The page has one Save button for every section, so this runs even when
    // nobody touched the source fields. Skip the write - and the cached-feed
    // drop that follows it - rather than rewriting the same values on every
    // unrelated save.
    if ((bool) $config->get('announcements_source_enabled') === $enabled
      && (string) $config->get('announcements_source_term') === $term) {
      return;
    }

    $config
      ->set('announcements_source_enabled', $enabled)
      ->set('announcements_source_term', $term)
      ->save();

    // Drop the cached feed so the new settings take effect immediately,
    // matching what DashboardSettingsForm did while it owned these fields.
    $this->announcements->clearCache();
  }

  /**
   * Ensures a term with the given name exists in the Tags vocabulary.
   *
   * @param string $name
   *   The tag name to look for, and create when it is missing.
   */
  protected function ensureAnnouncementTerm(string $name): void {
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load('tags')) {
      $this->messenger()->addWarning($this->t('The "Tags" vocabulary does not exist on this site, so the announcement tag could not be created automatically.'));
      return;
    }

    // Deliberately unfiltered by access: an access-checked lookup that could
    // not see an existing term would create a duplicate. The consumer query in
    // AnnouncementsFeedController does check access, though, so an existing
    // but unpublished term is found here and skipped there - which reads to an
    // admin as a working feed returning nothing. Say so rather than staying
    // silent.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $term_storage->loadByProperties(['vid' => 'tags', 'name' => $name]);
    if ($existing) {
      if (!array_filter($existing, fn($term) => $term->isPublished())) {
        $this->messenger()->addWarning($this->t('The %name tag exists but is unpublished, so the feed will return no items until it is published.', ['%name' => $name]));
      }
      return;
    }

    $term_storage->create(['vid' => 'tags', 'name' => $name])->save();
    $this->messenger()->addStatus($this->t('Created the %name tag in the Tags vocabulary. Apply it to posts you want to surface on editorial dashboards.', ['%name' => $name]));
  }

}
