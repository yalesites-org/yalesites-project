# ys_views_wizard_spike — prototype B (THROWAWAY)

Spike for **yalesites-org/YaleSites-Internal#1586**, Phase 2 approach **B: AJAX form swap
in place**. Do **not** merge this. Branch: `spike/views-wizard-ajax`.

Identical to prototype A (`spike/views-wizard-redirect`) except for the handoff, so the two
can be compared on that and nothing else.

## What it does

Replaces the single `View` tile in the Layout Builder block browser with a two-question
step — _I Want To Show_ (content type) and _As_ (display mode) — and then hands off to
Layout Builder's own `layout_builder.add_block` route for the block that matches.

## How the handoff works

| Step                            | Mechanism                                                                                                                                                                                                                                               |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Retarget the tile               | `hook_layout_builder_browser_alter()`. The browser hands over `section_storage`, `delta`, `region`, which is exactly the wizard route's parameter set. No patch, no route override, no template.                                                        |
| Make the tile open in the modal | Our own `hook_link_alter`-equivalent inside that same alter. `layout_builder_browser_link_alter()` whitelists only `layout_builder.choose_block` and `layout_builder.add_block`, so a custom route would otherwise open off-canvas from inside a modal. |
| Keep unsaved layout edits       | Route option `parameters.section_storage.layout_builder_tempstore: TRUE`, same as every core Layout Builder route.                                                                                                                                      |
| Region-aware options            | `ViewsWizardOptions` asks the block manager for the same filtered definition list the browser asks for, so `layout_builder_restrictions` applies for free.                                                                                              |
| Hand off                        | `$form_state->setRedirect('layout_builder.add_block', …)`.                                                                                                                                                                                              |
| Carry the answers               | Only needed until #1164–#1167 exist. `?ys_wizard_seed=<type>:<view_mode>` plus a `hook_form_alter()` that writes `field_view_params` on the `#block` entity.                                                                                            |

## Three things that were not obvious and cost time

1. **The block browser's plugin filter is not core's.** `BrowserController::browse()`
   passes `list: inline_blocks` and `browse: TRUE` to
   `getFilteredDefinitions('layout_builder', …)`; `ChooseBlockController::build()` does
   not. Measured on node 1 / delta 2 / region `content`: core's argument set returns **9**
   definitions and omits `inline_block:view` entirely; the browser's returns **46** and
   includes it. Copy the browser's call, not core's.
2. **You cannot seed the configure form from `hook_form_alter()`.** At that point
   `$form['settings']['block_form']` holds only `#type`, `#process`, `#block`, `#access` —
   the widget's radios are built later by that `#process` callback. Seed
   `$form['settings']['block_form']['#block']` (the BlockContent entity) instead. That is
   also the only seeding point that gets the _display mode option list_ right, because
   `ViewsBasicDefaultWidget` derives which display modes it offers from the stored params.
3. **`gin_lb` will not style a custom route or a custom form.** It gates on a route-name
   regex `/^(layout_builder\.([^.]+\.)?)/` and on a hardcoded `$formIds` list. Without
   opting in, the icons render but the icon _cards_ do not, because every layout rule in
   `ys_views_basic/assets/css/views-basic.css` is written against gin_lb's `.glb-*` and
   `.fieldset__wrapper--group` DOM. The route half has an alter
   (`hook_gin_lb_is_layout_builder_route_alter()`); the form-id half has none, so this
   spike **decorates the `gin_lb.context_validator` service**. Even then,
   `ThemeSuggestionsAlter::$routesWithSuggestions` stays hardcoded with no seam.

## Known limitation this prototype exposes

The retargeted tile inherits `inline_block:view`'s own placement allowlist. On this site
`inline_block:view` is allowlisted **nowhere**, so it is reachable only in layouts with no
per-region allowlist at all (`layout_onecol`). The wizard is therefore invisible in the
70/30 content region, the 30% sidebar, the 50/50 and the 33/33/33 — precisely the regions
#1168's approved table wants Card Grid and Condensed to be available in. A real
implementation must register the picker entry independently of the legacy plugin and gate
it on "does any target bundle resolve as placeable here".

## Removing it

`lando drush pmu ys_views_wizard_spike -y` then delete the directory.

## Extra trap specific to this approach

`FormBuilder` defaults `#action` to the current request URI. Built in-process from the
wizard's AJAX callback, that is the **wizard's** route, so the embedded configure form would
POST back to a route that builds a different form. `#action` has to be set explicitly to the
`layout_builder.add_block` URL.
