# YaleSites Migrate ONHA

This module contains ONHA-specific migration configurations and plugins for the YaleSites platform.

## Overview

This module is a sub-module of `ys_migrate` that contains migration configurations and custom process plugins specifically for migrating content from the ONHA (Office of New Haven Affairs) Drupal 7 website to the YaleSites Drupal 10 platform.

## Contents

### Migration Configurations

- `migrate_plus.migration_group.ys_onha.yml` - Migration group configuration for ONHA migrations
- `migrate_plus.migration.ys_onha_news.yml` - Migration for ONHA news content
- `migrate_plus.migration.ys_onha_news_terms.yml` - Migration for ONHA news taxonomy terms
- `migrate_plus.migration.ys_onha_program_body.yml` - Migration for ONHA program body fields to blocks
- `migrate_plus.migration.ys_onha_programs.yml` - Migration for ONHA program content

### Custom Process Plugins

- `BodyToLayoutBuilder` - Custom process plugin that converts Drupal 7 body fields into Layout Builder sections

## Dependencies

- `ys_migrate` - Parent migration module
- `migrate_plus` - Migration Plus module
- `migrate_tools` - Migration Tools module

## Usage

This module should only be enabled on sites that need to migrate ONHA content. For other YaleSites installations, this module can remain disabled to avoid having unused migration configurations active. 
