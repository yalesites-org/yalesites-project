# YaleSites Feeds Demo

A proof of concept, not a feature. It exists to answer one question: **should
YaleSites adopt the Feeds module for content import and synchronisation?**

Three demonstration feed types, the fixtures they read, and the small amount of
custom code they turned out to need. The recommendation and the findings are in
[EVALUATION.md](EVALUATION.md) — read that for the decision. **This file is the
runbook: how to get the demo running and what to show.**

> All fixture data is invented. The people, collections, accession numbers and
> documents do not exist.

---

# Start here

The demo is on a Pantheon multidev:

**https://pr-1527-yalesites-platform.pantheonsite.io/**

The modules and feed types are already installed there — they ship in
`config/sync`, so the deploy set them up with no manual step. Confirmed:
`feeds`, `feeds_tamper`, `tamper` and `ys_feeds_demo` are all enabled and all
three feed types exist.

Only one thing is left, because content is not configuration.

### Step 1 — get a login link

```bash
terminus drush yalesites-platform.pr-1527 -- uli
```

Two harmless things about the output: it warns that the environment is in
read-only Git mode (true, and irrelevant — nothing here writes code), and the
link comes back as `http://`, which redirects to https on its own.

### Step 2 — load the demo content

```bash
terminus drush yalesites-platform.pr-1527 -- ys-feeds-demo:run
```

That publishes the fixtures and sets up both demos. You should see:

```
Published fixtures for https://pr-1527-yalesites-platform.pantheonsite.io
Imported demo_staff_roster.
EPS staff roster (demo): Created 21 Profile items.
Created demo_resource_library, ready for a file. Upload this:
  https://pr-1527-yalesites-platform.pantheonsite.io/sites/default/files/feeds-demo/resources.csv
```

No flags needed — the command works out the site's own address from the
Pantheon environment, and checks the fixtures are readable before importing, so
a problem gives you one clear sentence rather than a wall of failed downloads.

