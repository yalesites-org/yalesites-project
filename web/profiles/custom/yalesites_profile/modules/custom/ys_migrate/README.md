# YaleSite Migrations

## Overview

This README details the process for Drupal migrations at Yale, specifically the migration from Drupal 7 to Drupal 10. These migrations are part of Yale's strategic initiative to upgrade its web presence across various departments and offices, leveraging enhanced features and improved performance of Drupal 10.

## CSV Template

The Profile CSV Import tool includes a "Download CSV Template" link that redirects users to an external CSV template file. The external URL is hardcoded in the controller and can be updated by developers as needed.

## Post CSV Import

Site owners can bulk-create posts from a spreadsheet at **Content > Manage
Posts > CSV Import** (`/admin/content/manage-posts/csv-import`, permission
`create post content`). The form lists every recognised column with a note and
links to a sample CSV. Like the Resource importer it offers a preview that saves
nothing, skips duplicates (matched on Title **and** Publish Date, since a series
such as a weekly update reuses its title), runs the real import as a batch, and
creates every post as a **draft**.

How the columns land on a post (`PostImportService`):

- **Title** and **Publish Date** are required. An empty Title rejects the file;
  every other problem fails only its own row and is listed in the preview and
  the import summary.
- **Body** has no field to go in: a post's content is built in Layout Builder.
  The importer copies the post bundle's default layout (keeping the locked
  "Title and Metadata" section, as Layout Builder does when an editor first
  edits a layout) and adds one inline **Text** block holding the Body to the
  "Content Section". The block is handed to the node unsaved
  (`block_serialized`), so saving the post saves it and records its inline block
  usage. Plain text is turned into paragraphs. The Text block's `basic_html`
  format allows no `<img>`, `<iframe>`, `<video>` or embedded media, so a Body
  containing them (or D7 `[[{"type":"media"...}]]` / `[[nid:123]]` tokens, which
  are removed) is imported without them and listed in the summary as needing
  images added by hand.
- **Category**, **Tags**, **Audience**, **Custom Vocab** only accept terms that
  already exist. Unlike the profile and resource importers, a misspelt term
  fails its row (`TaxonomyResolverService::findTerm()`) rather than being
  created.
- **Teaser Media ID** takes the ID of an image already in the media library. The
  importer never uploads files; site owners can upload images first or add them
  after import.
- **URL Alias** sets the path alias and switches Pathauto off for that post; it
  must not already be in use. Leave it empty for Pathauto's usual alias.
- **Author**, **Teaser Title**, **Teaser Text** (capped at the form display's
  limit), **External Source** and **CAS Login Required** map to their fields.

`ys_content_export` writes **Publish Date**, **Category** and **CAS Protected**
headers that this importer reads, so a Manage Posts export can be filled in and
imported; its URL and Published columns are reported as ignored.

## General Migration Setup

Each migration within Yale is organized into specific migration groups. These groups manage the migration of data and content from old Drupal instances to new ones, focusing on maintaining data integrity and meeting the unique requirements of each site. This document outlines the migrations for the Office of New Haven Affairs (ONHA), but the framework and methods described are applicable to other Yale sites.

## Migration Group Configuration

All migrations use a shared database connection, typically named 'd7' for Drupal 7 sources. This connection is defined in the site’s `settings.php` file and is essential for accessing the source data.

Below is a sample configuration for the `settings.php` file:
```php
$databases['d7']['default'] = [
  'database'=>'pantheon',
  'username'=>'pantheon',
  'password'=>'pw',
  'prefix'=>'',
  'host'=>'host-from-pantheon',
  'port'=>'port-from-pantheon',
  'namespace'=>'Drupal\\Core\\Database\\Driver\\mysql',
  'driver'=>'mysql'
];
```

## Running General Migrations

Migrations are executed using Drush, Drupal's command-line interface. Ensure all dependencies, including essential modules like Migrate Plus and Migrate Tools, are installed before running any migrations. Use the following Drush commands:

```bash
drush migrate:status
drush migrate:import [migration_id]
drush migrate:rollback [migration_id]
```

## Troubleshooting and Logs

To identify and resolve issues during the migration, consult the migration logs using:

```bash
drush migrate:messages [migration_id]
```

## Specific Migration: Office of New Haven Affairs (ONHA)

The ONHA migration transfers a subset of content from Drupal 7 to Drupal 10 YaleSites. To run the ONHA migration:

1. Clone the `development` multidev build artifact from Pantheon as this environment has the latest content.
2. Add the `ys_migrate` module, as it is not yet in the project upstream.
3. Update the `settings.php` file to connect to a copy of the D7 source database.
4. Execute the following via Terminus after ensuring the required code is on the multidev:

```bash
# Enable the YaleSites migration module
drush en ys_migrate

# Verify the new database connection as pending changes exist
drush migrate:status

# Migrate 4 taxonomy terms from the Drupal 7 'news' vocabulary to the 'post_category' vocabulary in Drupal 10.
drush migrate:import ys_onha_news_terms

# Migrate 369 nodes from the Drupal 7 'news' content type to the 'post' content type in Drupal 10, treating all news items as external links.
drush migrate:import ys_onha_news

# Transform 266 'body' fields from the Drupal 7 'program' content type into Drupal 10 'text' content block entities.
drush migrate:import ys_onha_program_body

# Migrate 266 nodes from the Drupal 7 'program' content type to the 'page' content type in Drupal 10, attaching body content using Layout Builder.
drush migrate:import ys_onha_programs
```

## Specific Migration: Whitney Humanities Center (WHC)

The WHC migration transfers events, videos, people/profiles, taxonomy, and media
from Drupal 7 to Drupal 10 YaleSites. It is packaged as the `ys_migrate_whc`
sub-module (migration group `ys_whc`) and, like the other sub-modules, is opt-in:
enable it only on the environment running the migration and disable it afterwards.
It also relies on two additional contrib modules that are available on the
platform but only enabled with the sub-module: `migrate_file_to_media` and
`migrate_conditions`.

See `modules/ys_migrate_whc/README.md` for the full contents, run steps, and a
reusability note. In short:

```bash
drush en ys_migrate_whc
drush migrate:status --group=ys_whc
drush migrate:duplicate-file-detection whc_node_images
drush migrate:duplicate-file-detection whc_user_images
drush migrate:import --tag=whc_node --execute-dependencies
```

## Running tests

This module has PHPUnit tests under `tests/src/Unit/` for the CSV importers (`CsvValidatorService`, `TaxonomyResolverService`, `CsvImportBatch`, and the profile, resource and post import services, forms and sample controllers). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests`.

`tests/fixtures/profile-import-sample.csv` is a sample Profile CSV that is uploaded by hand through the Profile CSV import form when testing the importer. No production code reads it, so `ProfileImportSampleFixtureTest` pins its header row to `CsvValidatorService::EXPECTED_COLUMNS` — if the importer's columns change, that test fails rather than letting the sample go quietly stale.

The `ys_migrate_onha`, `ys_migrate_sustainability_news`, and `ys_migrate_whc` submodules each have their own tests, under their own `tests/src/Unit/` directories, covering their migrate process/source plugins. Run each the same way, substituting its own `tests` path, e.g.:

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/modules/ys_migrate_onha/tests"

lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/modules/ys_migrate_sustainability_news/tests"

lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/modules/ys_migrate_whc/tests"
```
