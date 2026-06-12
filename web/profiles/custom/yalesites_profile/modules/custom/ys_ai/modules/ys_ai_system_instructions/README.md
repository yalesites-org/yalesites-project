# YS AI System Instructions (ys_ai_system_instructions)

Lets site admins edit, version, and roll back the **system instructions** that
shape this site's chatbot assistant. The editing UI lives at
`/admin/config/ys-ai/system-instructions`; version history is at
`/admin/config/ys-ai/system-instructions/versions`.

This module is a submodule of `ys_ai`.

## How instructions reach the live chatbot

The live Yale Chat chatbot (`ys_contoso_chat`) runs the configured `ai_assistant`
entity — by default `beacon` — through `YsContosoChatController` and
`AiAssistantApiRunner`. **Which field holds the live prompt depends on whether
the assistant delegates to an agent:** when the assistant has an `ai_agent` set
and the `ai_agents` module is enabled, `AiAssistantApiRunner` hands off to the
agent runner and the assistant's own `instructions` field is never read — the
prompt comes from the agent's `system_prompt`. The Beacon assistant has
`ai_agent: beacon`, so its live prompt is `ai_agents.ai_agent.beacon:system_prompt`.

This module's job is to keep that runtime field in sync with the active
instruction version:

- **Save** or **Revert** in the UI makes a version active, then writes that
  version's text to the runtime field.
- `SystemInstructionsAssistantWriter` resolves the target the same way the runner
  does: it loads the assistant (id from `ys_contoso_chat.settings:assistant_id`),
  and if the assistant delegates to an agent it targets the agent's
  `system_prompt`; otherwise it targets the assistant's `instructions`. So edits
  always land on whatever field the chatbot actually reads.

There is no second runtime path.

## First use and migration

On first visit to the editing form, if no local versions exist yet, the module
seeds **version 1** from the runtime field's current value
(`seedFromAssistantIfEmpty()` in `SystemInstructionsManagerService`, via the
writer). This captures whatever an admin previously set — or the shipped default
— so existing content is never lost and the form is never blank.

## Default instructions

The standard default ships on the `beacon` agent via the profile config
(`config/sync/ai_agents.ai_agent.beacon.yml`, the `system_prompt` field).

Because that key is config-ignored (see below), config_ignore "simple" mode
strips it on a first-time import (a CREATE has no active value to preserve), so
on the **first** `drush deploy` the agent would otherwise come up with no prompt.
`ys_ai_system_instructions_deploy_10001()` closes that gap: it runs after config
import and writes the shipped default to the runtime field **only when it is
empty**, so a fresh deployment is usable before any admin customization and
existing sites are left untouched. The default text is read from sync storage
(via the writer's resolved target config name), so the shipped yml stays the
single source of truth.

## Protecting admin edits from deploys

The chatbot prompt is an admin-managed, site-specific value, so it must survive
config imports. `config_ignore` ignores the runtime key:

```
ai_agents.ai_agent.beacon:system_prompt
```

This is a **key-level** ignore — only `system_prompt` is protected, not the rest
of the agent entity, so other agent config still deploys normally.

## Settings

The settings form at `/admin/config/ys-ai/system-instructions/settings` exposes:

- **Enable System Instruction Modification** — gates the editing UI.
- **Content Length Controls** — the recommended max length and warning threshold
  used by the character counter on the editing form.

The legacy external Azure function API integration (endpoint / web app name /
API key, the `SystemInstructionsApiService`, and the manual **Sync** pull) has
been removed. The live chatbot reads the assistant config entity, which
Save/Revert keep current.

## Tests

- `tests/src/Unit/SystemInstructionsAssistantWriterTest.php` — covers the
  assistant id resolution and the read/write/missing-entity behavior of the
  writer service.
