<?php

namespace Drupal\ys_contoso_chat\Plugin\ys_integrations;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;

/**
 * Provides a Yale Chat integration plugin.
 */
#[Integration(
  id: 'ys_contoso_chat',
  label: new TranslatableMarkup('Yale Chat'),
  description: new TranslatableMarkup('A Yale-branded AI chat widget backed by the Drupal AI module and RAG search.'),
)]
class ContosoChatIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_contoso_chat.settings');
    $assistant_id = $config->get('assistant_id');
    return (bool) $config->get('enable')
      && $assistant_id !== NULL
      && $assistant_id !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_contoso_chat.settings');
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

    $form['#actions']['configure'] = [
      '#type' => 'link',
      '#title' => $this->t('Configure'),
      '#url' => $configUrl,
      '#access' => $configUrlAccess,
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    if (!$this->isTurnedOn()) {
      $form['#actions']['not_configured'] = [
        '#markup' => '<p>' . $this->t('This integration is not enabled.') . '</p>',
      ];
    }

    return $form;
  }

}
