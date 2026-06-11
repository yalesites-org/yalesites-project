<?php

/**
 * @file
 * Deploy hooks for the ys_ai_system_instructions module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds the chatbot assistant with the default system instructions on first
 * deployment.
 *
 * The assistant's `instructions` field is config-ignored (key-level) so an
 * admin's site-specific value is never overwritten by a config import. The
 * trade-off is that config_ignore "simple" mode strips an ignored sub-key on a
 * CREATE (a first-time import has no active value to preserve), so on the first
 * deploy the Beacon assistant is imported without any instructions and the
 * chatbot would come up with no system prompt.
 *
 * This runs as a deploy hook rather than a post update so it executes *after*
 * configuration import (post updates run during the earlier updatedb step), by
 * which point the assistant entity exists. It writes the shipped default to the
 * assistant only when its instructions are empty, so it seeds a fresh site and
 * is a no-op on any site that already has a value (it never clobbers an admin's
 * instructions).
 *
 * The default is read from sync storage (the shipped
 * ai_assistant_api.ai_assistant.<id>.yml), keeping a single source of truth for
 * the default text and avoiding drift with a hardcoded copy.
 */
function ys_ai_system_instructions_deploy_10001() {
  /** @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter $writer */
  $writer = \Drupal::service('ys_ai_system_instructions.assistant_writer');

  // Leave any existing instructions (admin value or already-seeded) untouched.
  if (trim((string) $writer->readInstructions()) !== '') {
    return 'Chatbot assistant already has system instructions; nothing to seed.';
  }

  // Read the shipped default straight from sync storage; config_ignore filters
  // the import, not this raw storage read, so the default text is intact here.
  $assistant_id = $writer->getAssistantId();
  $sync_data = \Drupal::service('config.storage.sync')
    ->read('ai_assistant_api.ai_assistant.' . $assistant_id);
  $default = is_array($sync_data) ? trim((string) ($sync_data['instructions'] ?? '')) : '';

  if ($default === '') {
    return 'No default system instructions found in sync config; nothing to seed.';
  }

  if (!$writer->writeInstructions($default)) {
    return 'Chatbot assistant could not be loaded; default system instructions not seeded.';
  }

  return 'Seeded default system instructions onto the chatbot assistant.';
}
