<?php

namespace Drupal\ys_ai_tester\Plugin\ys_integrations;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;

/**
 * Provides an AI Tester integration plugin.
 */
#[Integration(
  id: 'ys_ai_tester',
  label: new TranslatableMarkup('AI Tester'),
  description: new TranslatableMarkup('Batch test the Beacon assistant against a YAML fixture of prompts.'),
)]
class AiTesterIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    // The tester runs questions through Beacon, which needs an index; mirror
    // the Beacon integration's "configured" signal.
    $config = $this->configFactory->get('ys_beacon.settings');
    return $config->get('enable_chat') || !empty($config->get('azure_index_name'));
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_ai_tester.tester');
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
        '#title' => $this->t('Open AI Tester'),
        '#url' => $configUrl,
        '#access' => $configUrlAccess,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      $form['#actions']['not_configured'] = [
        '#markup' => '<p>' . $this->t('Configure the Beacon AI chat before using the tester.') . '</p>',
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
