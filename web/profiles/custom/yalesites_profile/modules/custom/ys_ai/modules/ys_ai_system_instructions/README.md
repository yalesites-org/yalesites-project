# YS AI System Instructions (ys_ai_system_instructions)

Lets site admins edit, version, and roll back the **system instructions** that
shape this site's chatbot assistant. The editing UI lives at
`/admin/config/ys-ai/system-instructions`; version history is at
`/admin/config/ys-ai/system-instructions/versions`.

This module is a submodule of `ys_ai`.

## How instructions reach the live chatbot

The live Yale Chat chatbot (`ys_contoso_chat`) loads its instructions at runtime
from an `ai_assistant_api.ai_assistant` **config entity** — by default the
`beacon` assistant — via `YsContosoChatController` and `AiAssistantApiRunner`.
That entity is the single source of truth consumed at runtime.

This module's job is to keep that entity in sync with the active instruction
version:

- **Save** or **Revert** in the UI makes a version active, then writes that
  version's text to the assistant's `instructions` field. The assistant the
  chatbot actually uses is resolved from `ys_contoso_chat.settings:assistant_id`,
  so edits always target the right assistant.
- The write is performed by
  `SystemInstructionsAssistantWriter::writeInstructions()`, called from
  `SystemInstructionsManagerService`.

There is no second runtime path — the chatbot reads only the assistant entity.

## First use and migration

On first visit to the editing form, if no local versions exist yet, the module
seeds **version 1** from the assistant's current `instructions`
(`seedFromAssistantIfEmpty()` in `SystemInstructionsManagerService`). This
captures whatever an admin previously set — or the shipped default — so existing
content is never lost and the form is never blank.

## Default instructions

The standard default instructions ship on the `beacon` assistant via the profile
config (`config/sync/ai_assistant_api.ai_assistant.beacon.yml`).

Because the `instructions` key is config-ignored (see below), config_ignore
"simple" mode strips it on a first-time import (a CREATE has no active value to
preserve), so on the **first** `drush deploy` the assistant would otherwise come
up with no instructions. `ys_ai_system_instructions_deploy_10001()` closes that
gap: it runs after config import and writes the shipped default to the assistant
**only when its instructions are empty**, so a fresh deployment is usable before
any admin customization and existing sites are left untouched. The default text
is read from sync storage, so the shipped yml stays the single source of truth.

## Protecting admin edits from deploys

The assistant's instructions are an admin-managed, site-specific value, so they
must survive config imports. `config_ignore` ignores the key:

```
ai_assistant_api.ai_assistant.beacon:instructions
```

This is a **key-level** ignore — only `instructions` is protected, not the rest
of the assistant entity, so other assistant config still deploys normally.

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
