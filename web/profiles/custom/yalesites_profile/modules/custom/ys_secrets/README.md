# YaleSites Secrets Mannager

The YaleSites upstream project and all of its configurations are stored in a
public repository. As a result sensative information such as API keys are stored
in the Pantheon secrets.json file instead of the standard Drupal config YAML
files. Utilities in this module help to load API keys from the secrets file at
runtime.

## How to add a secret API key to the platform

Step 1. Within the Drupal interface add a fake API key with the value 'HIDDEN'.
Config-export the changes to store the value as stagged configuration. For
example, see: web/profiles/custom/yalesites_profile/config/sync/mailchimp_transactional.settings.yml

Step 2. Disable the API key field within the Drupal interface using a
ys_mail_form_FORM_ID_alter() so that future site builders are not confused by
the 'HIDDEN' value. The `disableField` method is useful for disabling the field.
It is not obvious when Drupal's config-override system is being used to replace
a value so we want to leave clues for future developers. For example, see
ys_mail_form_mailchimp_transactional_admin_settings_alter()

Step 3. Set the API key in the appropriate Pantheon environment. This will
require the [Terminus Secrets Plugin](https://github.com/pantheon-systems/terminus-secrets-plugin).
Following the format: `terminus secrets:set site.env key value`

Step 4. Create a class to override Drupal configuration with values stored in
the secrets.json file. This class will map the name of the configuration in
Drupal with the key in the secrets.json file. See example:
Drupal/ys_mail/Config/MailConfigOverrides
