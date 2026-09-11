# Changelog

## Views Block Architectural Rework (EPIC #1161)

Refactored the single monolithic `view` block into a layered, per-content-type
architecture.

### Added

- Abstract `ViewsBasicWidgetBase` "Views Core" layer holding all shared form
  logic, with a bundle-keyed definition (`ViewsBasicManager::LISTING_BUNDLES`)
  mapping each bundle to its (content type, display mode) pair (#1163).
- Per-content-type widgets — `PostViewWidget`, `EventViewWidget`,
  `PageViewWidget`, `ProfileViewWidget` — and 13 listing block content types:
  `post_card` / `post_list_item` / `post_condensed`, the matching `event_*`,
  `page_*`, and `profile_*` plus the profile-only `profile_directory`
  (#1164–#1167). The existing `event_calendar` block is unchanged.
- Block-picker grouping into Post / Event / People / Page Listings categories,
  and per-display-mode Layout Builder placement restrictions: card and
  condensed everywhere; list and directory in wide regions only (#1168).
- Authoring-form UX: grouped detail sections, clearer labels, and help text
  (#1316/#1317).

### Changed

- Each Views block now renders in isolation: `ViewsBasicManager::initView()`
  clones the scaffold view per instance and pagers get a per-block element id,
  fixing cross-block setting clobbering (#906/#1306).
- The legacy `view` block and the predecessor `post_list` / `event_list` /
  `directory` blocks are removed from the Layout Builder picker (#1170).

### Migration

- `ys_views_basic_deploy_10001()` migrates existing `view` blocks in place to
  their `{type}_{view_mode}` bundle and rewrites Layout Builder placements
  across all revisions (#1169).
- `ys_views_basic_deploy_10002()` supersedes `post_list` / `event_list` /
  `directory` instances, converting them to the equivalent new bundles with
  pre-filled params (#1170).

### Deprecated / follow-up

- The legacy `view` bundle is kept in config so any unconverted instance still
  renders; a status-report warning surfaces remaining instances. Removal of the
  `view` widget's content-type selector is deferred until the bundle is dropped.
- Removal of the predecessor `post_list` / `event_list` / `directory` bundles
  and their embedded Views, and the cross-repo removal of the predecessor
  `atomic` theme templates, follow once the migrations are validated on staging
  (#1171).
