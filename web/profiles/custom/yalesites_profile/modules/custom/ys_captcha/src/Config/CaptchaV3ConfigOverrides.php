<?php

namespace Drupal\ys_captcha\Config;

use Drupal\ys_secrets\Config\SecretsConfigOverridesBase;

/**
 * Configuration overrides for reCAPTCHA v3 module.
 *
 * Override the API keys with the value defined in the secrets.json file. This
 * approach keeps the secret value out of the the repo. Overrides do not displa
 * in config forms but can be verified by running:
 * `drush config-get recaptcha_v3.settings --include-overridden`.
 */
final class CaptchaV3ConfigOverrides extends SecretsConfigOverridesBase {

  /**
   * {@inheritdoc}
   */
  protected $configGroup = 'recaptcha_v3.settings';

  /**
   * {@inheritdoc}
   */
  protected $mapping = [
    'site_key' => 'recaptcha_v3_key',
    'secret_key' => 'recaptcha_v3_secret',
  ];

}
