# YaleSites Layouts

## Description

The layouts module organizes work related to YaleSite's implementation of Layout Builder. This includes the definition of custom layouts including the banner, page meta, and two column sections.

## Meta Fields Manager

This module includes a service called `MetaFieldsManager` that was specifically made generic to support other meta data (field data) for any content type to be used in different ways. The first use of this is for events. Event data has some fields that need to be used in multiple places (custom block and various view modes) and also have some additional calculation required before display. For example, the date field is calculated ahead of time to provide the following information to twig templates in an array:

`event_dates`

* value - Raw Unix timestamp of start date and time (i.e. 1714757400)
* end_value - Raw Unix timestamp of end date and time (i.e. 1714761000)
* duration - Duration in minutes (i.e. 60)
* timezone - The timezone (i.e. America/New_York)
* formatted_start_date - Formatted as: Friday, May 3rd, 2024
* formatted_start_time - Formatted as: 1:30 pm EDT
* formatted_end_date - Formatted as: Friday, May 3rd, 2024
* formatted_end_time - Formatted as: 2:30 pm EDT
* original_start - The unformatted UTC start datetime
* original_end - The unformatted UTC end datetime
* is_all_day - Boolean for all day events (i.e. false)

The `ics_url` is auto-calculated if there is an ICS URL provided from Localist, then use it. If not, calculate an ICS URL dynamically from the first date in the series.

## Orphaned inline blocks

When an editor removes a non-reusable ("inline") block from a page's layout, the block
disappears from the page but its `block_content` record stays in the database. Nothing in the
admin UI can reach it afterwards: non-reusable blocks have no canonical route, so
`/block/{id}/edit` returns 404 for every one of them, orphaned or not.

This is documented Drupal core behaviour, not a YaleSites defect, and core cannot clean it up
for a node that still exists:

- `layout_builder_cron()` collects only `inline_block_usage` rows whose `layout_entity_type`
  and `layout_entity_id` are **both** NULL, which happens when the parent entity is deleted.
- `InlineBlockEntityOperations::removeUnusedForEntityOnSave()` returns early for anything
  implementing `RevisionableInterface`. Nodes always do, so it never runs for them. Core skips
  it deliberately: an older revision of the node might still reference the block, and core
  cannot prove otherwise cheaply from inside a single save.

### Sweeping them up

A deliberate sweep can afford the proof core cannot, so this module provides one **on demand**.
It is **not** wired into cron, because automatically deleting editor content on a schedule is a
much riskier default than leaving inert rows in place.

```bash
# Report only. Changes nothing.
lando drush ys-layouts:orphaned-blocks

# Delete the reported orphans.
lando drush ys-layouts:orphaned-blocks --delete
```

Safety guarantees, in order of importance:

1. **Report by default.** Deletion happens only with the explicit `--delete` flag.
2. **A block counts as referenced if *any* revision of *any* layout-bearing entity points at
   *any* of its revisions.** This is stricter than core's own intent and is what makes deletion
   safe.
3. **Layout Builder default layouts stored in config are checked too.** They belong to no node,
   so a node-only sweep would report their blocks as orphans and delete live content.
4. **Blocks referenced only by a non-default revision are never deleted.** They are listed
   separately in the command output, because rolling a node back to such a revision would make
   the block live again. Choosing to delete that category would be a separate, explicit
   decision.
5. **Deletion derives its own list** and accepts no IDs from the caller, so removing a block
   that is still on a page is structurally impossible rather than merely guarded against.

Reusable blocks from the Custom Block Library are never candidates; only `reusable = 0` blocks
are considered.

### From the admin UI

The command needs terminal access, which means the sweep only ever ran on the sites someone
remembered to run it on. Platform admins can reach the same sweep per site at:

```
/admin/yalesites/orphaned-inline-blocks
```

The screen reports and nothing more; deleting is a separate confirm step at
`/admin/yalesites/orphaned-inline-blocks/delete`, linked from the report only when there is
something to delete. Both routes are gated on `administer orphaned inline blocks`, a
`restrict access: true` permission granted to `platform_admin` alone — deliberately not
`yalesites manage settings` (site admins hold that) and not `administer site configuration`
(no role on the platform holds it, so `ys_layouts.settings_form` is effectively user-1-only).

The confirm form deletes *all* orphans rather than a selection, because `deleteOrphans()`
derives its own list — see guarantee 5 above. The count shown on the report is therefore
advisory: the list is worked out again when you confirm, so a block that was put back on a
page in between is left alone. Every deletion is written to the `ys_layouts` log channel with
the IDs removed, whichever route triggered it.

The sweep runs synchronously in the request. It walks every revision of every layout-bearing
entity, so cost scales with revision count rather than orphan count; on a site with ~180
orphans and a normal revision history it completes in about two seconds, comfortably inside a
web request. The drush command remains the escape hatch if a site ever grows large enough to
risk a PHP timeout, and is still the right tool for scripting across many sites.

#### Timing: deploys and unsaved layout work

A button can be pressed at any moment, whereas a drush command is run deliberately by someone
who knows what else is happening on the site. Both timing questions were checked before the
screen was exposed:

- **Unsaved layout work is invisible to the sweep, so it cannot be collected.** Adding, cloning
  or detaching a block in Layout Builder carries the new block on the component as
  `block_serialized` with `block_revision_id` set to `NULL`; no `block_content` row exists until
  the layout is saved. Confirmed against a tempstore holding an unsaved cloned block: no rows
  created and no new orphans reported, so an editor's in-progress layout cannot be swept out
  from under them.
- **A deploy does not change the sweep's answer.** Its only inputs are saved entity revisions
  and the Layout Builder defaults in `entity_view_display` config. No default layout in
  `config/sync` carries a `block_revision_id`, so a `config:import` cannot transiently strip the
  last reference to a block, and this module ships no update hook that rewrites layout data.
  Deploys do not enable maintenance mode, so the screen stays reachable while one runs — which
  is safe today. An update hook that rewrote layouts would reintroduce this risk and should
  finish before anything sweeps.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_layouts/tests --testdox"
```
