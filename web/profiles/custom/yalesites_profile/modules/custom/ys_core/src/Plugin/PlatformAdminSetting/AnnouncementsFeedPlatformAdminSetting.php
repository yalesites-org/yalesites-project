<?php

namespace Drupal\ys_core\Plugin\PlatformAdminSetting;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\PlatformAdminSettingBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform admin control for which feed the dashboard consumes.
 *
 * Exposes `ys_core.dashboard_settings:announcements_feed_url` - previously
 * settable only via drush - so a platform admin can point this site's
 * editorial dashboard at a specific feed (another RC/test site's own
 * `/api/dashboard-announcements` endpoint, or its own) instead of the
 * production default. Without this, RC testing could never verify the
 * announcements feature end to end: the dashboard always consumed
 * production's feed regardless of what a test site published
 * (yalesites-org/YaleSites-Internal#1487).
 *
 * The field always displays the real effective URL (the stored override, or
 * the production default when none is set) so a platform admin can see at a
 * glance what the dashboard is currently pulling from. Submitting that shown
 * default back unchanged is normalized to blank rather than pinned into
 * config, so a routine save of this page never converts "no override" into
 * an explicit one.
 */
#[PlatformAdminSetting(
  id: 'announcements_feed',
  label: new TranslatableMarkup('Dashboard Announcements Feed'),
)]
class AnnouncementsFeedPlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * The dashboard announcements service.
   *
   * @var \Drupal\ys_core\DashboardAnnouncements
   */
  protected DashboardAnnouncements $announcements;

  /**
   * Constructs an AnnouncementsFeedPlatformAdminSetting object.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    DashboardAnnouncements $announcements,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $config_factory, $current_user);
    $this->announcements = $announcements;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    $form['announcements_feed_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Feed source URL'),
      '#default_value' => $this->announcements->getEffectiveFeedUrl(),
      '#description' => $this->t("This is a testing/override control, not something most sites should touch. It lets a platform admin point this site's editorial dashboard at a specific announcements feed - for example another RC/test site's own <code>/api/dashboard-announcements</code> endpoint - so the full announcement loop can be verified without drush access. Leave it set to the URL shown above (the production feed) unless deliberately testing against a different source; clearing the field also falls back to the production feed."),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $value = trim((string) $form_state->getValue([$this->getPluginId(), 'announcements_feed_url']));
    // A resubmission of the displayed production default is not a
    // deliberate override, so it is stored as blank (the same effective
    // value) rather than pinned into config.
    if ($value === DashboardAnnouncements::PLATFORM_FEED_URL) {
      $value = '';
    }

    $config = $this->configFactory->getEditable('ys_core.dashboard_settings');
    if ((string) $config->get('announcements_feed_url') === $value) {
      // No effective change: skip the write and the cache-clearing HTTP
      // refetch it would otherwise force on the next toolbar badge render.
      return;
    }

    $config->set('announcements_feed_url', $value)->save();

    // Drop the cached feed so a changed source takes effect immediately,
    // consistent with DashboardSettingsForm's save behavior.
    $this->announcements->clearCache();
  }

}
