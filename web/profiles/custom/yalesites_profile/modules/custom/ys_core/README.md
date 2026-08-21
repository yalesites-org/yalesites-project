# YaleSites Core

## Description
The YaleSites Core Functionality module serves as the central repository for organizing custom functionality that is fundamental to the YaleSites platform. Within this module, you'll find templates, assets, configuration overrides, and custom code that are vital for the seamless operation of all sites hosted on the platform.

However, before adding new features or functionality to this module, platform developers are encouraged to contemplate the creation of separate custom modules. These custom modules can encapsulate specific sets of features, enhancing modularity and ensuring that the codebase remains clean and organized. This approach promotes a more efficient and maintainable development process for the YaleSites platform.

## Features
- **Sitewide Elements**: This category covers a wide array of elements including plugins, forms, templates, and various assets used for managing sitewide components such as the site header, footer, and breadcrumbs. These elements may also extend into the styling realm within the Atomic theme and the component library.
- **Install Configuration**: The module houses default values for YaleSites-specific configuration files used during the creation of new sites on the platform. While technically not mandatory, maintaining these install files is considered a best practice, as they ensure consistency and serve as a reference point for values that should ideally reside in the profile's config/sync directory.
- **Hooks and Custom Functionality**: It provides a growing list of hooks for adding and altering form elements, tokens, caching rules, and website behavior. These hooks empower developers to customize and fine-tune the platform's behavior to meet specific requirements.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_core/tests --testdox"
```

The Unit tests need no database and run in under a second, so for a quick check
point the shorthand at the `Unit` directory specifically:

```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_core/tests/src/Unit
```

Passing the whole `tests` directory to that shorthand also discovers `Kernel/`,
which errors without `SIMPLETEST_DB` — use the full command above for those.

Note that **CI does not run PHPUnit at all**: `.ci/test/static/run` calls
`composer unit-test`, which is currently a stub. These tests only run when
someone runs them locally.

## Known test coverage gaps

Recorded here so they are visible to anyone working in this module, particularly
during the staged cleanup tracked in yalesites-org/YaleSites-Internal#579.

- **No config schema for this module's settings objects.** `config/schema/ys_core.schema.yml`
  covers only `ys_core.dashboard_settings`. `ys_core.site`, `ys_core.header_settings`,
  `ys_core.footer_settings`, and `ys_core.social_links` have none, so a kernel test
  that installs this module's config fails with `SchemaIncompleteException` under
  PHPUnit's default strict schema checking. That blocks kernel-level characterization
  of every service that reads these objects, so the tests here work around it by
  exercising only code paths that need no config save. Adding the schema is not
  test-only work: `environment_indicator.show` ships as boolean `true` in
  `config/install` but `SiteSettingsForm` saves integer `1`, and `custom_favicon` /
  `site_name_image` are declared as `''` but hold arrays of file IDs, so no schema
  type validates both the install defaults and real saved values without also
  correcting those.
- **`getFavicons()` custom-favicon branch is only partly pinned.** The test for it
  stubs a single image style for all four sizes, so it cannot show that each size
  resolves to its own distinct URL. Verifying that needs a real image style and a
  saved `custom_favicon` value, so it is blocked on the missing schema above. The
  fallback branch is covered against the real filesystem and the shipped image
  style config in `YaleSitesMediaManagerTest`. That method and its test now live in
  the `ys_media` module (Phase 1 of #579), but the blocker is recorded here because
  the schema that blocks it belongs to `ys_core`.
- **`CoreTwigExtension::getAssetPath()`'s `_yale-packages` fallback is uncovered.**
  A normal checkout has an asset manifest under the theme's `node_modules`, so the
  first path always wins; the fallback only runs where the theme's npm assets are
  absent but `_yale-packages` is populated.
- **`CoreTwigExtension::getUrlType()` can never return `'mailto'`.** It checks
  `isInternal()` first, and that treats any URL with an empty `parse_url()` host as
  internal, which every `mailto:` URL is. The private `isMailTo()` method is dead
  code. Both the current behavior and the intended behavior are recorded as a
  characterization/skipped test pair in `CoreTwigExtensionTest`; fixing the ordering
  is a behavior change and is out of scope for a behavior-preserving refactor.

## Known configuration inconsistencies

- **`bluesky` is missing from the shipped social links defaults.** `SocialLinksManager::SITES`
  offers it, but `config/install/ys_core.social_links.yml` does not list it, so a
  freshly installed site has no `bluesky` key until an editor saves the Footer
  Settings form.
- **`seo.google_analytics_id`** is present in both `config/install` and the profile's
  `config/sync` but is read and written by nothing; Google Tag Manager replaced it.
