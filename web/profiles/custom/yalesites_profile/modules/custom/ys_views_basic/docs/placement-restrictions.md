# Listing Block Placement & Picker Grouping

**Ticket:** YaleSites-Internal #1168 · **Epic:** #1161

## Block picker grouping

The Layout Builder Browser groups the 14 listing blocks into four categories
(`layout_builder_browser_blockcat`):

| Category | Blocks |
|---|---|
| Post Listings | Posts — Card Grid / List / Condensed |
| Event Listings | Events — Card Grid / List / Condensed / Calendar |
| People Listings | People — Card Grid / List / Directory / Condensed |
| Page Listings | Pages — Card Grid / List / Condensed |

## Placement matrix (product-approved)

| Display mode | Full width | 70% column | 30% sidebar | 50/50 | 33/33/33 |
|---|---|---|---|---|---|
| Card Grid | ✅ | ✅ | ✅ | ✅ | ✅ |
| List | ✅ | ✅ | ❌ | ❌ | ❌ |
| Condensed | ✅ | ✅ | ✅ | ✅ | ✅ |
| Directory (profiles) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Calendar (events) | ✅ | ❌ | ❌ | ❌ | ❌ |

Implemented via the `entity_view_mode_restriction_by_region` plugin in each
Layout-Builder-enabled `core.entity_view_display.node.*` config. Region mapping:

- `ys_layout_two_column` → `content` (70%, wide) and `sidebar` (30%, narrow)
- `ys_layout_two_column_50_50` → `all_regions` (narrow)
- `ys_layout_three_column_33_33_33` → `all_regions` (narrow)

The new bundles were added to the **existing** region allowlists, mirroring how
the predecessor listing blocks (`event_list` / `post_list` / `directory`) are
already placed: all display modes in the wide `content` region; card + condensed
only in the narrow regions. The change is additive — no previously-allowed block
was removed, and no previously-unrestricted region was newly restricted.

Displays updated: `node.page.default`, `node.page.single`, `node.post.default`,
`node.profile.default`, `node.profile.single`.

## Known gaps — handled by the #1171 QA pass

- **Full-width (`layout_onecol`) and banner regions** were intentionally left
  untouched. On the audited displays `layout_onecol` only allowlists a taxonomy
  block (not inline blocks), and the banner region is reserved for hero/banner
  blocks; the predecessor listing blocks are not present in either. Whether the
  new listing blocks should be explicitly allowlisted in full-width
  `layout_onecol` is a product decision to confirm during QA.
- **Calendar full-width-only** is unchanged from the existing `event_calendar`
  behaviour.
- **Manual Layout Builder verification** of every mode × region combination
  (the AC's QA checklist) is required and is tracked in #1171; the automated
  guarantee here is only that the config exports/imports cleanly (`npm run
  confex` round-trip is clean) and that the matrix was applied to the wide vs.
  narrow regions as verified at build time.