**The roster imports straight away. The catalogue is a file upload**, so it is
created empty and waiting — grab the CSV from that URL and attach it, as
described in [Demo 1](#demo-1--a-library-resource-collection). The round-trip
demo needs a file you export during the demo itself, so nothing is created for
it up front.

**Error messages you might see:**

| Message | What it means |
|---|---|
| `... returned 401` or `403` | The environment has been locked. `terminus lock:disable yalesites-platform.pr-1527`. It was unlocked when this was written. |
| Two download errors during the catalogue import | **Expected.** Two rows fail on purpose — see Demo 1, step 3. |
| `ImagickException ... security policy 'PDF'` | Harmless. See [Known noise](#known-noise). |

### Step 3 (optional) — see it as a site owner would

Reviewers do not need uid 1, and one of the questions the evaluation asks is
what an ordinary site owner can safely do. To check that yourself:

```bash
terminus drush yalesites-platform.pr-1527 -- user:create reviewer --mail="reviewer@example.com" --password="<pick one>"
```
```bash
terminus drush yalesites-platform.pr-1527 -- user:role:add site_admin reviewer
```

Signed in as that user, `/admin/content/feed` works and imports run, but
`/admin/structure/feeds` is denied. That split is deliberate — see
[Who can see what](#who-can-see-what).

---

# The walkthrough

Lead with Demo 2 if you only have time for one. It is the strongest case, and
the only thing here that nothing in YaleSites can do today.

## Demo 2 — a roster maintained in a spreadsheet

*A department administrator maintains people in a spreadsheet. The site
follows it. There is no import screen to learn.*

Feed type `demo_staff_roster`, 22 rows.

**Start at** [/admin/content/manage-profiles](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/manage-profiles)
— 21 people. Positions are properly cased, affiliations are separate terms, and
there is no HTML in the teasers. The source CSV has none of that: it has
`PROFESSOR OF GEOPHYSICS`, semicolon-separated affiliations with stray spaces,
and `<p>` tags in every summary. One row has no email address and was skipped,
because a row with no identity cannot be synchronised.

Then show the three things that matter, in order.

**1. Editing a row updates the profile in place.**

```bash
terminus drush yalesites-platform.pr-1527 -- ys-feeds-demo:publish-fixtures --variant=v2
```

Then import from [/admin/content/feed](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/feed).

Three profiles change — a promotion, a postdoc becoming staff, a rewritten
biography — and the report says **"Updated 3"**, not "Created 3". Same node
IDs, same URLs, no duplicates. The other 18 rows are skipped because their
content has not changed.

**2. Deleting a row archives the profile; it does not delete it.**

```bash
terminus drush yalesites-platform.pr-1527 -- ys-feeds-demo:publish-fixtures --variant=v3
```

Import again. One person has gone from the sheet. The report says
**"Cleaned 1"**, and that profile is now Archived — still there, still
restorable, nothing 404s.

**3. Editor work survives the sync.**

Do this *before* step 1 or 2: open any profile, add a text block on the
**Layout** tab, save. Then re-import. The field values update and your
hand-built block is untouched, because Feeds only writes the fields it was
told about.

This is the first question a content governance person asks, and it is worth
answering live rather than describing.

### Pointing it at a real Google Sheet

This is the version of the demo worth showing to a department, because the
spreadsheet becomes the whole editing interface. The source is a field on the
feed, not anything in code, so no deployment is involved.

**1. Get the roster into a sheet.** In Google Sheets, **File → Import →
Upload** and choose `fixtures/staff-roster.csv`. Use Import rather than
copy-paste: the `teaser_summary` column contains commas inside quoted HTML,
and pasting raw CSV text puts every row in one cell.

**2. Publish it as CSV.** **File → Share → Publish to web**, pick the sheet
tab, choose **Comma-separated values (.csv)**, then Publish. Copy the URL,
which looks like:

```
https://docs.google.com/spreadsheets/d/e/<long-id>/pub?gid=0&single=true&output=csv
```

Two things people get wrong here. The URL from the browser address bar is
**not** the same thing and will not work — it serves HTML, not CSV. And
publishing is separate from sharing: a sheet can be shared with people and
still be unpublished, in which case Feeds gets a redirect to a login page.

**3. Point the feed at it.** Edit the roster feed at
[/admin/content/feed](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/feed),
replace the Source URL with the published one, and save. Import.

**4. Now do the actual demo.** Change someone's job title in the sheet, wait a
moment, and import again. The report says **"Updated 1"** and that profile
changes in place. Delete a row and import: **"Cleaned 1"**, and the profile is
archived rather than deleted.

Google caches the published CSV for a few minutes, so an edit may not appear on
the very next import. That is Google's cache, not Feeds — waiting a minute is
usually enough. Worth knowing before you hit Import three times on stage and
conclude it is broken.

The feed is already set to run hourly on cron, so once it points at a sheet it
keeps following it with nobody pressing anything.

## Demo 1 — a library resource collection

*A collections team maintains a catalogue outside Drupal. The site mirrors it,
including the actual documents.*

Feed type `demo_resource_library`, 9 rows. **This one is a file upload.**

This is the demo that does the thing the current importer calls impossible.
`ys_migrate`'s resource CSV importer says in its own code that "a media
reference cannot travel in a CSV cell", and asks a human to attach every file
afterwards, one at a time. Here each row's PDF and cover image are downloaded
and become real `document` and `image` media entities.

Note the split, because it is the point: the **catalogue arrives as a file**
somebody uploads, while the **documents it points at are still fetched** over
HTTP. That is how a real hand-off works — a collections team sends a CSV
export, and the files it references live on their server.

### Running it

`ys-feeds-demo:run` creates this feed empty and ready, and tells you which
file to upload. Get the file from:

**https://pr-1527-yalesites-platform.pantheonsite.io/sites/default/files/feeds-demo/resources.csv**

Then go to [/admin/content/feed](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/feed),
edit **Special Collections catalogue (demo)**, attach that CSV, and import.

The URLs inside that file are absolute and point at this environment, which is
why it is downloaded from the site rather than taken straight from
`fixtures/resources.csv` — the committed fixture carries a `__BASE_URL__`
placeholder that is filled in when the fixtures are published.

### What to show

1. **The content.** [/admin/content/manage-resources](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/manage-resources)
   — eight resources. Open one: it has a PDF, a cover image, and its
   description rendered as a real Layout Builder text block rather than an
   empty page.
2. **They are genuine media entities.** [/admin/content/media](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/media)
   — the PDFs and covers are in the media library like anything an editor
   uploaded, not files bolted onto a field.
3. **The failures, which are deliberate.** Two rows fail and the import says
   why: one points at a PDF that 404s, the other at a `.exe` that the
   extension allow-list refuses. Both rows still import everything else,
   including their cover image. Nothing silently half-succeeds.
4. **Re-uploading a corrected export updates in place.** Edit a title or a
   category in the CSV, upload it again, import. The report says **"Updated"**,
   the resource count stays at eight, and no duplicate appears. The accession
   number is the key, and files already downloaded are reused rather than
   fetched twice. This is the thing the current CSV importer cannot do at all:
   it would either skip every row or duplicate every row.
5. **The mess it cleaned up.** [the tamper screen](https://pr-1527-yalesites-platform.pantheonsite.io/admin/structure/feeds/manage/demo_resource_library/tamper)
   — 25 transformations, for five different date formats, pipe-separated
   taxonomy with trailing separators, HTML in plain-text fields, and a
   discipline column that would otherwise overflow a single-value field.
   *(Platform administrator only.)*

### One caveat worth saying out loud

Dates are normalised with `strtotime`, which **guesses**. `3/4/2015` is 4 March
in the United States and 3 April almost everywhere else, and a bare `1998`
becomes today's month and day in 1998. The existing `ys_migrate` importer
deliberately refuses to guess — it has an explicit list of accepted formats and
rejects anything else, on the grounds that a silently wrong publication date is
worse than a rejected row. On this specific point Feeds is a step backwards,
and that is a choice to make knowingly.

## Demo 3 — export, fix in a spreadsheet, re-import

*Bulk content operations using tooling YaleSites already has.*

Feed type `demo_content_roundtrip`. No fixture — the file comes from the site.

1. Download the CSV from [/admin/content/manage-resources/export](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/manage-resources/export).
2. Open it in Excel or Sheets. Retag a column, rewrite some teaser text. Leave
   the **UUID (do not edit)** column alone.
3. Upload it to the round-trip feed at [/admin/content/feed](https://pr-1527-yalesites-platform.pantheonsite.io/admin/content/feed)
   and import.

Rows update in place. Rows whose UUID is not recognised are reported and
skipped, never created, so a typo cannot mint duplicate content.

This needed a change to `ys_content_export`: it now emits a
`UUID (do not edit)` column plus `Teaser Title` and `Teaser Text`. Without a
stable identifier there is nothing to match an edited row back to, and the URL
column will not do — path aliases regenerate when a title changes, which is
exactly the edit this workflow is for. **That change is worth keeping whatever
Yale decides about Feeds.**

---

# Reset and re-run

To wipe the imported content and start the walkthrough again:

```bash
terminus drush yalesites-platform.pr-1527 -- ys-feeds-demo:reset
```
```bash
terminus drush yalesites-platform.pr-1527 -- ys-feeds-demo:run
```

`reset` deletes the imported nodes, their media and the underlying files, then
removes the feeds. Auto-created taxonomy terms are deliberately left behind.
Inline blocks left by the Layout Builder spike are swept up separately, with
the cleaner YaleSites already ships:

```bash
terminus drush yalesites-platform.pr-1527 -- ys-orphaned-blocks
```

## Who can see what

| | Site administrator | Platform administrator |
|---|---|---|
| `/admin/content/feed` — run an import | yes | yes |
| Change a feed's source URL | yes | yes |
| `/admin/structure/feeds` — feed types, mappings, tampers | **no** | yes |

This split is the permission model [EVALUATION.md](EVALUATION.md) recommends,
committed so the multidev demonstrates it rather than just asserting it.
`administer feeds` is the permission that matters: it allows pointing a feed at
any URL and mapping it onto any field, including the author and published
status. That is considerably more power than the current CSV importers expose,
and it should not go to site owners.

## A different multidev

If the PR number changes, swap `pr-1527` in every command and URL above. The
environment is named after the **pull request number, not the branch**, and
nothing posts the URL to the PR, so the pattern is
`https://pr-<N>-yalesites-platform.pantheonsite.io`. The commands take the
matching terminus target, `yalesites-platform.pr-<N>`.

---

# Running it locally instead

The modules and feed types come from `config/sync`, so a config import
installs them:

```bash
lando drush cim -y
```
```bash
lando drush ys-feeds-demo:run --base-url=http://yalesites-platform.lndo.site:8000
```

**Locally you have to pass `--base-url`.** The catalogue fixture points at its
own PDFs and cover images, so it needs the site's address; the committed CSV
carries a `__BASE_URL__` placeholder filled in when fixtures are published. On
Pantheon the command derives the address from the environment, but a local site
often does not know its own public URL — and on this project it answers on
port 8000, which Drupal does not know about. The preflight check will tell you
if you get it wrong.

Everything else is the same, with `lando drush` in place of
`terminus drush <site>.<env> --`.

---

# What needed custom code

Three plugins, all small, each because Feeds does not cover something YaleSites
needs:

| Code | Why it exists |
|---|---|
| `src/Feeds/Target/MediaFromUrl.php` | Feeds 3.2 has **no media target**. Its File and Image targets write to file fields, not to an entity reference pointing at a media entity. A media target was committed to Feeds' development branch after 3.2 shipped, so this should be deleted when that lands rather than maintained. |
| `src/Plugin/Action/ArchiveModeratedNode.php` | Feeds' "unpublish items no longer in the source" runs core's unpublish action, which sets the status field. Content moderation then recomputes status from the unchanged moderation state and quietly republishes the node — while Feeds records success. Four of the five YaleSites bundles are moderated, so this is not an edge case. |
| `src/EventSubscriber/ModerationStateSubscriber.php` | Gives newly imported nodes an explicit moderation state. Existing nodes are deliberately left alone. |
| `src/Feeds/Target/LayoutBuilderText.php` | **A spike, not a feature.** See below. |

Unit tests: `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_feeds_demo/tests` (19 tests).

## About the Layout Builder spike

It works: an imported resource gets its description as a real text block inside
the content section the bundle's default layout already provides, with the
metadata and related-content sections intact.

It is also deliberately limited, and the limits are the finding:

- **It runs on first import only.** If a node already has a layout, the target
  does nothing — overwriting an editor's page would be worse than leaving it
  empty. That also means changed body text in the source will never reach an
  already-imported node.
- It bypasses `layout_builder_restrictions` and `layout_builder_lock`, both of
  which are enabled here.
- Deleting an imported node orphans its inline block.

Making this production-ready means component diffing, restriction awareness and
orphan handling. That is a feature, not an afternoon.

# Known noise

Importing PDFs logs `ImagickException: ... not allowed by the security policy
'PDF'`. That is ImageMagick refusing to rasterise PDFs for
`media_thumbnails_pdf`. It is unrelated to Feeds, non-fatal, and the documents
import correctly.

# Repository hygiene, and one warning

The modules and the three feed types are committed to
`web/profiles/custom/yalesites_profile/config/sync`. That is what makes the
multidev set itself up, and it is deliberate — `drush deploy` runs a full
config import, so anything enabled by hand on an environment is uninstalled
again on the next push. Config is the only thing that survives.

> **If this branch is merged and released, Feeds is enabled on every Yale
> site.** That is the trade-off for easy shared testing. The PR should not be
> merged as-is; if Yale says yes, the follow-up is a real `ys_feeds` module
> behind the `ys_integrations` per-site toggle.

Enabling Feeds also rewrites 21 entity displays on `profile` and `resource`,
adding `feeds_item` as a hidden field. That churn is in the diff and is
expected.

To unpick the whole config footprint in one command:

```bash
git checkout develop -- web/profiles/custom/yalesites_profile/config/sync
```

Feed types live **only** in `config/sync`, not in the module. An earlier version
shipped them in the module and created them from `hook_install()`, because
Feeds creates the `feeds_item` field only when a feed type is first saved — so
at `config/install` time the dependency does not yet exist and the resource
feed type is rejected. Config import does not have that problem: it resolves
dependencies across the whole import set. Verified by uninstalling everything
and running `drush cim` from a clean slate.
