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
   * The category whitelist used when a site has never saved this section.
   */
  const DEFAULT_CATEGORIES = ['Feature release', 'News', 'Important update'];

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

    $form['announcements_source_categories'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Announcement categories'),
      '#rows' => 3,
      '#default_value' => implode("\n", self::resolveCategoryWhitelist($config->get('announcements_source_categories'))),
      '#description' => $this->t("One category per line. Only posts tagged (via the Category field) with one of these will have that category exposed in the feed for downstream dashboards to display as a label. Names must match this site's Post Categories vocabulary terms (case-insensitive). Leave blank to publish no categories."),
      '#states' => [
        'visible' => [
          ':input[name="announcements_source[announcements_source_enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Resolves the stored category whitelist to what should actually be used.
   *
   * A `NULL` stored value means the section has never been saved - the normal
   * state on every existing site, since config_ignore keeps
   * ys_core.dashboard_settings from being created/merged by config:import -
   * and resolves to the platform defaults. An explicitly stored value
   * (including an empty array) is a deliberate choice and is trimmed/filtered
   * but otherwise returned as-is: an admin who cleared this field to publish
   * no categories must have that persist as "none", not silently revert to
   * the defaults.
   *
   * Public and static so AnnouncementsFeedController can resolve the same
   * whitelist the settings form displays, without re-implementing this logic.
   *
   * @param mixed $stored
   *   The raw `announcements_source_categories` config value.
   *
   * @return string[]
   *   The category names to treat as whitelisted, trimmed and non-empty.
   */
  public static function resolveCategoryWhitelist($stored): array {
    if ($stored === NULL) {
      return self::DEFAULT_CATEGORIES;
    }
    if (!is_array($stored)) {
      return [];
    }
    return self::trimNonEmpty($stored);
  }

  /**
   * Parses the categories textarea into a normalized array of names.
   *
   * @param mixed $value
   *   The raw submitted textarea value.
   *
   * @return string[]
   *   One entry per non-blank line, trimmed.
   */
  private function parseCategoriesInput($value): array {
    return self::trimNonEmpty(preg_split('/\r\n|\r|\n/', (string) $value) ?: []);
  }

  /**
   * Trims each value and drops any that are blank afterward.
   *
   * Shared by resolveCategoryWhitelist() (normalizing a stored config value)
   * and parseCategoriesInput() (normalizing textarea lines) - both need the
   * same "trim, then drop empties" cleanup, just on different raw input.
   *
   * @param array $values
   *   Raw values to normalize.
   *
   * @return string[]
   *   The trimmed, non-empty values, reindexed.
   */
  private static function trimNonEmpty(array $values): array {
    return array_values(array_filter(
      array_map(fn($value) => trim((string) $value), $values),
      fn($value) => $value !== '',
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $enabled = (bool) $form_state->getValue([$this->getPluginId(), 'announcements_source_enabled']);
    $term = $this->normalizeTerm($form_state->getValue([$this->getPluginId(), 'announcements_source_term']));
    $categories = $this->parseCategoriesInput(
      $form_state->getValue([$this->getPluginId(), 'announcements_source_categories']),
    );

    $config = $this->configFactory->getEditable(self::CONFIG_NAME);

    // The page has one Save button for every section, so this runs even when
    // nobody touched the source fields. Enabled/term both go through
    // normalizeTerm() because the stored and submitted values hold the same
    // setting in different shapes: config_ignore keeps
    // ys_core.dashboard_settings out of config:import, so on a site that has
    // never saved this section the key reads NULL while the form submits back
    // the default it just displayed. Comparing those raw counted an untouched
    // save as a change. Categories uses resolveCategoryWhitelist() for the
    // stored side for the same reason, but the submitted side is used as-is
    // (never re-defaulted) - an admin who deliberately clears the field to
    // publish no categories must have that persist as `[]`, not compare equal
    // to the shown default and silently fail to save.
    $categories_unchanged = self::resolveCategoryWhitelist($config->get('announcements_source_categories')) === $categories;
    $unchanged = (bool) $config->get('announcements_source_enabled') === $enabled
      && $this->normalizeTerm($config->get('announcements_source_term')) === $term
      && $categories_unchanged;

    // Deliberately ahead of the short-circuit below, and re-run on every save
    // while publishing is on: the tag is the one thing here that something
    // else can delete out from under the config. Re-running is how a deleted
    // tag gets restored - it was self-healing on the old form only because
    // that form re-ran this on every platform-admin save.
    //
    // Its warnings, though, are reported only when this section actually
    // changed. The self-heal has to run on every save; telling an admin who
    // edited only another section about this section's tag on every save does
    // not, and there is nothing on screen for them to act on.
    if ($enabled) {
      $this->ensureAnnouncementTerm($term, !$unchanged);
    }

    // Unlike the tag, a whitelist entry that matches no Post Categories term
    // cannot be self-healed - creating a taxonomy term is a content decision,
    // not an infrastructure one - so this only warns. Gated on the categories
    // themselves having changed, same reasoning as the tag warnings above: an
    // admin saving an unrelated section should not be renagged about a
    // pre-existing mismatch on every save.
    if ($enabled && !$categories_unchanged) {
      $this->warnAboutUnmatchedCategories($categories);
    }

    // Skip the write - and the cached-feed drop that follows it - rather than
    // rewriting the same values on every unrelated save.
    if ($unchanged) {
      return;
    }

    $config
      ->set('announcements_source_enabled', $enabled)
      ->set('announcements_source_term', $term)
      ->set('announcements_source_categories', $categories)
      ->save();

    // Drop the cached feed so the new settings take effect immediately,
    // matching what DashboardSettingsForm did while it owned these fields.
    $this->announcements->clearCache();
  }

  /**
   * Normalizes a tag name to the value the form displays for it.
   *
   * A cleared or absent tag would otherwise leave AnnouncementsFeedController
   * falling back to a default name that nothing guarantees exists - a 200 with
   * zero items and no warning. Applied to the submitted value before it is
   * stored, and to the stored value before it is compared, so the no-change
   * guard sees both sides in the same shape as buildSettings() renders them.
   *
   * @param mixed $value
   *   A stored or submitted tag name.
   *
   * @return string
   *   The trimmed name, or the platform default when it is empty.
   */
  private function normalizeTerm($value): string {
    return trim((string) $value) ?: self::DEFAULT_TERM;
  }

  /**
   * Ensures a term with the given name exists in the Tags vocabulary.
   *
   * @param string $name
   *   The tag name to look for, and create when it is missing.
   * @param bool $report_problems
   *   Whether to warn about a taxonomy state this method cannot fix - a
   *   missing vocabulary, or an existing but unpublished term. Passed FALSE on
   *   a save that left this section alone, so the warnings do not re-fire on
   *   every save of an unrelated section. The creation message is not gated
   *   this way: it reports something that actually happened.
   */
  protected function ensureAnnouncementTerm(string $name, bool $report_problems = TRUE): void {
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load('tags')) {
      if ($report_problems) {
        $this->messenger()->addWarning($this->t('The "Tags" vocabulary does not exist on this site, so the announcement tag could not be created automatically.'));
      }
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
      if ($report_problems && !array_filter($existing, fn($term) => $term->isPublished())) {
        $this->messenger()->addWarning($this->t('The %name tag exists but is unpublished, so the feed will return no items until it is published.', ['%name' => $name]));
      }
      return;
    }

    $term_storage->create(['vid' => 'tags', 'name' => $name])->save();
    $this->messenger()->addStatus($this->t('Created the %name tag in the Tags vocabulary. Apply it to posts you want to surface on editorial dashboards.', ['%name' => $name]));
  }

  /**
   * Warns about whitelist entries that match no Post Categories term.
   *
   * A typo or wording drift here has no visible symptom otherwise: the post
   * still publishes, the feed still returns 200, and the category simply
   * never appears as a pill anywhere - identical to "correctly configured, no
   * post uses that category." This is the only place that distinction
   * becomes visible.
   *
   * @param string[] $categories
   *   The whitelist about to be saved.
   */
  protected function warnAboutUnmatchedCategories(array $categories): void {
    if (!$categories) {
      return;
    }

    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocab_storage->load('post_category')) {
      $this->messenger()->addWarning($this->t('The "Post Categories" vocabulary does not exist on this site, so the configured categories could not be checked against it.'));
      return;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing_names = array_map(
      fn($term) => mb_strtolower(trim((string) $term->getName())),
      $term_storage->loadByProperties(['vid' => 'post_category']),
    );

    $unmatched = array_values(array_filter(
      $categories,
      fn($name) => !in_array(mb_strtolower($name), $existing_names, TRUE),
    ));

    if ($unmatched) {
      $this->messenger()->addWarning($this->t('These configured categories do not match any existing Post Categories term and will not appear on any post until a matching term exists: %names.', ['%names' => implode(', ', $unmatched)]));
    }
  }

}
