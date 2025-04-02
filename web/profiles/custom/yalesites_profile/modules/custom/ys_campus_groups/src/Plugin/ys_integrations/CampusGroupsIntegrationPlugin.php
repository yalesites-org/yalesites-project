<?php

namespace Drupal\ys_campus_groups\Plugin\ys_integrations;

use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
* Provides a campus groups integration plugin.
*/
#[Integration(
  id: 'ys_campus_groups',
  label: new TranslatableMarkup('Campus Groups'),
  description: new TranslatableMarkup('Provides integration with the Campus Groups API.'),
)]
class CampusGroupsIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_campus_groups.settings');
    return $config->get('enable_campus_groups_sync') ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_campus_groups.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return Url::fromRoute('ys_campus_groups.run_migrations');
  }

  /**
   *
   */
  public function build() {
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
      '#options' => [
        'attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
    ];
    if ($this->isTurnedOn()) {
      $form['#actions']['sync'] = [
        '#type' => 'link',
        '#title' => $this->t('Sync now'),
        '#url' => $syncUrl,
        '#access' => $syncUrlAccess,
        '#options' => [
          'attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
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
