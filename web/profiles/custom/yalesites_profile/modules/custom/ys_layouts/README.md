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

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_layouts/tests --testdox"
```
