# YaleSites Media

Site media for a YaleSite: favicons, the site name image, and image derivative handling.

## Overview

This module was extracted from `ys_core` in Phase 1 of
[yalesites-org/YaleSites-Internal#579](https://github.com/yalesites-org/YaleSites-Internal/issues/579).
It is a **behavior-preserving** relocation — nothing here is new functionality.

## What lives here

- **`YaleSitesMediaManager`** (`ys_media.media_manager`) — builds the favicon markup for the page
  head, handles the create/delete lifecycle of the managed files behind the Site Settings and
  Header Settings media pickers, and renders the uploaded site-name SVG with the site name
  injected as its `<title>`.
- **`ys_media_page_attachments()`** — attaches the favicons to every page.
- **`ys_media_imagemagick_arguments_alter()`** — preserves PNG transparency across image style
  operations.
- **`images/favicons/`** — the four fallback favicons used when a site has not uploaded its own.

## Boundaries

- **Config stays in `ys_core`.** `YaleSitesMediaManager` reads `ys_core.site` (for
  `custom_favicon`) and `system.site` (for the site name). Hard constraint 1 on issue #579 keeps
  config object names unchanged, so this module deliberately reads a `ys_core.*` config object
  rather than owning its own. The forms that *write* those values —
  `SiteSettingsForm` and `HeaderSettingsForm` — also stay in `ys_core`.
- **`ys_core` depends on `ys_media`, not the other way round.** Three consumers stayed behind in
  `ys_core` (`CoreTwigExtension`, `SiteSettingsForm`, `HeaderSettingsForm`), so `ys_core.info.yml`
  declares this module.
- **Not to be confused with `ys_file_management`.** That module owns *media entity* deletion —
  the "also delete the associated file?" flow and its File Manager role gating. This module owns
  *sitewide branding media* configured through the settings forms. The two scopes do not overlap
  and should stay separate.
- **`ys_core_preprocess_image_widget()` stayed in `ys_core`** — it exists to render previews for
  the Site Settings and Header Settings forms, which stayed there too.

## Backwards compatibility

The pre-extraction service id `ys_core.media_manager` is kept as an alias of
`ys_media.media_manager` (hard constraint 3 on issue #579). Prefer the new id in new code.

## The favicon asset paths are hardcoded

`getFavicons()` emits root-relative hrefs into the page head as literal strings, e.g.
`/profiles/custom/yalesites_profile/modules/custom/ys_media/images/favicons/favicon.ico`. One
other place hardcodes the same directory and must be changed in lockstep if it ever moves again:
`web/themes/custom/ys_admin_theme/templates/content-edit/file-managed-file--favicon.html.twig`,
which renders the fallback preview in the Site Settings form.

`YaleSitesMediaManagerTest` asserts the four hrefs and separately asserts each resolves to a real
file on disk, so a move that forgets to repoint **this module's** strings fails loudly. **It does
not cover the twig template** — nothing reads that file in a test, so a move that repoints the PHP
and forgets the template leaves two silently broken images in the Site Settings form with a green
suite. Check the template by hand.

## Dependencies

`imagemagick` is declared here because the favicon pipeline genuinely requires it: the
`favicon_16x16_ico` image style converts to `.ico`, which the GD toolkit cannot produce. It was
enabled platform-wide but declared by no module before this one.

## Tests

- `tests/src/Unit/YaleSitesMediaManagerTest.php` — the Phase 0 characterization tests for the
  moved class, relocated unchanged apart from the namespace and the relocated asset paths.
- `tests/src/Unit/YsMediaModuleRegistrationTest.php` — guards the `core.extension.yml` entry that
  enables this module on existing sites.
- `tests/src/Kernel/YsMediaExtractionTest.php` — pins the backwards-compatible `ys_core.media_manager`
  alias, and that it is a real alias rather than a duplicate definition.

Run them from the repository root:

```
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon?module=mysql \
  SIMPLETEST_BASE_URL=http://appserver \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_media/tests/ --testdox"
```
