<?php

namespace Drupal\ys_mail\Config;

use Drupal\ys_secrets\Config\SecretsConfigOverridesBase;

/**
 * Configuration overrides for ys_mail.
 *
 * Override the mailchimp_transactional API key with the value defined in the
 * secrets.json file. This approach keeps the secret value out of the the repo.
 * Overrides do not display in config forms but can be verified by running
 * `drush config-get mailchimp_transactional.settings --include-overridden`.
 */
final class MailConfigOverrides extends SecretsConfigOverridesBase {

  /**
   * {@inheritdoc}
   */
  protected $configGroup = 'mailchimp_transactional.settings';

  /**
   * {@inheritdoc}
   */
  protected $mapping = [
    'mailchimp_transactional_api_key' => 'mailchimp_transactional_api_key',
  ];

}
