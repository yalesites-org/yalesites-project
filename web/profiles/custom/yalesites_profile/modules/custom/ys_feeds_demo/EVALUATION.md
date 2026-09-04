# Should YaleSites adopt Feeds?

An evaluation based on a working proof of concept, not a paper exercise. Every
claim below was verified by building and running the thing on a local
YaleSites site. Where something is untested, it says so.

## The short version

Feeds' genuine, unmatched contribution to YaleSites is **keeping content in
step with a source that keeps changing, keyed on a stable business identifier,
without overwriting editor work.** That is Demo 2, and nothing in the codebase
does it today.

Almost everything else Feeds offers, YaleSites can already do or nearly do.
The decision is whether that one capability is worth three new dependencies,
one of which has no security coverage, three custom plugins, and a permission
surface considerably larger than the current importers.

**Recommendation: adopt it, narrowly.** Ship Feeds behind the existing
`ys_integrations` per-site toggle, with feed types authored by developers and
site owners given only the permission to press Import. Do not give site owners
the ability to create feed types. Revisit the media target when Feeds 3.3
ships.

## What YaleSites has today

`ys_migrate` provides two hand-rolled CSV importers, for Profile and Resource.
They are careful, well-tested code: BOM stripping for Excel's "CSV UTF-8", a
preview mode, per-row error reporting with true line numbers, header aliasing
that matches the export format, and a deliberate refusal to guess at ambiguous
dates.

They also have three hard limits:

1. **Create-only.** Neither service ever loads and re-saves an existing node.
   Re-uploading a corrected spreadsheet either skips every row or duplicates
   every row. There is no update path anywhere in the module.
2. **No media, by design.** The code says so plainly. Its mitigation is a list
   of titles for a human to open and fix one at a time.
3. **One-shot and manual.** Somebody uploads a file. Nothing syncs, nothing
   schedules, nothing notices when the source changes.

`ys_content_export` produces CSV for all five content types, and the resource
importer already aliases the exporter's header spellings so that an export can
be fed back in. A round-trip loop was most of the way built and blocked only by
the missing update path — and, it turned out, by three missing columns.

## What the proof of concept demonstrated

| | Result |
|---|---|
| Update in place from a changing source | Works. Same node IDs, "Updated 3" not "Created 3". Unchanged rows are skipped by content hash. |
| Removal handling | Works, after custom code. Rows deleted from the source are archived, not deleted. |
| Editor work surviving a sync | Works. Layout Builder pages and unmapped fields are untouched. |
| Media from URLs | Works, with a custom target. PDFs and cover images become real media entities, deduplicated across re-imports. |
| Messy source data | Works. 25 Tamper transformations handle five date formats, inconsistent separators, HTML in plain-text fields and case-mangled titles. |
| Failure reporting | Good. A 404 document, a disallowed file type and a cardinality overflow each produced a clear message naming the row and the reason. |
| Export → edit → re-import | Works, after a small change to `ys_content_export`. |
| Layout Builder content | Spike only. Works on first import; updating imported body content remains unsolved. |

## The findings that should shape the decision

### 1. Feeds' cleanup is silently wrong on moderated content

This is the most important thing the proof of concept uncovered.

Feeds' "unpublish items no longer in the source" setting runs core's unpublish
action, which sets the `status` field. Content moderation then recomputes
`status` from the unchanged moderation state on save and republishes the node
— **and Feeds records the operation as successful**, so it never retries.

Four of the five YaleSites content types are moderated. Out of the box, this
setting appears to work, reports success, and does nothing. It took a
~40-line custom action plugin to fix.

Anyone evaluating Feeds by clicking through its settings would not find this.
It is exactly the class of problem a proof of concept exists to surface.

### 2. The stable release has no media target

Feeds 3.2's File and Image targets write to file and image *fields*. YaleSites
references media entities instead, so neither applies. The stock entity
reference target can autocreate a media entity by name, but has no way to put
a file inside it.

Closing this took roughly 250 lines of security-sensitive download code:
scheme validation, an extension allow-list read from the media type's own
source field, a size check, and deduplication so re-imports do not accumulate
copies of every file.

A media target was committed to Feeds' development branch after 3.2 was
released. **The right move is to treat this custom target as temporary and
delete it when Feeds 3.3 ships.** Building it properly now would mean
maintaining a large piece of code with a known expiry date.

### 3. Feeds Tamper has no stable release

| Package | Version | Security coverage |
|---|---|---|
| `drupal/feeds` | 3.2.0 | Covered |
| `drupal/tamper` | 1.0.0 | Covered |
| `drupal/feeds_tamper` | 2.0.0-rc1 | **Not covered** — release candidates are outside Drupal's security advisory policy |

For an upstream that ships to every Yale site, taking a dependency with no
security advisory policy behind it is a governance decision, not a footnote.
Three options, in rough order of preference:

- Accept it and monitor. It is a mapping-time transformation layer, not a
  request-path component, which limits the exposure.
- Do without it. Feeds' own targets plus a couple of custom ones would cover
  most of what the demos use Tamper for, at the cost of more custom code.
- Wait for a stable release before shipping to production sites.

Feeds Tamper also emits PHP warnings when an exploded source is empty — its
own code carries a `@todo` acknowledging the bug. Working around it means
guarding every multi-value column with a skip-on-empty transformation. It is
minor, but it is the kind of rough edge that suggests where the module's
maturity actually sits.

