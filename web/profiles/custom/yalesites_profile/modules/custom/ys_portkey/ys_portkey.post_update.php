<?php

/**
 * @file
 * Post update hooks for the ys_portkey module.
 */

/**
 * Migrate single Portkey config to multi-instance model entries.
 */
function ys_portkey_post_update_migrate_to_multi_instance(&$sandbox) {
  $config_factory = \Drupal::configFactory();
  $old_config = $config_factory->getEditable('ys_portkey.settings');
  $model = $old_config->get('model');

  if (!empty($model)) {
    $ai_settings = $config_factory->getEditable('ai.settings');
    $models = $ai_settings->get('models') ?? [];

    // Model IDs must be alphanumeric, hyphens, or underscores only.
    $safe_model_id = preg_replace('/[^a-zA-Z0-9_-]/', '-', $model);

    $model_data = [
      'model_id' => $safe_model_id,
      'label' => $model,
      'api_key' => $old_config->get('api_key') ?: '',
      'gateway_url' => $old_config->get('gateway_url') ?: 'https://api.portkey.ai/v1',
      'custom_headers' => $old_config->get('custom_headers') ?: '',
      'provider' => 'portkey',
    ];

    $models['portkey']['chat'][$safe_model_id] = $model_data + [
      'operation_type' => 'chat',
    ];

    $models['portkey']['embeddings'][$safe_model_id] = $model_data + [
      'operation_type' => 'embeddings',
    ];

    $ai_settings->set('models', $models)->save();

    \Drupal::logger('ys_portkey')->notice(
      'Migrated Portkey config to multi-instance model entries for model "@model".',
      ['@model' => $model]
    );
  }

  // Reset to minimal config.
  $old_config
    ->clear('api_key')
    ->clear('gateway_url')
    ->clear('model')
    ->clear('custom_headers')
    ->set('data', '')
    ->save();
}
