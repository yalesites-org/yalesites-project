# Changelog

## Resource listings folded into Views Basic (#1723)

### Added

- `ResourceViewWidget` and four resource listing block types —
  `resource_card`, `resource_portrait_grid`, `resource_list_item`,
  `resource_condensed` — served by `ViewsBasicManager` through a new
  `views_basic_scaffold_resources` view. Resources appear as a content type
  tile in the Views wizard. Every option the `ys_views_content_resources`
  widget offered stays available under the same label.
- `ys_views_basic_deploy_10003()` moves existing `resource_view` blocks onto
  the new bundles, copying and normalising their params on every revision.
- The `resource_year_filter` views filter now lives in this module.

### Changed

- `resource_view` is removed from the Layout Builder picker. The block type,
  its field and the `ys_views_content_resources` module stay until a
  follow-up removes them.

## Views Block Architectural Rework (EPIC #1161)

Refactored the single monolithic `view` block into a layered, per-content-type
architecture.

### Added

- Abstract `ViewsBasicWidgetBase` "Views Core" layer holding all shared form
  logic, with a bundle-keyed definition (`ViewsBasicManager::LISTING_BUNDLES`)
  mapping each bundle to its (content type, display mode) pair (#1163).
- Per-content-type widgets — `PostViewWidget`, `EventViewWidget`,
  `PageViewWidget`, `ProfileViewWidget` — and 12 listing block content types:
  `post_card` / `post_list_item` / `post_condensed`, and the matching
  `event_*`, `page_*`, and `profile_*` (#1164–#1167). The existing `event_calendar` block is unchanged.
- Block-picker grouping into Post / Event / People / Page Listings categories,
  and per-display-mode Layout Builder placement restrictions: card and
  condensed everywhere; list in wide regions only (#1168).
- Authoring-form UX: grouped detail sections, clearer labels, and help text
  (#1316/#1317).

### Changed

- Each Views block now renders in isolation: `ViewsBasicManager::initView()`
  clones the scaffold view per instance and pagers get a per-block element id,
  fixing cross-block setting clobbering (#906/#1306).
- The legacy `view` block and the predecessor `post_list` / `event_list` /
  `directory` blocks are removed from the Layout Builder picker (#1170).

### Removed

- The profile-only `profile_directory` listing bundle, its fields, displays,
  picker entry and role permissions, and the profile "Directory Grid" display
  mode choice (#1682). Small profile cards with department, email and phone
  switched on reproduce its look.

### Migration

- `ys_views_basic_deploy_10001()` migrates existing `view` blocks in place to
  their `{type}_{view_mode}` bundle and rewrites Layout Builder placements
  across all revisions (#1169).
- `ys_views_basic_deploy_10002()` supersedes `post_list` / `event_list` /
  `directory` instances, converting them to the equivalent new bundles with
  pre-filled params (#1170). Profile directory listings land on `profile_card`
  with small cards and department, email and phone on (#1682).
- `ys_views_basic_post_update_retire_profile_directory()` converts existing
  `profile_directory` blocks to `profile_card` the same way and rewrites their
  placements (#1682). It is a post-update, not a deploy hook, so it runs
  before config import deletes the bundle.

### Deprecated / follow-up

- The legacy `view` bundle is kept in config so any unconverted instance still
  renders; a status-report warning surfaces remaining instances. Removal of the
  `view` widget's content-type selector is deferred until the bundle is dropped.
- Removal of the predecessor `post_list` / `event_list` / `directory` bundles
  and their embedded Views, and the cross-repo removal of the predecessor
  `atomic` theme templates, follow once the migrations are validated on staging
  (#1171).