### 4. Feeds validates more strictly than Drupal's own UI

`layout_builder__layout` is declared with cardinality 1. The Layout Builder
interface writes several sections regardless and never notices, because it does
not run full entity validation. Feeds does, so every imported node failed
until a single constraint was exempted.

The blunt instrument here is `skip_validation`, which disables every check.
The right fix is `skip_validation_types: ['Count']`, exempting exactly one
constraint. Worth knowing that the blunt option exists and will be tempting.

### 5. Shipping feed types has one sharp edge, and a bigger footprint than expected

Feeds creates the `feeds_item` field only when a feed type using it is first
saved. At the moment Drupal processes a module's `config/install`, that field
does not exist, so a feed type mapping it is rejected outright:

    Configuration objects provided by ys_feeds_demo have unmet dependencies:
    feeds.feed_type.demo_resource_library (field.field.node.resource.feeds_item)

Config import does **not** have this problem — it resolves dependencies across
the whole import set and creates the field storage, the field instances and the
feed types in the right order. Verified by uninstalling everything and running
`drush cim` from nothing. So feed types can ship in `config/sync` but not in a
module's `config/install`, which is worth knowing before someone spends an
afternoon on it.

Two things about the footprint are worth stating plainly, because both are
easy to miss until a rollout:

- **Enabling Feeds rewrites 21 entity displays.** Adding `feeds_item` touches
  every view and form display on each bundle that gets a feed. Harmless, but
  it means "turn on Feeds" is not a one-line config diff.
- **No role gets any Feeds permission by default.** All 53 are ungranted, so
  immediately after enabling it, nobody but uid 1 can see the feature at all.
  Whatever permission model Yale wants has to be designed and committed
  deliberately; there is no sensible default to inherit.

There is also a related known issue upstream: feed types mapping
`moderation_state` export without a dependency on the workflow config and fail
on a fresh install. It is open and unpatched. This proof of concept sidesteps
it by setting the moderation state from an event subscriber rather than a
mapping, but anyone mapping it directly will hit it.

### 6. Permissions are a much bigger surface than today's importers

This deserves attention before anything ships.

`administer feeds` lets somebody define a source URL, pull arbitrary remote
content into node fields, choose the entity type and bundle, map to any field
including the author and published status, and configure deletion of items no
longer in the source. That is materially more power than
`yalesites manage settings`, which **four roles currently hold**: Editor,
Contributor, Site administrator and Platform administrator.

The current `ys_migrate` forms expose two fixed shapes with no configurability.
Feeds trades a bounded tool for an unbounded one.

A safe rollout looks like: developers author feed types; site roles get only
the per-feed-type `import` permission; `administer feeds` goes to platform
administrators alone.

### 7. Feeds is more capable and less usable

The existing importers are task-shaped: a form that says what a profile CSV
looks like, validates it, previews it, and reports errors by row. Feeds
presents a mapping grid, a tamper list, and a fetcher configuration.

For a site owner who wants to import a spreadsheet of people, the current form
is better. For a department that wants their spreadsheet to *stay* in sync,
Feeds is the only option that exists.

A synthesis — Feeds as the engine, a locked-down YaleSites form as the face —
is plausible and is real work, not a configuration exercise.

## Where Feeds is the wrong tool

`migrate_plus` should keep everything it currently has. Feeds has no rollback,
no map tables, no dependency ordering between migrations, no
`migration_lookup`, and no Drupal 7 database source. The D7 migrations
(`ys_migrate_onha`, `ys_migrate_sustainability_news`, `ys_migrate_whc`), the
Localist and Campus Groups integrations, and the starterkit content should not
be touched.

The dividing line is clean:

- **One-time, developer-run, needs rollback** → migrate_plus.
- **Recurring, source-owned, needs update-in-place** → Feeds.

## Cost, honestly

- Three dependencies, one without security coverage.
- Three custom plugins, all coupled to Feeds internals. Feeds' development
  branch has already converted plugin annotations to PHP attributes, so all
  three will need work at Feeds 4.
- One of those plugins should be deleted when Feeds 3.3 ships.
- A permission model that has to be designed rather than accepted.
- Feed types are config entities, so on a multi-tenant upstream every site
  inherits every feed type unless they are managed through `config_ignore`
  alongside the existing `ys_core*` and `ys_localist*` entries.

The composer requirement itself ships Feeds' code to every Yale site whether
the module is enabled or not, because contrib must live in the profile's
`composer.json`. That is unavoidable given the CI rules and is worth stating
plainly rather than discovering later.

## What we would do next, if the answer is yes

1. Build `ys_feeds` as a real module with a `ys_integrations` plugin, so it is
   per-site opt-in like Localist and Beacon.
2. Design the permission model before enabling it anywhere.
3. Keep the moderation action and the moderation subscriber; they are needed
   regardless.
4. Drop the media target when Feeds 3.3 ships.
5. Treat the Layout Builder work as a separate, scoped feature, or decide
   explicitly that imported content is fields-only and that a human builds
   the page.
6. Keep `ys_migrate`'s CSV forms for the one-shot case. They are better at it.

## What we would do if the answer is no

The `ys_content_export` change — the UUID and teaser columns — is worth
keeping either way. And the finding that update-in-place is the real gap
stands on its own: it could be added to the existing importers directly, at
considerably less cost than adopting Feeds, if recurring synchronisation turns
out not to be something Yale actually wants.
