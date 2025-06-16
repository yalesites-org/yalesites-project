<?php

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Include the Pantheon-specific settings file.
 *
 * n.b. The settings.pantheon.php file makes some changes
 *      that affect all environments that this site
 *      exists in.  Always include this file, even in
 *      a local development environment, to ensure that
 *      the site settings remain consistent.
 */
include __DIR__ . "/settings.pantheon.php";

/**
 * Skipping permissions hardening will make scaffolding
 * work better, but will also raise a warning when you
 * install Drupal.
 *
 * https://www.drupal.org/project/drupal/issues/3091285
 */
// $settings['skip_permissions_hardening'] = TRUE;
// Config split for production environments.
$config['config_split.config_split.local_config']['status'] = FALSE;
$config['config_split.config_split.production_config']['status'] = TRUE;

/**
 * If there is a local settings file, then include it.
 */
$local_settings = __DIR__ . "/settings.local.php";
if (file_exists($local_settings)) {
  include $local_settings;
}

/**
 * If there is a site-specific settings file, then include it.
 */
if (isset($_ENV['PANTHEON_SITE_NAME'])) {
  $site_settings = __DIR__ . "/settings." . $_ENV['PANTHEON_SITE_NAME'] . ".php";

  if (file_exists($site_settings)) {
    include $site_settings;
  }
}

// Set the install profile as the source of site config.
$settings['config_sync_directory'] = 'profiles/custom/yalesites_profile/config/sync';

/**
 * Environment Indicator.
 */
$env = $_ENV['PANTHEON_ENVIRONMENT'] ?? 'lando';
$env_options = [
  'lando' => [
    'bg_color' => '#000000',
    'fg_color' => '#ffffff',
    'name' => 'Local',
  ],
  'dev' => [
    'bg_color' => '#ff0906',
    'fg_color' => '#ffffff',
    'name' => 'Dev',
  ],
  'test' => [
    'bg_color' => '#ffe770',
    'fg_color' => '#000000',
    'name' => 'Test',
  ],
  'multidev' => [
    'bg_color' => '#e1821f',
    'fg_color' => '#000000',
    'name' => 'Multidev:' . $env,
  ],
];
if ($env !== 'live') {
  $env_key = isset($env_options[$env]) ? $env : 'multidev';
  $config['environment_indicator.indicator'] = $env_options[$env_key];
  if ($env_key === 'lando') {
    $git_head = file(DRUPAL_ROOT . '/../.git/HEAD');
    $ref = explode('/', $git_head[0]);
    $branch_parts = array_slice($ref, 2);
    $branch_name = implode('/', $branch_parts);
    $config['environment_indicator.indicator']['name'] .= ":$branch_name";
  }
}
