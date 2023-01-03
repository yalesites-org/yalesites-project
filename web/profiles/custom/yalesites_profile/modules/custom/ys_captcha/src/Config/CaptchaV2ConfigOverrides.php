<?php

namespace Drupal\ys_captcha\Config;

use Drupal\ys_secrets\Config\SecretsConfigOverridesBase;

/**
 * Configuration overrides for reCAPTCHA module.
 *
 * Override the API keys with the value defined in the secrets.json file. This
 * approach keeps the secret value out of the the repo. Overrides do not displa
 * in config forms but can be verified by running:
 * `drush config-get recaptcha.settings --include-overridden`.
 */
final class CaptchaV2ConfigOverrides extends SecretsConfigOverridesBase {

  /**
   * {@inheritdoc}
   */
  protected $configGroup = 'recaptcha.settings';

  /**
   * {@inheritdoc}
   */
  protected $mapping = [
    'site_key' => 'recaptcha_v2_key',
    'secret_key' => 'recaptcha_v2_secret',
  ];

}
