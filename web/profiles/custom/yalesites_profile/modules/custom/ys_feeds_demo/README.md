# YaleSites Feeds Demo

A proof of concept, not a feature. This module exists to answer one question:
**should YaleSites adopt the Feeds module for content import and
synchronisation?** It installs three demonstration feed types, the fixtures
they read, and the small amount of custom code they turned out to need.

The recommendation and the findings are in [EVALUATION.md](EVALUATION.md).
This file is the runbook.

Everything here is disposable. `drush pm:uninstall ys_feeds_demo` removes all
three feed types, and `drush ys-feeds-demo:reset` removes the content they
created.

> All fixture data is invented. The people, collections, accession numbers and
> documents do not exist.

## Setup

The modules and feed types come from `config/sync`, so a normal config import
installs them:

```bash
lando drush cim -y
lando drush ys-feeds-demo:run --base-url=http://yalesites-platform.lndo.site:8000
```

`run` publishes the fixtures and imports both fetching feeds. The round-trip
feed takes an uploaded file, so it is driven by hand.

**About `--base-url`.** The catalogue fixture points at its own PDFs and cover
images, so it has to know the site's address; the committed CSV carries a
`__BASE_URL__` placeholder that is filled in when the fixtures are published.
The command works the address out on its own — the Pantheon platform domain
when running there, otherwise whatever address the site thinks it has — and
checks it before importing, so a wrong guess produces one clear sentence
rather than a wall of failed downloads. Pass `--base-url` when the site cannot
know its own public address, which locally it usually cannot.

To start over at any point:

```bash
lando drush ys-feeds-demo:reset
```

That deletes the imported nodes, their media and the underlying files, then
removes the feeds. Auto-created taxonomy terms are deliberately left behind;
`lando drush ys-orphaned-blocks` sweeps up inline blocks left by the Layout
Builder spike.

Feed types live at `/admin/structure/feeds`, feeds at `/admin/content/feed`.

## Demo 1 — a library resource collection

*A collections team maintains a catalogue outside Drupal. The site mirrors it,
including the actual documents.*

Feed type `demo_resource_library`, fixture `fixtures/resources.csv`,
9 rows.

This is the demo that does the thing the current importer says is impossible.
`ys_migrate`'s resource CSV importer notes in its own code that "a media
reference cannot travel in a CSV cell", and asks a human to attach every file
by hand afterwards. Here each row's PDF and cover image are fetched from a URL
and become real `document` and `image` media entities on `field_media` and
`field_teaser_media`.

Worth showing, in this order:

1. **The content.** `/admin/content/manage-resources` — eight resources, each
   with a PDF and a cover. Open one: the description is a real Layout Builder
   text block, not an empty page.
2. **The mess it cleaned up.** `/admin/structure/feeds/manage/demo_resource_library/tamper`
   — 25 transformations. The source has publication dates in five different
   shapes, pipe-separated taxonomy with stray spaces and trailing separators,
   HTML in plain-text fields, and a discipline column that would overflow a
   single-value field.
3. **The failures.** Two rows fail deliberately and the import tells you why:
   one points at a PDF that 404s, the other at a `.exe` that the extension
   allow-list refuses. Both rows still import everything else, including their
   cover image. Nothing silently half-succeeds.
4. **Re-import.** Run it twice. Node count and media count do not move — the
   accession number is the key, and files already fetched are reused rather
   than downloaded again.

### The honest caveat, worth saying out loud

Dates are normalised with `strtotime`, which *guesses*. `3/4/2015` becomes
4 March in the United States and 3 April almost everywhere else, and a bare
`1998` becomes today's month and day in 1998. The existing `ys_migrate`
importer deliberately refuses to guess: it has an explicit list of accepted
formats and rejects the row otherwise, on the grounds that a silently wrong
publication date is worse than a rejected row. On this point Feeds is a step
backwards, and that is a choice to make consciously.

## Demo 2 — a roster maintained in a spreadsheet

*A department administrator maintains people in a spreadsheet. The site
follows it. There is no import screen to learn.*

Feed type `demo_staff_roster`, fixtures `fixtures/staff-roster.csv` and its
`-v2` and `-v3` variants, 22 rows.

This is the strongest case for Feeds, and the one to lead with if there is
only time for one. Three things to prove, in order:

**1. Editing a row updates the profile in place.**

```bash
lando drush ys-feeds-demo:publish-fixtures --variant=v2
```
Re-import from `/admin/content/feed`. Three profiles change — a promotion, a
postdoc becoming staff, a rewritten biography — and the report says
"Updated 3", not "Created 3". Same node IDs, same URLs, no duplicates. The
other 18 rows are skipped because their content has not changed.

**2. Deleting a row archives the profile; it does not delete it.**

```bash
lando drush ys-feeds-demo:publish-fixtures --variant=v3
```
Re-import. One row has gone from the sheet. The report says "Cleaned 1", and
that profile is now Archived — still there, still restorable, nothing 404s.

**3. Editor work survives the sync.**

Before re-importing, open a profile, add a text block in Layout Builder, and
save. Re-import. The field values update and the hand-built block is untouched,
because Feeds only writes the fields it was told about. This is the question a
content governance person asks first, and it is worth answering live.

### Pointing it at a real Google Sheet

The feed uses the HTTP fetcher, so the source is one field on the feed rather
than anything in code. In Google Sheets: **File → Share → Publish to web**,
choose the sheet, select **Comma-separated values (.csv)**, and paste the
resulting URL into the feed's Source field. Nothing else changes.
`fixtures/staff-roster.csv` is laid out to be pasted straight into a sheet.

## Demo 3 — export, fix in a spreadsheet, re-import

