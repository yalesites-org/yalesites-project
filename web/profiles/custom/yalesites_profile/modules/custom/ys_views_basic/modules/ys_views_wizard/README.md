# ys_views_wizard

Collapses the per-(content type, display mode) listing tiles in the Layout Builder block
picker into a **single** entry — _Content Listing_ — which opens a two-question step,
_I Want To Show_ (content type) and _As_ (display mode), and hands off to the configure
form for the listing bundle that matches. With JavaScript the handoff happens inside the
same dialog; without it, it falls back to a plain redirect.

Implements yalesites-org/YaleSites-Internal#1586.

## Relationship to ys_views_basic

This is an **optional add-on** to `ys_views_basic`, and the dependency runs one way only:

- The wizard reads its options, labels and icons from `ViewsBasicManager` — the same source
  the authoring widget uses, so the step introduces no new terminology and no second copy
  of the content type / display mode map.
- The wizard reuses `ys_views_basic`'s card styling rather than restating it. It opts in to
  that stylesheet's spacing and type scale with the `views-basic--form-scale` class.
- `ys_views_basic` contains **no reference to this module**. Uninstalling
  `ys_views_wizard` restores the per-bundle tiles in the picker and leaves the authoring
  experience untouched. The `content_listings` block browser category is removed with it,
  via an enforced module dependency on the config entity.

If you change the card markup here, check the authoring widget too — both surfaces share
those CSS rules deliberately.

Card, selected-state and spacing rules all come from `ys_views_basic`'s stylesheet.
`assets/css/views-wizard.css` holds only what is true of the wizard's dialog and of
nothing else, so anything genuinely wizard-only belongs there rather than in
`ys_views_basic` — the `views-basic--wizard` class on the form's container is the hook to
target, and keeping these rules here means uninstalling the module removes them. Right
now that is two rules: the gap under the last question, and zeroing the dialog's own
bottom padding so gin_lb's actions bar reaches the dialog edge.

## How it works

| Step                             | Mechanism                                                                                                                                                                                                                                                                                                           |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Collapse the listing tiles       | `hook_layout_builder_browser_alter()`. The browser hands over `section_storage`, `delta` and `region`, which is exactly the wizard route's parameter set. No patch, no route override, no template.                                                                                                                 |
| Which tiles to collapse          | `ViewsWizardOptions::listingPluginIds()`, derived from `ViewsBasicManager::LISTING_BUNDLES`, so the set can never drift from the set of bundles the wizard can hand off to. `event_calendar` is correctly left alone — it is not a listing bundle.                                                                  |
| Where the single entry lives     | Its own `layout_builder_browser_blockcat` config entity, `content_listings`, shipped in `config/install`. The hook rebuilds the category render array from that entity and splices it in where the first listing tile used to be. Categories the hook empties are dropped, mirroring `BrowserController::browse()`. |
| Make the entry open in the modal | Handled in the same alter. `layout_builder_browser_link_alter()` whitelists only `layout_builder.choose_block` and `layout_builder.add_block`, so a custom route would otherwise open off-canvas from inside a modal.                                                                                               |
| Keep unsaved layout edits        | Route option `parameters.section_storage.layout_builder_tempstore: TRUE`, same as every core Layout Builder route.                                                                                                                                                                                                  |
| Region-aware options             | `ViewsWizardOptions` asks the block manager for the same filtered definition list the browser asks for, so `layout_builder_restrictions` applies for free and the step never offers a combination that cannot be placed in the region the editor is in.                                                             |
| Hand off (JS)                    | An AJAX submit callback builds core's `AddBlockForm` for the resolved bundle in-process and re-opens the same `#layout-builder-modal` dialog with it.                                                                                                                                                               |
| Hand off (no JS)                 | `::submitForm()` redirects to `layout_builder.add_block` for the resolved bundle, which renders the identical form as a full page load.                                                                                                                                                                             |

## Things that were not obvious and cost time

1. **The block browser's plugin filter is not core's.** `BrowserController::browse()`
   passes `list: inline_blocks` and `browse: TRUE` to
   `getFilteredDefinitions('layout_builder', …)`; `ChooseBlockController::build()` does
   not. Copy the browser's call, not core's, or the option list will not match what the
   picker offered.
