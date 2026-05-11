<?php

/**
 * @file
 * Lando-only: select the OpenAI Key on the provider form.
 *
 * Key `openai_api_key` uses the Pantheon Secrets provider (same pattern as
 * mailchimp_transactional_api_key, recaptcha_*, etc.). Locally, Lando sets
 * CUSTOMER_SECRETS_FAKE_FILE to /app/secrets.json — add an `openai_api_key`
 * entry there (see scripts/local/setup.sh / terminus secret:site:local-generate).
 *
 * On Pantheon, add the secret named `openai_api_key` in the Secrets Manager,
 * then deploy or refresh local secrets export.
 */

$config['ai_provider_openai.settings']['api_key'] = 'openai_api_key';
