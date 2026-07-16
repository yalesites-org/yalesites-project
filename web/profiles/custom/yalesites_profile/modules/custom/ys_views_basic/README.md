# YaleSites Views Basic

## Description
The View Basic module is a custom utility, built on Drupal Views, that gives advanced users with the ability to generate dynamic content lists based on content metadata. This feature lets users curate the collections of news, events, people, and pages across various sections of their website more effectively. This module serves as a valuable resource for authors seeking to create multiple content streams, leveraging taxonomy and other filters to tailor their content displays. It gives them the ability to create custom queries with little training and in a no-code interface.

## Features
- **Field Plugin**: Data describing a view is stored within a custom schema as a serialized object. This modular design allows for seamless addition and removal of features without compromising metadata storage integrity.
- **Field Widget**: Users interact with a specialized form for constructing queries and render logic using a user-friendly, no-code interface. Natural language and intuitive icons simplify query building, requiring minimal training for authors.
- **Field Formatter**: Metadata is rendered consistently through custom templates, blocks, display modes, and a Drupal View. This consistency ensures that content creators construct Views that align with the YaleSites Design System.
- **View Plugins**: The module incorporates numerous view plugins that interpret view definitions into sorts, filters, pagers, and styles compatible with the core Drupal Views module, enhancing functionality and flexibility.

## Architecture: layered listing blocks

The module uses a layered, per-content-type architecture (see the EPIC,
YaleSites-Internal #1161, and the ADR #1162). Rather than one monolithic `view`
block whose form branched on content type and display mode, there are now **14
listing block content types**, one per `(content type, display mode)` pair,
grouped in the Layout Builder picker by content type:

| Group | Block content types |
|---|---|
| Post Listings | `post_card`, `post_list_item`, `post_condensed` |
| Event Listings | `event_card`, `event_list_item`, `event_condensed`, `event_calendar` *(pre-existing)* |
| People Listings | `profile_card`, `profile_list_item`, `profile_directory`, `profile_condensed` |
| Page Listings | `page_card`, `page_list_item`, `page_condensed` |

### Bundle naming

Each bundle id encodes the pair as `{content_type}_{view_mode}` (e.g.
`post_card`, `profile_directory`). The display mode is **not** a form control —
it comes from the bundle. All bundles share the existing `field_view_params`
field (type `views_basic_params`) and storage; they differ only by the widget
named in their form display.

### Three layers

1. **Views Core (base)** — `ViewsBasicWidgetBase` (abstract) holds all shared
   form logic (filters, sort, display, pinned, field-display options), the
   dependency wiring, and the abstract contract (`getContentType()`,
   `buildEntitySpecificOptions()`, with overridable `getCategoryVocabulary()` /
   `buildCategoryLabel()`). `ViewsBasicManager` holds the shared render logic.
2. **Content type** — one widget per type (`PostViewWidget`, `EventViewWidget`,
   `PageViewWidget`, `ProfileViewWidget`) extending the base and adding only its
   own controls, with no `#states` content-type gating.
3. **Display mode** — one block content type per `(type, mode)` pair. Per-mode
   facts (such as whether the teaser-image option applies) are **capability
   flags** in the bundle definition, not conditionals.

### The bundle definition (replaces `ALLOWED_ENTITIES`)

`ViewsBasicManager::LISTING_BUNDLES` is the single source of truth mapping each
bundle id to its `content_type`, `view_mode`, and `supports_thumbnail` flag. It
lives on the manager (not a widget) so the widget, the field formatter, and the
migration deploy hooks can all reach it. Static accessors
(`getContentTypeForBundle()`, `getViewModeForBundle()`,
`bundleSupportsThumbnail()`, `migrationTargetBundle()`) read it; an unknown
bundle throws rather than guessing a default.

### Adding a display mode

Add a row to `LISTING_BUNDLES`, generate the bundle config (block content type,
a `field_view_params` instance, form display naming the content type's widget,
view display, and a Layout Builder Browser entry), and add placement rules — no
new PHP unless the mode needs behaviour a capability flag cannot express.

### Render isolation

Each block renders in isolation: `ViewsBasicManager::initView()` clones the
scaffold view config entity per instance and the pager gets a per-block element
id, so multiple listing blocks on one page cannot clobber each other's settings
or pagination (#906).

### Placement restrictions

Per-display-mode Layout Builder placement is enforced via
`entity_view_mode_restriction_by_region`; see
[`docs/placement-restrictions.md`](docs/placement-restrictions.md). Authoring
form design notes are in [`docs/authoring-form-ux.md`](docs/authoring-form-ux.md),
and the QA checklist is in [`docs/qa-matrix.md`](docs/qa-matrix.md).

### Migration

The legacy `view` block and the predecessor `post_list` / `event_list` /
`directory` blocks are migrated in place to the new bundles by the
`ys_views_basic_deploy_10001()` / `_10002()` deploy hooks. The legacy `view`
bundle is kept in config as a safety net; a status-report warning surfaces any
unconverted instance. See [`CHANGELOG.md`](CHANGELOG.md).

## Running tests

This module has PHPUnit tests under `tests/src/` covering ViewsBasicManager, the EventsCalendar service, and several Views plugins. Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/tests"
```

Add `--testdox` for readable output.