2. **gin_lb will not style a custom route or a custom form.** It gates on a route-name
   regex `/^(layout_builder\.([^.]+\.)?)/` and on a hardcoded `$formIds` list. Without
   opting in, the icons render but the icon _cards_ do not, because every layout rule in
   `ys_views_basic/assets/css/views-basic.css` is written against gin_lb's `.glb-*` and
   `.fieldset__wrapper--group` DOM. The route half has an alter
   (`hook_gin_lb_is_layout_builder_route_alter()`); the form-id half has none, so this
   module **decorates the `gin_lb.context_validator` service**. Even then,
   `ThemeSuggestionsAlter::$routesWithSuggestions` stays hardcoded with no seam.
3. **`#wrapper_attributes` does not survive on a radios element under gin_lb.** Its
   composite-fieldset template drops them. `#attributes` is what reaches the DOM, and it
   propagates onto every child radio input — which is where the `:checked` and
   `:focus-visible` card styling has to key from anyway.
4. **The actions element must be a container, not `actions`.** An `actions` element
   renders `div.form-actions`, and core's `dialog.ajax.js` copies every button it finds
   there into the jQuery UI button pane, hiding the originals with an inline
   `display: none`. gin_lb ships `.glb-button { display: inline-block !important }`, which
   beats an inline style — so the original Continue stayed visible beside its own copy and
   the dialog showed two Continue buttons. (`Back` was an `a.button`, not a
   `.glb-button`, so it hid correctly, which is why only one of the two duplicated.)
   Rendering a plain container with gin_lb's `canvas-form__actions` class — exactly what
   gin_lb's own `FormAlter` does for the five Layout Builder form IDs it hardcodes — means
   there is no `.form-actions` for `dialog.ajax.js` to find.

   `Back` now **does** carry `glb-button`, so that it matches Continue's 48px/16px sizing
   instead of rendering as a 41px/14px plain `a.button`. That is only safe because of the
   container above: with no `.form-actions` in the form there is nothing for
   `dialog.ajax.js` to copy, so making `Back` a `.glb-button` cannot resurrect the
   duplicate-button bug. If the actions element is ever turned back into `actions`, both
   buttons will duplicate, not just Continue.
5. **gin_lb's canvas-form treatment has three parts, and the form has to apply all
   three.** `canvas-form` on the form escapes the dialog's own side padding with
   `margin-left/right: -20px`; gin_lb puts that 20px back on the two _inner_ parts,
   `canvas-form__settings` and `canvas-form__actions`, not on the form. Set only the
   actions half and the questions render 20px left of the buttons beneath them, 4px off
   the dialog's edge. `__settings` is also what makes the questions the scrolling region
   with the actions bar parked below, the way gin_lb's own Layout Builder forms behave.
6. **`FormBuilder` derives `#action` from the current request.** Built in-process from the
   wizard's AJAX callback that is the _wizard's_ route, so `#action` has to be set
   explicitly to the `layout_builder.add_block` URL or the embedded form posts back to a
   route that builds a different form.
7. **`$form_state->setRebuild(TRUE)` is required on the AJAX path.** Without it a plain
   `FormBase` issues a redirect to the current URL after submission, and Drupal returns
   that redirect instead of ever invoking the `#ajax` callback — the POST comes back 200
   and the dialog silently does nothing.

## A note for anyone writing tests against this form

Core binds a submit button's AJAX to `mousedown`, not `click`. A synthetic
`element.click()` will not trigger Continue. Drive it with a real mouse click.

## Why the embedded form's own AJAX is not a problem

The embedded `AddBlockForm`'s `#ajax` URLs are derived from the request the same way
`#action` is, which would point them back at the wizard route. In practice the listing
bundles' configure forms have zero `#ajax` bindings and zero `use-ajax` links — the
per-bundle widgets dropped the content-type / display-mode radios that used to carry them,
because the bundle now encodes both answers. The Linkit autocompletes that remain use an
absolute `data-autocomplete-path`, which does not depend on the current request.

If a future listing widget reintroduces an `#ajax` element, this is the assumption that
breaks first.

## Removing it

`lando drush pmu ys_views_wizard -y`, then remove it from `core.extension.yml` and delete
the directory. The picker returns to one tile per listing bundle.
