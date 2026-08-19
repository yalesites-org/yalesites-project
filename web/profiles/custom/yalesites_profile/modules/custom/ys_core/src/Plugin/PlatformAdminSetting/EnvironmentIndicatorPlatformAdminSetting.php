<?php

namespace Drupal\ys_core\Plugin\PlatformAdminSetting;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\PlatformAdminSettingBase;

/**
 * Platform admin control for the environment indicator banner.
 *
 * Whether the dev/test/live banner shows is platform infrastructure rather than
 * site content, so this toggle used to be added to Site settings only for
 * platform admins - with the same hide-the-field-and-guard-the-write pattern
 * the Site Name Image had (yalesites-org/YaleSites-Internal#1560). It now lives
 * here, where the route permission does the gating.
 *
 * The config key is unchanged, so an existing per-site value keeps working with
 * no update hook.
 */
#[PlatformAdminSetting(
  id: 'environment_indicator',
  label: new TranslatableMarkup('Environment Indicator'),
  weight: -10,
)]
class EnvironmentIndicatorPlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * The config object this setting has always been stored in.
   */
  const CONFIG_NAME = 'ys_core.site';

  /**
   * The config key this setting has always been stored under.
   */
  const CONFIG_KEY = 'environment_indicator.show';

  /**
   * The value the form shows, and ys_core.module renders, when config is unset.
   *
   * A site that never saved this still gets the banner, so an unwritten key and
   * a stored TRUE behave identically - which is what lets an untouched save
   * skip the write entirely. ys_core_preprocess_html() and ys_core_page_top()
   * read through this same constant so the render path cannot drift from it.
   */
  const DISPLAY_DEFAULT = TRUE;

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    $form['environment_indicator_show'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show environment indicator'),
      '#description' => $this->t('Display the environment indicator banner at the top of the site. This setting overrides all environment-specific configurations.'),
      '#default_value' => $this->configFactory->get(self::CONFIG_NAME)->get(self::CONFIG_KEY) ?? self::DISPLAY_DEFAULT,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    $submitted = (bool) $form_state->getValue([$this->getPluginId(), 'environment_indicator_show']);

    // The page has one Save button for every section, so this runs even when
    // nobody touched this checkbox. Skip the write - and the
    // config:ys_core.site cache tag invalidation it drags along - rather than
    // rewriting the same value on every unrelated save. The key may be absent,
    // and SiteSettingsForm stored the raw checkbox value, so sites that saved
    // this before the move hold integer 1/0 rather than a boolean - hence
    // normalizing the stored side rather than comparing it raw.
    if ((bool) ($config->get(self::CONFIG_KEY) ?? self::DISPLAY_DEFAULT) === $submitted) {
      return;
    }

    $config->set(self::CONFIG_KEY, $submitted)->save();
  }

}
