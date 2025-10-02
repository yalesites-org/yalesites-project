<?php

/**
 * @file
 * Load services definition file.
 */

$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Include the Pantheon-specific settings file.
 *
 * N.b. The settings.pantheon.php file makes some changes
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
 * Https://www.drupal.org/project/drupal/issues/3091285
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

// Exclude modules from config sync.
$settings['config_exclude_modules'] = ['redis'];

/**
 * Include the Redis settings file.
 */
if (!empty($_ENV['PANTHEON_ENVIRONMENT']) && !empty($_ENV['CACHE_HOST'])) {
  $redis_settings = __DIR__ . "/settings.redis.php";

  if (file_exists($redis_settings)) {
    include $redis_settings;
  }
}

/**
 * Environment Indicator.
 */
$env = $_ENV['PANTHEON_ENVIRONMENT'] ?? 'lando';

// Get version from profile info file.
$profile_info_file = DRUPAL_ROOT . '/profiles/custom/yalesites_profile/yalesites_profile.info.yml';
$version = '';
if (file_exists($profile_info_file)) {
  $profile_info = file_get_contents($profile_info_file);
  if (preg_match('/^version:\s*(.+)$/m', $profile_info, $matches)) {
    $version = trim($matches[1]);
  }
}

$env_options = [
  'lando' => [
    'bg_color' => '#94bdff',
    'fg_color' => '#000000',
    'name' => 'Local Environment',
  ],
  'development' => [
    'bg_color' => '#3b82f6',
    'fg_color' => '#ffffff',
    'name' => 'Development' . ($version ? ' - ' . $version : ''),
  ],
  'test' => [
    'bg_color' => '#8b5cf6',
    'fg_color' => '#ffffff',
    'name' => 'YaleSites Team Testing Only.' . ($version ? ' - ' . $version : ''),
  ],
  'live' => [
    'bg_color' => '#22c55e',
    'fg_color' => '#000000',
    'name' => 'Live Site' . ($version ? ' - ' . $version : ''),
  ],
  'multidev' => [
    'bg_color' => '#e1821f',
    'fg_color' => '#000000',
    'name' => 'Multidev - ' . $env,
  ],
];
$env_key = isset($env_options[$env]) ? $env : 'multidev';
$config['environment_indicator.indicator'] = $env_options[$env_key];
if ($env_key === 'lando') {
  $git_head = file(DRUPAL_ROOT . '/../.git/HEAD');
  $ref = explode('/', $git_head[0]);
  $branch_parts = array_slice($ref, 2);
  $branch_name = implode('/', $branch_parts);
  $config['environment_indicator.indicator']['name'] .= " - $branch_name";
}
