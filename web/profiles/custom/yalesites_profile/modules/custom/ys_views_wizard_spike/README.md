# ys_views_wizard_spike — prototype B (THROWAWAY)

Spike for **yalesites-org/YaleSites-Internal#1586**, Phase 2 approach **B: AJAX form swap
in place**. Do **not** merge this. Branch: `1586-views-wizard-ajax`, based on
`1318-views-rework` (PR #1299).

Identical to prototype A (`1586-views-wizard-redirect`) except for the handoff, so the
two can be compared on that and nothing else.

## What it does

Collapses the **thirteen** listing tiles that the views rework adds to the Layout Builder
block browser into a **single** entry — _Content Listing_ — which opens a two-question
step, _I Want To Show_ (content type) and _As_ (display mode), and then hands off to
Layout Builder's own `layout_builder.add_block` route for the real bundle that matches.

## What changed versus the original develop-based spike

| Original spike (on `develop`)                                                                                 | This port (on `1318-views-rework`)                                                                                                            |
| ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Retargeted the single `inline_block:view` tile.                                                               | That tile no longer exists — `layout_builder_browser.layout_builder_browser_block.view` is **deleted** on this branch. The hook collapses the thirteen per-bundle tiles instead. |
| Every combination resolved to the legacy `view` bundle, so answers had to be carried as a `?ys_wizard_seed` query parameter and written onto the `#block` entity from `hook_form_alter()`. | All thirteen bundles exist. The wizard hands off to the **real** bundle (`inline_block:post_card`, `inline_block:event_condensed`, …). The seed query parameter, the `hook_form_alter()` seeder, `LEGACY_BUNDLE` and `needsSeed()` are all **deleted** — they are unreachable here. |
| Bundle id built by `{content_type}_{view_mode}` string convention.                                            | Read from `ViewsBasicManager::LISTING_BUNDLES`, which this branch publishes as the single source of truth for that mapping (ADR DR-2/DR-4).   |
| Wizard was invisible outside `layout_onecol`, because `inline_block:view` was allowlisted nowhere.            | **Resolved.** The thirteen bundles are allowlisted per region in `core.entity_view_display.node.page.default`, so the wizard appears wherever any listing bundle is placeable — 70/30 content and sidebar, 50/50, 33/33/33. |

`ViewsBasicManager::entityTypeList()` and `::viewModeList($content_type)` are **unchanged**
between `develop` and `1318-views-rework` — same signatures, same `ALLOWED_ENTITIES`
source. No adaptation was needed there.

## How the handoff works

| Step                            | Mechanism                                                                                                                                                                                                                                               |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Collapse the thirteen tiles     | `hook_layout_builder_browser_alter()`. The browser hands over `section_storage`, `delta`, `region`, which is exactly the wizard route's parameter set. No patch, no route override, no template, and no config change. |
| Which tiles to collapse         | `ViewsWizardOptions::listingPluginIds()`, derived from `ViewsBasicManager::LISTING_BUNDLES`, so the set can never drift from the set of bundles the wizard can hand off to. `event_calendar` is correctly left alone — it is not a listing bundle. |
| Empty categories                | The three listing categories the hook empties are unset, mirroring what `BrowserController::browse()` does for a category with no links. `event_listings` survives because Event Calendar stays in it. |
| Make the entry open in the modal | Our own `hook_link_alter`-equivalent inside that same alter. `layout_builder_browser_link_alter()` whitelists only `layout_builder.choose_block` and `layout_builder.add_block`, so a custom route would otherwise open off-canvas from inside a modal. |
| Keep unsaved layout edits       | Route option `parameters.section_storage.layout_builder_tempstore: TRUE`, same as every core Layout Builder route.                                                                                                                                       |
| Region-aware options            | `ViewsWizardOptions` asks the block manager for the same filtered definition list the browser asks for, so `layout_builder_restrictions` applies for free.                                                                                              |
| Hand off                        | An AJAX submit callback builds core's `AddBlockForm` for the resolved bundle in-process and re-opens the SAME `#layout-builder-modal` dialog with it, so the editor never leaves the dialog.                                                             |

## Three things that were not obvious and cost time

1. **The block browser's plugin filter is not core's.** `BrowserController::browse()`
   passes `list: inline_blocks` and `browse: TRUE` to
   `getFilteredDefinitions('layout_builder', …)`; `ChooseBlockController::build()` does
   not. Copy the browser's call, not core's, or the wizard's option list will not match
   what the picker offered.
2. **`gin_lb` will not style a custom route or a custom form.** It gates on a route-name
   regex `/^(layout_builder\.([^.]+\.)?)/` and on a hardcoded `$formIds` list. Without
   opting in, the icons render but the icon _cards_ do not, because every layout rule in
   `ys_views_basic/assets/css/views-basic.css` is written against gin_lb's `.glb-*` and
   `.fieldset__wrapper--group` DOM. The route half has an alter
   (`hook_gin_lb_is_layout_builder_route_alter()`); the form-id half has none, so this
   spike **decorates the `gin_lb.context_validator` service**. Even then,
   `ThemeSuggestionsAlter::$routesWithSuggestions` stays hardcoded with no seam.
3. **The category that hosts the single entry has to be relabelled.** The entry is built
   by reusing the first listing tile so it inherits the browser's markup, image fallback
   and `#open` state — but that leaves it sitting under "Post Views", which is wrong for
   an entry covering all four content types.

## The develop-era flaw that no longer applies

`FormBuilder` defaults `#action` to the current request URI. Built in-process from the
wizard's AJAX callback that is the **wizard's** route, so the embedded configure form would
POST back to a route that builds a different form. `#action` still has to be set explicitly
to the `layout_builder.add_block` URL — that part is unchanged.

The develop-era spike recorded a second, fatal half: the embedded form's own `#ajax` URLs
are derived from the request in the same way, so every AJAX element inside `AddBlockForm`
would call back to `/ys-views-wizard/choose/...` and fail. **On this base that no longer
bites.** Measured on `1318-views-rework`, `drupalSettings.ajax` is empty for the standalone
configure form of `event_condensed`, `post_card`, `profile_directory` and `page_list_item` —
zero `#ajax` bindings and zero `use-ajax` links. The rework's per-bundle widgets dropped the
content-type / display-mode radios that carried the develop-era `#ajax`, because the bundle
now answers both questions. The three Linkit autocompletes that remain use
`data-autocomplete-path="/linkit/autocomplete/default"`, an absolute route that does not
depend on the current request.

So the embedded form was driven end to end in the dialog on this base: tab switching,
filling the heading, and **Add block** all worked, the dialog closed itself, and the block
saved into `overrides/node.54` delta 2 region `sidebar` as `inline_block:event_condensed`.

Prototype A leaves the modal but embeds nothing, and is on `1586-views-wizard-redirect`.

## Removing it

`lando drush pmu ys_views_wizard_spike -y` then delete the directory.
