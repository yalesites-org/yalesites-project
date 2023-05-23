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

/**
 * Override config with values from the secrets file.
 */
$secrets_json_text = file_get_contents('/files/private/secrets.json');
$secrets_data = json_decode($secrets_json_text, TRUE);
$config['recaptcha.settings']['site_key'] = $secrets_data['recaptcha_v2_key'];
$config['recaptcha.settings']['secret_key'] = $secrets_data['recaptcha_v2_secret'];
$config['recaptcha_v3.settings']['site_key'] = $secrets_data['recaptcha_v3_key'];
$config['recaptcha_v3.settings']['secret_key'] = $secrets_data['recaptcha_v3_secret'];
$config['mailchimp_transactional.settings']['mailchimp_transactional_api_key'] = $secrets_data['mailchimp_transactional_api_key'];

/**
 * If there is a local settings file, then include it
 */
$local_settings = __DIR__ . "/settings.local.php";
if (file_exists($local_settings)) {
  include $local_settings;
}

// Set the install profile as the source of site config.
$settings['config_sync_directory'] = 'profiles/custom/yalesites_profile/config/sync';

