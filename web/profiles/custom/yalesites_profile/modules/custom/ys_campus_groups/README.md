## INTRODUCTION

The YS Campus Groups module handles the import of events from the Campus Groups API.

## REQUIREMENTS

* Drupal Core Migrate
* [Migrate Plus](https://www.drupal.org/project/migrate_plus)
* [Migrate Tools](https://www.drupal.org/project/migrate_tools)

## INSTALLATION

Install as you would normally install a contributed Drupal module.
See: https://www.drupal.org/node/895232 for further information.

## CONFIGURATION
- Visit `/admin/yalesites/campus_groups` or via the menu "Settings" -> "Campus Groups settings"
- Click "Enable Campus Groups sync"
- Enter or double check the default endpoint URL
- Enter the number of days to sync ahead
- Enter the group ids to sync (you'll get these from campus groups)
- Click "Save configuration"
- The form should now have a "Sync now" button at the top of the form
- Click "Sync now"
- The initial sync takes about 30 seconds
- Once finished, the status message should tell you how many events were imported.
- Verify by visiting the content overview page at `/admin/content`

## Migrations

This module uses Drupal core migration. The following are the migrations that are imported and a brief description of each.

* `campus_groups_events` - These are a filter on Campus Groups that applies to events.

## The Event Migration

Specific unique plugins will be mentioned here. Most migration fields are text strings, so, for example `field_event_room: campus_groups_room` is simply adding the `campus_groups_room` to the `field_event_room`.

## Scheduling
The cron run is scheduled in the `ys_campus_groups.module` file to run every hour. Note that due to caching of the API, caching of Drupal, and any edge caching, data can take longer than an hour to show up.

