# YaleSites Recaptcha

This module customizes recaptcha settings for the YaleSites platform.

This includes:

- Setting the recaptcha v2 and v3 API keys from a value stored in a Terminus secrets file.
- Disabling the recaptcha API key field to improve the admin interface.

## Requirements

Recaptcha API keys must be set in a `secrets.json` file to use the service.
After enabling this module, verify that the API keys are set.

```bash
lando drush config-get recaptcha.settings --include-overridden
lando drush config-get recaptcha_v3.settings --include-overridden
```

If this file is missing, then pull the latest files into the local environment.

```bash
lando pull --database=none --files=dev --code=none
```
