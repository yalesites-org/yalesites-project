# Views Block Authoring Form — UX/UI Audit & Design Spec

**Ticket:** YaleSites-Internal #1316 · **Implements into:** #1317 · **Epic:** #1161

> Status: design spec for developer hand-off. The product/design team must review
> and approve this before the #1317 implementation begins. Items marked **(needs
> design sign-off)** are explicit decision points.

## 1. Purpose

The per-content-type widgets introduced in #1163–#1167 (`PostViewWidget`,
`EventViewWidget`, `PageViewWidget`, `ProfileViewWidget`, all extending
`ViewsBasicWidgetBase`) already removed the biggest source of authoring
confusion — the content-type and display-mode selectors are gone, because the
block bundle now encodes both. This spec covers the **remaining** authoring-form
problems: too many ungrouped checkboxes, unclear labels, and no help text.

The goal is a form an editor can scan top-to-bottom and understand without
training. No query-layer or stored-JSON changes — this is presentation only.

## 2. Audit of the current form

The form is built by `ViewsBasicWidgetBase::formElement()` from these builder
methods. All controls live under the `group_user_selection` container in flat
sub-containers (`entity_and_view_mode`, `filter_and_sort`, `entity_specific`,
`options`). Fields by builder:

| Control | Machine name | Type | Built by | Applies to |
|---|---|---|---|---|
| Field Display Options | `field_options` | checkboxes (categories / tags / teaser image) | `buildFieldDisplayOptions` | all (teaser image: card + list only) |
| Post field options | `post_field_options` | checkboxes (show eyebrow) | `PostViewWidget` | post |
| Event field options | `event_field_options` | checkboxes (hide add-to-calendar) | `EventViewWidget` | event |
| Event Time Period | `event_time_period` | radios (future/past/all) | `EventViewWidget` | event |
| Exposed Filter Options | `exposed_filter_options` | checkboxes (search / category / custom vocab / audience / year) | `getExposedFilterOptions` | all (year: post only) |
| Category Filter Label | `category_filter_label` | textfield | `buildExposedFilterControls` | all |
| Filter by Category Parent Term | `category_included_terms` | select | `buildExposedFilterControls` | all |
| Filter by Custom Vocab Parent Term | `custom_vocab_included_terms` | select | `buildExposedFilterControls` | all |
| Include tags/categories | `terms_include` | multiselect | `buildTermIncludeExclude` | all |
| Exclude tags/categories | `terms_exclude` | multiselect | `buildTermIncludeExclude` | all |
| Match Content That Has | `term_operator` | radios (any / all) | `buildTermIncludeExclude` | all |
| Sorting by | `sort_by` | select | `buildSortControl` | all |
| Show pinned label | `pinned_to_top` | checkbox | `buildPinnedControls` | all |
| Label for pinned items | `pin_label` | textfield (conditional) | `buildPinnedControls` | all |
| Number of Items to Display | `display` | select (all/limit/pager) | `buildDisplayControls` | all |
| Items / Items per Page | `limit` | number | `buildDisplayControls` | all |
| Ignore Number of Results | `offset` | number | `buildDisplayControls` | all |
| Include this content in view | `show_current_entity` | checkbox | `buildDisplayControls` | all |

### Problems identified

1. **No visual structure.** ~15 controls render as one long column with no
   sectioning, so "what content" sits next to "how many items" with no cue.
2. **Unclear labels.** "Match Content That Has", "Ignore Number of Results",
   "Show pinned label", and "Include this content in view" are jargon; editors
   cannot predict their effect.
3. **No help text.** Only `category_filter_label`, `category_included_terms`,
   `offset`, and `pinned_to_top` have any `#description`. Most controls have none.
4. **Filter controls are split** across `entity_and_view_mode` (exposed filters,
   term selects) and `filter_and_sort` (include/exclude, operator, sort), so
   related settings are visually disconnected.
5. **Pinned label** appears only via `#states`, with no hint of what "pinned"
   means or how items get pinned.

## 3. Proposed grouping

Replace the flat sub-containers with four labelled, collapsible `details`
groups (reuse the `field_group` module already enabled on these form displays;
no new dependency). Proposed order and membership:

| Group (label) | Controls | Open by default |
|---|---|---|
| **Content & filters** | exposed filter options, category filter label, category/custom-vocab parent term, include/exclude terms, match operator | yes |
| **Sorting & pinned** | sort by, pinned toggle + label | yes |
| **Display options** | number to display, items, ignore-first-N, include current page | no |
| **Field display** | field display options (categories/tags/image), + per-type: eyebrow (post), hide add-to-calendar + time period (event) | yes |

Rationale: this maps to the editor's mental model — *which content* → *in what
order* → *how many* → *what each result shows*. "Field display" stays open
because it most affects the visual result and is the target of the #1318 live
preview.

## 4. Proposed label & help-text copy

**(needs design sign-off on final wording.)** Machine names do not change —
copy only.

| Control | New label | New help text |
|---|---|---|
| `term_operator` | Match content tagged with | "Any" shows content with at least one selected term; "All" requires every selected term. |
| `offset` | Skip the first N results | Hide the first results that match — e.g. enter 1 to omit the single newest item. |
| `pinned_to_top` | Highlight pinned items | Show a small label on items an editor has pinned to the top of this list. |
| `pin_label` | Pinned-item label | Text shown on each pinned item (for example "Featured"). |
| `show_current_entity` | Include the current page | When this block is placed on a content page, include that page in the results instead of excluding it. |
| `display` | How many items to show | Choose all matching items, a fixed number, or a paginated list. |
| `field_options` | Show on each result | (per option: "Category label", "Tags", "Teaser image") |
| `terms_include` | Only show content tagged | Limit results to content using any of these tags or categories. |
| `terms_exclude` | Hide content tagged | Remove results using any of these tags or categories. |

All help text is rendered with each field's `#description` (already supported);
no markup beyond the existing `<strong>` usage.

## 5. Visual pickers — evaluation

The content-type/display-mode visual picker (the old icon radios) is **already
retired** by the bundle split, so the remaining candidates are smaller:

- **Event time period** (future/past/all) already ships with icons in
  `EventViewWidget`; keep as an icon radio set — it is the one place a visual
  picker clearly helps.
- **Field display options**: a live mockup preview is a better fit than icons;
  that is its own ticket (#1318), which this grouping is designed to feed.
- Everything else (filters, sort, counts) is text/number data where a visual
  picker adds clutter, not clarity. **Recommendation: no new icon pickers.**

## 6. Accessibility notes for #1317

- `details` groups are natively keyboard-accessible; ensure each has a clear
  `#title`.
- Help text via `#description` is associated with the control by Drupal's Form
  API (`aria-describedby`) — preferred over standalone markup.
- The conditional `pin_label` (`#states` visible/required) must keep its
  required marker in sync; verify the required state is announced.
- Any new interactive pattern beyond `details`/`#description` must be flagged for
  the accessibility engineer (per #1317 acceptance criteria).

## 7. Out of scope

- No change to stored JSON, the query layer, `ViewsBasicManager`, or block
  bundles.
- The live mockup preview is #1318.
- The legacy `view` widget is untouched (retired in #1170).
