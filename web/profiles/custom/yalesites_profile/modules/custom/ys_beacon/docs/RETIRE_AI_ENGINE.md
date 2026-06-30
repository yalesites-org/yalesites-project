# Retiring the legacy `ai_engine` stack (second-release checklist)

Tracks issue #1297. **This is deliberately deferred to a follow-up release — no
code is removed in the current release.** `ai_engine` stays installed and
coexisting: Beacon supersedes its editor-facing surfaces, the AI metadata
migration runs, and nothing is uninstalled yet.

## Why this is deferred

`ys_beacon` intentionally coexists with `ai_engine` during the migration
window for two reasons:

1. **Data safety.** Legacy AI metadata on content is converted to Beacon's
   native handling by the metadata migration that ships in the current
   release. Uninstalling `ai_engine` / `ai_engine_metadata` before that
   migration has run **on every environment, production included** would drop
   metadata that has not yet been converted.
2. **No two chat widgets.** Beacon and the legacy `ai_engine_chat` widget must
   never render together. While both modules are installed, Beacon yields to
   the legacy widget when the legacy chat is enabled (see
   `ys_beacon_legacy_chat_active()`), so sites migrate one at a time without a
   double widget.

## Hard prerequisite

Before any removal work begins, **confirm the AI metadata migration has
completed on all environments (dev, test, live, and all multidev/site
instances).** Verify converted metadata on a representative migrated node
before touching anything below.

## Current coupling points (what to undo)

Unlike the original ticket draft (which described a `ys_ai` that *extended*
`ai_engine`'s settings form), the consolidated `ys_beacon` module already has
**no `ai_engine` dependency** in `ys_beacon.info.yml` and owns its own config
(`ys_beacon.settings`). The remaining couplings are runtime coexistence
guards and shared metadata identifiers:

- `ys_beacon.module`
  - `ys_beacon_legacy_chat_active()` — reads `ai_engine_chat.settings:enable`
    and `moduleExists('ai_engine_chat')`.
  - `ys_beacon_page_attachments_alter()` — merges
    `ai_engine_chat.settings` cache tags and suppresses the Beacon widget when
    the legacy chat is active.
  - `ys_beacon_page_bottom()` — same legacy-active guard for the floating
    button.
  - `ys_beacon_metatag_groups_alter()` — `unset($definitions['ai_engine'])`,
    which exists only to silence Metatag's tagless-group error while
    `ai_engine_metadata` is still installed.
- `ys_beacon/src/Form/YsBeaconSettings.php` — validation that blocks enabling
  Beacon chat while the legacy `ai_engine_chat` widget is on.
- `ys_beacon/src/Plugin/metatag/Tag/*` — `AiDescription`, `AiTags`,
  `AiDisableIndexing` intentionally reuse the legacy `ai_engine_metadata`
  plugin IDs so editor metadata carries over. These IDs can stay; only the
  legacy group/module goes away.
- `config_ignore.settings.yml` — the `ai_engine*` ignore entry.

## Removal steps (follow-up release)

1. **Confirm the metadata migration ran everywhere** (prerequisite above).
2. **Remove the coexistence guards in `ys_beacon`:**
   - Delete `ys_beacon_legacy_chat_active()` and its callers' guards in
     `ys_beacon_page_attachments_alter()` and `ys_beacon_page_bottom()`.
   - Drop the legacy-chat cache-tag merge in `ys_beacon_page_attachments_alter()`.
   - Remove the "legacy chat must be off" validation in `YsBeaconSettings.php`.
   - Remove `ys_beacon_metatag_groups_alter()` (no longer needed once the
     empty `ai_engine` metatag group is gone with the module).
3. **Purge dormant legacy data.** Add a deploy/update hook to purge the
   `ai_engine` metatag-group values left inert in `field_metatags` by the
   migration. Verify on a migrated node before and after.
4. **Uninstall and remove the modules:** `ai_engine`, `ai_engine_chat`,
   `ai_engine_embedding`, `ai_engine_feed`, `ai_engine_metadata` per the
   project's contrib-removal process (composer remove + config export).
5. **Clean config.** Remove the `ai_engine*` entry from
   `config_ignore.settings.yml` and any exported config that still references
   `ai_engine*`.
6. **Verify end to end** with `ai_engine` gone: Beacon chat answers with
   citations, content indexes into Azure AI Search, the Beacon settings and
   system-instructions screens work, and editor AI metadata is intact.

## Acceptance criteria (from #1297)

- [ ] Metadata migration confirmed run on all environments before removal.
- [ ] `ys_beacon` no longer reads any `ai_engine*` config or guards against the
      legacy widget at runtime.
- [ ] An update/deploy hook purges dormant legacy AI metatag values from
      `field_metatags` (verified on a migrated node).
- [ ] The legacy metatag-group form handling is removed.
- [ ] `ai_engine` and submodules uninstalled and removed; `config_ignore`
      updated.
- [ ] Beacon chat, AI indexing, and AI settings screens work end to end with
      `ai_engine` gone.
