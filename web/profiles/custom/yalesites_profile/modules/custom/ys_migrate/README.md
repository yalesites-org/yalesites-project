# YaleSite Migrations

## Overview

This README details the process for Drupal migrations at Yale, specifically the migration from Drupal 7 to Drupal 10. These migrations are part of Yale's strategic initiative to upgrade its web presence across various departments and offices, leveraging enhanced features and improved performance of Drupal 10.

## CSV Template

The Profile CSV Import tool includes a "Download CSV Template" link that redirects users to an external CSV template file. The external URL is hardcoded in the controller and can be updated by developers as needed.

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
