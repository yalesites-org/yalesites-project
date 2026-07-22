<?php

namespace Drupal\ys_integrations_test\Plugin\ys_integrations;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\ys_integrations\IntegrationPluginBase;

/**
 * Provides a test integration plugin for kernel tests.
 *
 * Inherits every default from IntegrationPluginBase so tests can exercise
 * discovery, instantiation and the settings form against a known plugin.
 */
#[Integration(
  id: 'ys_integrations_test',
  label: new TranslatableMarkup('Test Integration'),
  description: new TranslatableMarkup('A test integration used by kernel tests.'),
)]
class TestIntegrationPlugin extends IntegrationPluginBase {

}
