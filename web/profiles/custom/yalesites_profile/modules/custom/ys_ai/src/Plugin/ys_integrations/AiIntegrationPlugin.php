<?php

namespace Drupal\ys_ai\Plugin\ys_integrations;

use Drupal\ys_integrations\IntegrationPluginBase;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
* Provides an AI integration plugin.
*/
#[Integration(
  id: 'ys_ai',
  label: new TranslatableMarkup('AI'),
  description: new TranslatableMarkup('Provides integration with the AI engine.'),
)]
class AiIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ai_engine_chat.settings');
    return $config->get('azure_base_url') != NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_ai.settings');
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

  /**
   * {@inheritdoc}
   */
  public function save($form, $form_state): void {
  }

}
