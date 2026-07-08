<?php

namespace Drupal\ys_beacon\Plugin\PlatformAdminSetting;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_core\Attribute\PlatformAdminSetting;
use Drupal\ys_core\PlatformAdminSettingBase;

/**
 * Platform admin toggle authorizing Beacon for the site.
 *
 * Contributes the per-site Beacon authorization flag to the Platform Admin
 * Settings page. Only platform admins reach that page, so this is where Beacon
 * is switched on for a site; site admins then configure it as usual.
 */
#[PlatformAdminSetting(
  id: 'ys_beacon',
  label: new TranslatableMarkup('Beacon (AI Chat)'),
  weight: 0,
)]
class BeaconPlatformAdminSetting extends PlatformAdminSettingBase {

  /**
   * {@inheritdoc}
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array {
    $authorized = (bool) $this->configFactory
      ->get(BeaconAuthorization::CONFIG_NAME)
      ->get(BeaconAuthorization::FLAG);

    $form['platform_authorized'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow site admins to configure and use Beacon'),
      '#description' => $this->t('When enabled, site administrators can turn on and configure the Beacon AI chat for this site. When disabled, all Beacon features are hidden and inactive for this site.'),
      '#default_value' => $authorized,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void {
    $value = (bool) $form_state->getValue([$this->getPluginId(), 'platform_authorized']);
    $this->configFactory
      ->getEditable(BeaconAuthorization::CONFIG_NAME)
      ->set(BeaconAuthorization::FLAG, $value)
      ->save();
  }

}
