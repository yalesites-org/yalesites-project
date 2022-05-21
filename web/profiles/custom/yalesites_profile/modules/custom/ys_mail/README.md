# YaleSites Mail

This module customizes emails sent from sites on the YaleSites platform. This includes:

- Setting the MailChimp API from a value stored in a Terminus secrets file.
- Disabling the MailChimp API key field to improve the admin interface.
- Altering email metadata to ensure useful data is sent to MailChimp.

## Requirements

A MailChimp API key must be set in a `secrets.json` file to use the transactional email service. After enabling this module, verify that the api key is set.

```bash
lando drush config-get mailchimp_transactional.settings mailchimp_transactional_api_key --include-overridden
```
