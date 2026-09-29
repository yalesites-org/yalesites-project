# Views Block Rework — QA Matrix

**Ticket:** YaleSites-Internal #1171 · **Epic:** #1161

This is the QA regression checklist for the rework. Items marked **[auto]** are
covered by PHPUnit (run `lando phpunit <module>/tests/`); items marked
**[manual]** require a running site and a second team member's sign-off, per the
acceptance criteria.

## Automated coverage (PHPUnit)

| Test | Covers |
|---|---|
| `Unit/ListingBundleDefinitionTest` | The 12-bundle definition, (type, view_mode, thumbnail, card-size) resolution, throw-on-unknown, the `(type, view_mode)` migration mapping (profile directory to `profile_card`), the predecessor presets, and `directoryToCardParams()`. |
| `Unit/PostViewWidgetTest` | Post content type, bundle-driven view mode, the post-only year filter, the eyebrow option (no #states), stored `post_field_options`, and the detail-group form sectioning. |
| `Unit/EventViewWidgetTest` | Event content type, excluded year filter, event field options + time period (no #states), stored `event_field_options` + `filters.event_time_period`. |
| `Unit/PageViewWidgetTest` | Page content type, bundle-driven view modes, no entity-specific controls, excluded year filter. |
| `Unit/ProfileViewWidgetTest` | Profile content type, "Show Affiliations" label, affiliation vocabulary, and the department/email/phone/pronouns options (#1648), which are offered on the card and list bundles only (not condensed), plus their stored `profile_field_options`. |
| `Unit/CardSizeTest` | The shared "Card size" dial (#1648): offered on every content type's card grid with large/small options defaulting to large, absent from list/condensed, the declarative `supports_card_size` capability, and `normalizeCardSize()` falling back to large for any value outside the offered set. |
| `Unit/ViewArgumentOrderTest` | The scaffold view argument order (#1648): the pinned list, one distinct index per name, throw-on-unknown, and that the params JSON is not the final argument (so it can never be recovered with `end($args)` again). |
| `Unit/RenderIsolationTest` | Per-instance view cloning (#906), events scaffold selection, NULL on missing scaffold, deterministic pager element ids, the `show_current_entity` fall-through regression. |
| `Kernel/ViewMigrationTest` | The `view` → bundle swap per (type, mode), field-table bundle patch, unmappable-skip, idempotency, the predecessor migration swap + param pre-fill, and the `profile_directory` → `profile_card` post-update (params, field-table bundles, idempotency, zero instances, placement rewrite). |

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
| `profile_card` / `profile_list_item` / `profile_condensed` | "Affiliations" label; affiliation filter; sort by last name |

Also verify the shared controls on each block: terms include/exclude, term
operator, sort, display/limit/offset, include current page, pinned + pin label,
and the exposed filters (search, category, custom vocab, audience).

## Placement restrictions — [manual]

Against the #1168 matrix:
- Card Grid and Condensed available in every region (full width, 70%, 30%
  sidebar, 50/50, 33/33/33).
- List blocked from 30% sidebar, 50/50, and 33/33/33.
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

## Profile directory retirement (#1682) — [manual, staging]

- Before deploy, note pages with a `profile_directory` block. After
  `drush deploy`, each is a `profile_card` block with small cards and
  department, email and phone shown, and its other settings unchanged.
- Confirm the updatedb log shows zero remaining `profile_directory` blocks and
  zero `inline_block:profile_directory` references.
- Confirm "Directory" is no longer offered in the block picker or the wizard.

## Cross-cutting — [manual]

- Mobile and desktop viewports.
- Edge cases: empty result set, single result, max items, pager boundaries.
- Second team-member sign-off.
