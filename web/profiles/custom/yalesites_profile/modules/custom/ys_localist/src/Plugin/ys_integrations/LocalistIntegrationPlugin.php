<?php

namespace Drupal\ys_localist\Plugin\ys_integrations;

use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
* Provides a localist integration plugin.
*/
#[Integration(
  id: 'ys_localist',
  label: new TranslatableMarkup('Localist'),
  description: new TranslatableMarkup('Provides integration with the Localist API.'),
)]
class LocalistIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_localist.settings');
    return $config->get('enable_localist_sync') ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_localist.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return Url::fromRoute('ys_localist.run_migrations');
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

    $syncUrl = $this->syncUrl();
    $syncUrlAccess = $syncUrl->access($this->currentUser);

    $form['#actions']['configure'] = [
      '#type' => 'link',
      '#title' => $this->t('Configure'),
      '#url' => $configUrl,
      '#access' => $configUrlAccess,
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    if ($this->isTurnedOn()) {
      $form['#actions']['sync'] = [
        '#type' => 'link',
        '#title' => $this->t('Sync now'),
        '#url' => $syncUrl,
        '#access' => $syncUrlAccess,
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

  /**
   * {@inheritdoc}
   */
  public function save($form, $form_state): void {
  }

}
