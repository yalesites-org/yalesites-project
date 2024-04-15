## INTRODUCTION

The YS Localist module handles the import of events from the Localist API.

## REQUIREMENTS

* Drupal Core Migrate
* [Migrate Plus](https://www.drupal.org/project/migrate_plus)
* [Migrate Tools](https://www.drupal.org/project/migrate_tools)

## INSTALLATION

Install as you would normally install a contributed Drupal module.
See: https://www.drupal.org/node/895232 for further information.

## CONFIGURATION
- Visit `/admin/yalesites/localist` or via the menu "Settings" -> "Localist settings"
- Click "Enable Localist sync"
- Enter or double check the default endpoint URL
- Click "Save configuration"
- If the API returns data, the form should have a new button called "Create Groups"
- Click "Create Groups"
- If this worked, the form should reload and a new autocomplete field called "Group to sync events"
- Start typing the name of a group to sync events from and choose the group
- Click "Save configuration"
- The form should now have a "Sync now" button at the top of the form
- Click "Sync now"
- The initial sync takes about 30 seconds
- Once finished, the status message should tell you how many events were imported.
- Verify by visiting the content overview page at `/admin/content`

## Localist API
The [Localist API documentation](https://developer.localist.com/doc/api) can be useful in building additional migrations.

## Migrations

This module uses Drupal core migration. The following are the migrations that are imported and a brief description of each.

* `localist_event_types` - These are a filter on Localist that applies to events. There can be many event types attached to an event. This migration creates taxonomy terms in the `localist_event_type` vocabulary. In the migration, the `parent` is a migration lookup to itself to be able to create hierarchial terms.
* `localist_events` - This is the main migration that takes care of creating event nodes from Localist events. It has dependencies of all of the other migrations listed here. More details are below in describing the custom plugins written to support this migration.
* `localist_experiences` - Localist does not have an endpoint for experiences, so this migration is done via the `embedded_data` source plugin and all data is in this file. This creates taxonomy terms in the `event_type` vocabulary.
* `localist_groups` - A group is required to pull events from Localist so we can match the group with the Drupal site where the events will be displayed. This migration pulls from the groups endpoint and creates taxonomy terms in the `event_groups` vocabulary.
* `localist_places` - Events can be associated with a place. Each place has a lot of location data like address, geolocation, parking, and others. This migration creates taxonomy terms in the `event_place` vocabulary that is fielded with many of these place fields.
* `localist_status` - Like experiences, this is also a migration that uses the `embedded_data` source plugin as Localist does not have an endpoint for this. Terms are created in the `event_status` vocabulary.

## The Event Migration

Specific unique plugins will be mentioned here. Most migration fields are text strings, so, for example `field_event_room: localist_room` is simply adding the `localist_room` to the `field_event_room`.

### Callback Source URL

Most of the migrations require a dynamic URL for accessing the Localist API. The `migrate_plus` module supports callbacks with a patch that is in the `web/profiles/custom/yalesites_profile/composer.json`. This allows for a function `ys_localist_migrate_url` in the `ys_localist.module` file to return an array of dynamic URLs to use.

### Source Plugin
The Localist API is structured in a way that events repeat at the top level, using the same event ID. Event instances (each date that is attached to a single event) are located in a sub-key and also reference the top-level event ID. Therefore, to split out each instance and return only one event with many instances to the migration, a custom `migrate_plus` data parser plugin called `localist_json` was written. This parser handles the paging and combination of data. It returns a keyed array with the eventID as the key, and two sub arrays: `localist_data` and `instances`. `localist_data` is simply a copy of all of the data from the original event. And `instances` is an array of all date instances.

### Extract Filter Process Plugin

Filters in Localist have an extra key in the data that is returned, so a custom process plugin called `extract_localist_filter` was built to handle the Event Type filter as well as any future filters that may be created. For example, eventually there will be an Audience filter and this field would utilize this same process plugin but with a different filter value. To use, see the event type process:

```
field_localist_event_type:
    -
      plugin: extract_localist_filter
      source: filters
      filter: event_types
    -
      plugin: migration_lookup
      migration: localist_event_types
      no_stub: true
```

The `filter` is the name of the key on the filter from the Localist API. Note that an additional taxonomy vocabulary and accompanying migration will also need to be build for any subsequent filters.

Then, there is a secondary step to use the ID that was extracted with a migration lookup to lookup the correct ID to connect to a taxonomy term.

### Extract Groups Process Plugin
Similar to the extra filters but without the extra key, there is also a specific `extract_localist_groups` process plugin that is used in a similar way to first extract, and then it will use a migration lookup to lookup the correct ID to connect to a taxonomy term.

### On Overwriting Properties
In the `destination` section of the migration there is a `overwrite_properties` key. Any Drupal field listed here will be overwritten with Localist data on the next sync. This is important to know for what happens when new dates get added to Localist, they will also get added to Drupal. However, this also means that all past dates except for the last date will be removed from Drupal. The last date won't get removed because at that point, the Localist feed will no longer have that event and the node will not be updated anymore.

## Scheduling
The cron run is scheduled in the `ys_localist.module` file to run every hour. Note that due to caching of the API, caching of Drupal, and any edge caching, data can take longer than an hour to show up.
