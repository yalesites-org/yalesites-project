<?php

namespace Drupal\ys_beacon\Plugin\ys_integrations;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;

/**
 * Provides a Beacon AI chat integration plugin.
 */
#[Integration(
  id: 'ys_beacon',
  label: new TranslatableMarkup('Beacon (AI Chat)'),
  description: new TranslatableMarkup('Provides an AI chat assistant answering questions from site content.'),
)]
class BeaconIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_beacon.settings');
    return $config->get('enable_chat') || !empty($config->get('azure_index_name'));
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_beacon.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $form = [];

    $form['title'] = $this->pluginDefinition['label'];
    $form['description'] = $this->pluginDefinition['description'];

    $configUrl = $this->configUrl();
    $configUrlAccess = $configUrl->access($this->currentUser);

    if ($this->isTurnedOn()) {
      $form['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => $this->t('Configure'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      $form['#actions']['not_configured'] = [
        '#markup' => '<p>' . $this->t('This integration is not configured.') . '</p>',
      ];
    }

    return $form;
  }

}