*Bulk content operations using tooling YaleSites already has.*

Feed type `demo_content_roundtrip`. No fixture: the file comes from the site.

1. Go to `/admin/content/manage-resources/export` and download the CSV.
2. Open it in a spreadsheet. Retag a column, rewrite some teaser text.
3. Upload it at `/admin/content/feed` on the round-trip feed and import.

Rows update in place. Rows whose UUID is not recognised are reported and
skipped rather than created, so a typo cannot mint duplicate content.

This demo needed a change to `ys_content_export`: it now emits a
`UUID (do not edit)` column, plus `Teaser Title` and `Teaser Text`. Without a
stable identifier there is nothing to match an edited row back to, and the URL
column will not do — path aliases regenerate when a title changes, which is
exactly the edit this workflow is for. That change is small, tested, and
useful whatever Yale decides about Feeds.

## What needed custom code

Three plugins, all in `src/`, all small, and each one exists because Feeds
does not cover something YaleSites needs:

| Code | Why it exists |
|---|---|
| `Feeds/Target/MediaFromUrl.php` | Feeds 3.2 has no media target. Its File and Image targets write to file fields, not to an entity reference pointing at a media entity. A media target was committed to Feeds' development branch after 3.2 shipped, so this should be deleted when that lands rather than maintained. |
| `Plugin/Action/ArchiveModeratedNode.php` | Feeds' "unpublish items no longer in the source" runs core's unpublish action, which sets the status field. Content moderation then recomputes status from the unchanged moderation state and quietly republishes the node — and Feeds records success. Four of the five YaleSites bundles are moderated, so this is not an edge case. |
| `EventSubscriber/ModerationStateSubscriber.php` | Gives newly imported nodes an explicit moderation state. Existing nodes are deliberately left alone. |
| `Feeds/Target/LayoutBuilderText.php` | **A spike, not a feature.** See below. |

### About the Layout Builder spike

It works: an imported resource gets its description as a real text block
inside the content section the bundle's default layout already provides, with
the metadata and related-content sections intact.

It is also deliberately limited, and the limits are the finding:

- **It runs on first import only.** If a node already has a layout, the target
  does nothing, because overwriting an editor's page would be worse than
  leaving it empty. That also means changed body text in the source will never
  reach an already-imported node.
- It bypasses `layout_builder_restrictions` and `layout_builder_lock`, both of
  which are enabled here.
- Deleting an imported node orphans its inline block.

Making this production-ready means component diffing, restriction awareness
and orphan handling. That is a feature, not an afternoon.

## Known noise in the local environment

Importing PDFs logs `ImagickException: ... not allowed by the security policy
'PDF'`. That is ImageMagick's local policy refusing to rasterise PDFs for
`media_thumbnails_pdf`. It is unrelated to Feeds, non-fatal, and the documents
import correctly.

## Testing on a Pantheon multidev

Pushing the branch is not enough — the deploy workflow runs on **pull request**
open and on every push afterwards. Once a PR exists:

- The environment is named after the **PR number, not the branch**:
  `https://pr-<N>-yalesites-platform.pantheonsite.io`. Nothing posts that URL
  to the PR, so reviewers have to know the convention.
- The workflow runs `drush deploy` itself, which imports config. Because the
  modules and feed types are committed to `config/sync`, the multidev comes up
  with everything **installed and configured, with no manual step**.
- The database is cloned from `dev` when the environment is first created.
  Later pushes update code only.

Content is not config, so seeding is one command:

```bash
terminus drush <site>.pr-<N> -- ys-feeds-demo:run
```

No `--base-url` needed: on Pantheon the command derives the platform domain
from the environment. Then swap roster variants the same way:

```bash
terminus drush <site>.pr-<N> -- ys-feeds-demo:publish-fixtures --variant=v2
```

If `run` reports a 401 or 403, the environment has been locked and the feeds
cannot read their own fixtures. Unlock it:

```bash
terminus lock:disable <site>.pr-<N>
```

### Who can see it

Reviewers do not need uid 1. `Site administrator` can reach
`/admin/content/feed`, run an import and repoint a feed's source — and
nothing else. `Platform administrator` additionally gets `administer feeds`
and the tamper screens.

That split is deliberate: it is the permission model
[EVALUATION.md](EVALUATION.md) recommends, so the multidev demonstrates it
rather than just asserting it. `administer feeds` is the permission that
matters — it allows pointing a feed at any URL and mapping it onto any field,
which is considerably more power than the current CSV importers expose.

## Repository hygiene

The modules and the three feed types are committed to
`web/profiles/custom/yalesites_profile/config/sync`. That is a deliberate
choice for this branch, and it has a consequence worth being explicit about:
**if this branch is merged and released, Feeds is enabled on every Yale site.**
It is committed there because `drush deploy` runs a full config import, so
anything enabled by hand on an environment is uninstalled again on the next
push — config is the only thing that survives.

Enabling Feeds also rewrites 21 entity displays on `profile` and `resource`,
adding `feeds_item` as a hidden field. That churn is in the diff and is
expected.

To unpick the whole config footprint in one command:

```bash
git checkout develop -- web/profiles/custom/yalesites_profile/config/sync
```

Feed types are stored **only** in `config/sync`, not in the module. An earlier
version shipped them in the module and created them from `hook_install()`,
because Feeds creates the `feeds_item` field only when a feed type is first
saved — so at `config/install` time the dependency does not exist yet and the
resource feed type is rejected. Config import does not have that problem: it
resolves dependencies across the whole import set, creating the field storage,
the field instances and the feed types in order. Verified by uninstalling
everything and running `drush cim` on a clean slate.
