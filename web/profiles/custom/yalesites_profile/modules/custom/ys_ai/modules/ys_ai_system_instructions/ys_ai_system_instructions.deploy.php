<?php

/**
 * @file
 * Deploy hooks for the ys_ai_system_instructions module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds the chatbot with the default system instructions on first deployment.
 *
 * The chatbot's runtime prompt field is config-ignored (key-level) so an
 * admin's site-specific value is never overwritten by a config import. For the
 * Beacon assistant that field is the agent's `ai_agents.ai_agent.beacon`
 * `system_prompt` (the assistant delegates to the agent; see
 * SystemInstructionsAssistantWriter). The trade-off is that config_ignore
 * "simple" mode strips an ignored sub-key on a CREATE (a first-time import has
 * no active value to preserve), so on the first deploy the target is imported
 * without a prompt and the chatbot would come up with none.
 *
 * This runs as a deploy hook rather than a post update so it executes *after*
 * configuration import (post updates run during the earlier updatedb step), by
 * which point the entity exists. It writes the shipped default only when the
 * runtime prompt is empty, so it seeds a fresh site and is a no-op on any site
 * that already has a value (it never clobbers an admin's instructions).
 *
 * The default is read from sync storage (the shipped agent/assistant yml),
 * keeping a single source of truth for the default text and avoiding drift with
 * a hardcoded copy.
 */
function ys_ai_system_instructions_deploy_10001() {
  /** @var \Drupal\ys_ai_system_instructions\Service\SystemInstructionsAssistantWriter $writer */
  $writer = \Drupal::service('ys_ai_system_instructions.assistant_writer');

  // Leave any existing instructions (admin value or already-seeded) untouched.
  if (trim((string) $writer->readInstructions()) !== '') {
    return 'Chatbot already has system instructions; nothing to seed.';
  }

  // Read the shipped default straight from sync storage; config_ignore filters
  // the import, not this raw storage read, so the default text is intact here.
  $config_name = $writer->getTargetConfigName();
  $field = $writer->getTargetField();
  if (!$config_name || !$field) {
    return 'Could not resolve the chatbot prompt target; nothing to seed.';
  }

  $sync_data = \Drupal::service('config.storage.sync')->read($config_name);
  $default = is_array($sync_data) ? trim((string) ($sync_data[$field] ?? '')) : '';

  if ($default === '') {
    return 'No default system instructions found in sync config; nothing to seed.';
  }

  if (!$writer->writeInstructions($default)) {
    return 'Chatbot prompt entity could not be loaded; default system instructions not seeded.';
  }

  return 'Seeded default system instructions onto the chatbot.';
}
