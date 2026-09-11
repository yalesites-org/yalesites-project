<?php

namespace Drupal\ys_integrations_test\Plugin\ys_integrations;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;

/**
 * Provides a test integration plugin that renders a card.
 *
 * The sibling TestIntegrationPlugin inherits the base build(), which returns
 * an empty array; this one returns content, so the overview controller can be
 * tested against both an integration that has something to show and one that
 * has not.
 */
#[Integration(
  id: 'ys_integrations_test_built',
  label: new TranslatableMarkup('Built Test Integration'),
  description: new TranslatableMarkup('A test integration that renders a card.'),
)]
class BuiltIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['title' => $this->pluginDefinition['label']];
  }

}
