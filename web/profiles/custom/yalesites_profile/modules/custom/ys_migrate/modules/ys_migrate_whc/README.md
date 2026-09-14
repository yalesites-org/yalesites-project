# YaleSites Migrate WHC

This module contains WHC-specific migration configurations and plugins for the YaleSites platform.

## Overview

This module is a sub-module of `ys_migrate` that contains migration configurations and custom source/process plugins specifically for migrating content from the Whitney Humanities Center (WHC) Drupal 7 website to the YaleSites Drupal 10 platform.

Like the other `ys_migrate` sub-modules, it is opt-in: enable it on the environment where you are running the WHC migration, then disable it again once the migration is complete. It should remain disabled on all other sites.

## Contents

### Migration Configurations

- `migrate_plus.migration_group.ys_whc.yml` - Migration group configuration for WHC migrations (group id `ys_whc`)
- `migrate_plus.migration.whc_taxonomy_series.yml` - D7 `series` terms into the `tags` vocabulary
- `migrate_plus.migration.whc_taxonomy_fellow_role.yml` - D7 `fellow_role` terms into the `audience` vocabulary
- `migrate_plus.migration.whc_node_images.yml` - D7 image files into D10 media entities
- `migrate_plus.migration.whc_user_images.yml` - D7 user picture files into D10 media entities
- `migrate_plus.migration.whc_focal_point.yml` - Focal-point data for the migrated image media
- `migrate_plus.migration.whc_node_event.yml` - D7 `event` nodes into D10 `event` nodes (with Layout Builder)
- `migrate_plus.migration.whc_node_video.yml` - D7 video nodes into D10 `post` nodes (with Layout Builder)
- `migrate_plus.migration.whc_node_profile.yml` - D7 people into D10 `profile` nodes

### Custom Source Plugins

- `WhcNode` (`whc_node`) - D7 node source; filters events by calendar selection and derives Smart Date values for event times
- `WhcUser` (`whc_user`) - D7 user source; also generates a media iterator for user pictures
- `WhcMedia` (`whc_media_entity_generator`) - D7 media-entity-generator source (extends `migrate_file_to_media`)

### Custom Process Plugins

- `ContentToLayoutBuilder` (`whc_content_to_layout_builder`) - Converts D7 content into Layout Builder sections and inline block components (text, image, video banner). Also exposes static helpers used as migration callbacks: `replaceStraightQuotes`, `getTermName`, `toWatchUrl`.
- `GetMediaFile` (`whc_get_media_file`) - Resolves a media entity id to its underlying image file id

## Dependencies

- `ys_migrate` - Parent migration module
- `migrate_plus`, `migrate_tools`, `migrate_drupal` - Core migration tooling
- `migrate_file_to_media` - D7 file to D10 media migration; provides the `check_duplicate`, `check_media_duplicate`, `media_file_copy`, and `media_name` process plugins used by the image/user-image migrations
- `migrate_conditions` - Provides the `if_condition` and `skip_on_condition` process plugins used by the event and profile migrations

`migrate_file_to_media` and `migrate_conditions` are available at the platform level but are only enabled when this sub-module (or another consumer) is enabled.

## Reusability

The custom source and process plugins here (`whc_node`, `whc_user`, `whc_media_entity_generator`, `whc_content_to_layout_builder`, `whc_get_media_file`) are written as general, reusable building blocks and can inform or be adapted for future D7 -> D10 migrations. The migration YAML definitions, however, are intentionally tied to WHC's source data: they reference WHC-specific D7 field names, term IDs, source file URLs, and target content types. A future site migration should reuse the plugins and the migration structure, but expects to re-map that site-specific configuration.

## Usage

Enable this module only on the environment where you are running the WHC migration:

```bash
lando drush en ys_migrate_whc -y
```

Configure the D7 source database connection in `settings.php` (see the parent `ys_migrate` README), then verify the migrations are discovered:

```bash
lando drush migrate:status --group=ys_whc
```

Run the media duplicate-file detection first (required before the media migrations), then import by tag:

```bash
lando drush migrate:duplicate-file-detection whc_node_images
lando drush migrate:duplicate-file-detection whc_user_images
lando drush migrate:import --tag=whc_node --execute-dependencies
```

Disable the module again when the migration is complete:

```bash
lando drush pmu ys_migrate_whc -y
```
