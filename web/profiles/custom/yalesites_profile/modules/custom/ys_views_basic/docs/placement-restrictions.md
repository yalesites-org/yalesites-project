# Listing Block Placement & Picker Grouping

**Ticket:** YaleSites-Internal #1168 · **Epic:** #1161

## Block picker grouping

The Layout Builder Browser groups the 17 listing blocks into five categories
(`layout_builder_browser_blockcat`):

| Category | Blocks |
|---|---|
| Post Listings | Posts — Card Grid / List / Condensed |
| Event Listings | Events — Card Grid / List / Condensed / Calendar |
| People Listings | People — Card Grid / List / Condensed |
| Page Listings | Pages — Card Grid / List / Condensed |
| Resource Views | Resources — Card Grid / Portrait Grid / List / Condensed (#1723) |

## Placement matrix (product-approved)

| Display mode | Full width | 70% column | 30% sidebar | 50/50 | 33/33/33 |
|---|---|---|---|---|---|
| Card Grid | ✅ | ✅ | ✅ | ✅ | ✅ |
| Portrait Grid (resources) | ✅ | ✅ | ✅ | ✅ | ✅ |
| List | ✅ | ✅ | ❌ | ❌ | ❌ |
| Condensed | ✅ | ✅ | ✅ | ✅ | ✅ |
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

## Decisions and checks from the #1171 QA pass

- **Full-width sections allow every listing display.** Decided Oct 16 in the
  #1171 QA pass: no listing display is restricted in a full-width section, which
  matches the Full width column of the matrix above. The banner region stays
  reserved for hero/banner blocks.
- **Calendar full-width-only** is unchanged from the existing `event_calendar`
  behaviour.
- **Manual Layout Builder verification** of every mode × region combination
  (the AC's QA checklist) is required and is tracked in #1171; the automated
  guarantee here is only that the config exports/imports cleanly (`npm run
  confex` round-trip is clean) and that the matrix was applied to the wide vs.
  narrow regions as verified at build time.
