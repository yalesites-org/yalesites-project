# Views Block Rework — QA Matrix

**Ticket:** YaleSites-Internal #1171 · **Epic:** #1161

This is the QA regression checklist for the rework. Items marked **[auto]** are
covered by PHPUnit (run `lando phpunit <module>/tests/`); items marked
**[manual]** require a running site and a second team member's sign-off, per the
acceptance criteria.

## Automated coverage (PHPUnit)

| Test | Covers |
|---|---|
| `Unit/ListingBundleDefinitionTest` | The 13-bundle definition, (type, view_mode, thumbnail, card-size) resolution, throw-on-unknown, the `(type, view_mode)` migration mapping, and the predecessor presets. |
| `Unit/PostViewWidgetTest` | Post content type, bundle-driven view mode, the post-only year filter, the eyebrow option (no #states), stored `post_field_options`, and the detail-group form sectioning. |
| `Unit/EventViewWidgetTest` | Event content type, excluded year filter, event field options + time period (no #states), stored `event_field_options` + `filters.event_time_period`. |
| `Unit/PageViewWidgetTest` | Page content type, bundle-driven view modes, no entity-specific controls, excluded year filter. |
| `Unit/ProfileViewWidgetTest` | Profile content type, "Show Affiliations" label, affiliation vocabulary, directory mode with disabled thumbnail, and the department/email/phone/pronouns options (#1648), which are offered on the card and list bundles only (not directory or condensed), plus their stored `profile_field_options`. |
| `Unit/CardSizeTest` | The shared "Card size" dial (#1648): offered on every content type's card grid with large/small options defaulting to large, absent from list/condensed/directory, the declarative `supports_card_size` capability, and `normalizeCardSize()` (including the superseded numeric cards-per-row values). |
| `Kernel/CardSizeMigrationTest` | `ys_views_basic_deploy_10003()` (#1648): converts stored `cards_per_row` 3/4 to `card_size` large/small across every bundle holding a `views_basic_params` field (enumerated by field type, so the legacy `view` bundle is included), preserves the rest of the params blob, saves in place without a new revision (Layout Builder references a specific block revision), skips an undecodable blob with a warning, re-saves nothing that has no stored value, and is idempotent. |
| `Unit/ViewArgumentOrderTest` | The scaffold view argument order (#1648): the pinned list, one distinct index per name, throw-on-unknown, and that the params JSON is not the final argument (so it can never be recovered with `end($args)` again). |
| `Unit/RenderIsolationTest` | Per-instance view cloning (#906), events scaffold selection, NULL on missing scaffold, deterministic pager element ids, the `show_current_entity` fall-through regression. |
| `Kernel/ViewMigrationTest` | The `view` → bundle swap per (type, mode), field-table bundle patch, unmappable-skip, idempotency, and the predecessor migration swap + param pre-fill. |

Run: `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/tests/`
(Kernel tests need `SIMPLETEST_DB`). Current status: 32 tests, all passing.

## Per-block × view-mode matrix — [manual] in Layout Builder

For each block type, place it on a page and verify the listed settings render
and behave correctly:

| Block types | Key settings to verify |
|---|---|
| `post_card` / `post_list_item` / `post_condensed` | show eyebrow; show thumbnail (card + list only); show year filter; terms include/exclude; sort by publish date; pinned to top |
| `event_card` / `event_list_item` / `event_condensed` | event time period (future/past/all); hide add-to-calendar; sort by event date; category/audience filters; events use the distinct-de-duplicated events scaffold |
| `page_card` / `page_list_item` / `page_condensed` | category filter uses `page_category` (`field_category_target_id_1`); sort by title |
| `profile_card` / `profile_list_item` / `profile_directory` / `profile_condensed` | "Affiliations" label; affiliation filter; sort by last name; directory renders |

Also verify the shared controls on each block: terms include/exclude, term
operator, sort, display/limit/offset, include current page, pinned + pin label,
and the exposed filters (search, category, custom vocab, audience).

## Placement restrictions — [manual]

Against the #1168 matrix:
- Card Grid and Condensed available in every region (full width, 70%, 30%
  sidebar, 50/50, 33/33/33).
- List and Directory blocked from 30% sidebar, 50/50, and 33/33/33.
- Calendar remains full-width only.
- Confirm the full-width (`layout_onecol`) product decision noted in
  `placement-restrictions.md`.

## Multi-block isolation (#906 / #1306) — [manual]

- Place two listing blocks with different settings on one page; confirm each
  keeps its own sort, limit, filters, and pinned state (no clobbering).
- Place two paginated blocks; confirm they paginate independently (distinct
  `?page=` behaviour).
- Confirm a single block and the event calendar block still render correctly.

## Migration validation (#1169 / #1170) — [manual, staging]

- On a copy of production data, run `drush deploy` and diff rendered output of
  affected pages before/after — there must be no visual change for migrated
  `view` blocks.
- Confirm the deploy log shows zero remaining `view` blocks and zero
  `inline_block:view` references, and re-running the hook is a no-op
  (idempotent).
- Confirm predecessor `post_list` / `event_list` / `directory` instances render
  equivalently through their new bundles.

## Cross-cutting — [manual]

- Mobile and desktop viewports.
- Edge cases: empty result set, single result, max items, pager boundaries.
- Second team-member sign-off.
