<?php

/**
 * @file
 * Builds the "demo_staff_roster" feed type (Demo 2).
 *
 * Feed type config is fiddly and easy to get subtly wrong by hand, so it is
 * generated through the Feeds API and then exported to config/sync, which is
 * what ships. After running this, export with `lando drush cex -y` and commit
 * only the feeds.feed_type.* change.
 *
 * CAUTION: this deletes and recreates the feed type. Deleting a feed type
 * cascades — the per-feed-type permissions held by site_admin and
 * platform_admin go with it, silently. Re-grant them before exporting, or
 * the config diff will quietly drop them.
 *
 * Run with: lando drush php:script <this file>
 */

use Drupal\feeds\Entity\FeedType;

$id = 'demo_staff_roster';

if ($existing = FeedType::load($id)) {
  $existing->delete();
  echo "Deleted existing $id\n";
}

/**
 * Declares a CSV column as a Feeds source.
 */
$source = function ($name) {
  return ['label' => $name, 'value' => $name, 'machine_name' => $name, 'type' => 'csv'];
};

$columns = [
  'email', 'prefix', 'first_name', 'last_name', 'pronouns', 'position',
  'department', 'phone', 'office', 'affiliations', 'audience',
  'teaser_title', 'teaser_summary',
];
$custom_sources = [];
foreach ($columns as $column) {
  $custom_sources[$column] = $source($column);
}

/**
 * Builds a taxonomy entity reference mapping with autocreate enabled.
 */
$term_mapping = function ($field, $src, $bundle) {
  return [
    'target' => $field,
    'map' => ['target_id' => $src],
    'settings' => [
      'reference_by' => 'name',
      'autocreate' => TRUE,
      'autocreate_bundle' => $bundle,
    ],
  ];
};

$mappings = [
  // Title is composed from two other columns by a Rewrite tamper, so it maps
  // from a column that does not exist in the CSV: 'display_name' is a blank
  // source the tamper fills in.
  ['target' => 'title', 'map' => ['value' => 'display_name'], 'settings' => ['language' => NULL]],
  // The dedupe key. Marking it unique is what turns this from an importer
  // into a synchroniser: matching rows update the existing profile.
  [
    'target' => 'field_email',
    'map' => ['value' => 'email'],
    'unique' => ['value' => TRUE],
    'settings' => [],
  ],
  ['target' => 'field_first_name', 'map' => ['value' => 'first_name'], 'settings' => []],
  ['target' => 'field_last_name', 'map' => ['value' => 'last_name'], 'settings' => []],
  ['target' => 'field_honorific_prefix', 'map' => ['value' => 'prefix'], 'settings' => []],
  ['target' => 'field_pronouns', 'map' => ['value' => 'pronouns'], 'settings' => []],
  ['target' => 'field_position', 'map' => ['value' => 'position'], 'settings' => []],
  ['target' => 'field_department', 'map' => ['value' => 'department'], 'settings' => []],
  ['target' => 'field_telephone', 'map' => ['value' => 'phone'], 'settings' => []],
  ['target' => 'field_address', 'map' => ['value' => 'office'], 'settings' => ['format' => 'restricted_html']],
  ['target' => 'field_teaser_title', 'map' => ['value' => 'teaser_title'], 'settings' => []],
  [
    'target' => 'field_teaser_text',
    'map' => ['value' => 'teaser_summary'],
    'settings' => ['format' => 'restricted_html'],
  ],
  $term_mapping('field_affiliation', 'affiliations', 'affiliation'),
  $term_mapping('field_audience', 'audience', 'audience'),
];

// The blank source the display-name Rewrite tamper writes into.
$custom_sources['display_name'] = [
  'label' => 'display_name',
  'value' => 'display_name',
  'machine_name' => 'display_name',
  'type' => 'blank',
];

$feed_type = FeedType::create([
  'id' => $id,
  'label' => 'Demo: department staff roster (spreadsheet sync)',
  'description' => 'Keeps Profile nodes in step with a roster spreadsheet. Point it at a Google Sheet published as CSV and it will follow that sheet on cron: edited rows update the matching profile in place, and rows that disappear are archived rather than deleted.',
  'help' => 'The source URL must return CSV. For a Google Sheet use File > Share > Publish to web, choose the sheet, and select Comma-separated values (.csv).',
  // Hourly, so the "it syncs on its own" claim is demonstrable on cron.
  'import_period' => 3600,
  'fetcher' => 'http',
  'fetcher_configuration' => [
    'auto_detect_feeds' => FALSE,
    'use_pubsubhubbub' => FALSE,
    'always_download' => TRUE,
    'fallback_hub' => '',
    'request_timeout' => 30,
  ],
  'parser' => 'csv',
  'parser_configuration' => ['delimiter' => ',', 'no_headers' => FALSE, 'line_limit' => 100],
  'processor' => 'entity:node',
  'processor_configuration' => [
    'langcode' => 'en',
    'insert_new' => 1,
    // Update, rather than skip: the whole point of the demo.
    'update_existing' => 2,
    // Rows that vanish from the sheet get archived, not deleted.
    'update_non_existent' => 'ys_feeds_demo_archive_moderated_node',
    'expire' => -1,
    'owner_feed_author' => FALSE,
    'owner_id' => 1,
    'authorize' => FALSE,
    'skip_hash_check' => FALSE,
    'values' => ['type' => 'profile'],
  ],
  'custom_sources' => $custom_sources,
  'mappings' => $mappings,
]);

/**
 * Returns a tamper definition keyed by uuid.
 */
$uuid_service = \Drupal::service('uuid');
$tampers = [];
$add = function ($source, $plugin, array $settings, $label, $weight) use (&$tampers, $uuid_service) {
  $uuid = $uuid_service->generate();
  $tampers[$uuid] = [
    'uuid' => $uuid,
    'plugin' => $plugin,
    'source' => $source,
    'weight' => $weight,
    'label' => $label,
    'description' => '',
  ] + $settings;
};

// Compose the display name from two columns the CSV does have.
$add('display_name', 'rewrite', ['text' => '[first_name] [last_name]'], 'Build display name from first and last name', 0);
$add('display_name', 'find_replace_regex', ['find' => '/\\s+/', 'replace' => ' ', 'limit' => NULL], 'Collapse repeated whitespace', 1);
$add('display_name', 'trim', ['character' => '', 'side' => 'trim'], 'Trim stray whitespace', 2);

// Names in the sheet carry trailing spaces from copy-paste.
$add('first_name', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);
$add('last_name', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);

// Positions arrive in a mix of ALL CAPS and lower case. ucwords alone will not
// fix shouting, so lowercase first and then capitalise: tamper order matters,
// and the weights are what express it.
$add('position', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);
$add('position', 'convert_case', ['operation' => 'strtolower'], 'Flatten ALL CAPS to lower case', 1);
$add('position', 'convert_case', ['operation' => 'ucwords'], 'Capitalise each word', 2);
$lower_word = function ($word) {
  return [
    'find' => $word,
    'replace' => strtolower($word),
    'case_sensitive' => TRUE,
    'word_boundaries' => TRUE,
    'whole' => FALSE,
  ];
};
$add('position', 'find_replace', $lower_word('Of'), 'Lower-case "of"', 3);
$add('position', 'find_replace', $lower_word('And'), 'Lower-case "and"', 4);

// Affiliations are semicolon separated with inconsistent spacing.
// Tamper's Explode returns the original string for an empty cell but still
// marks the value as multiple, so the next tamper tries to iterate a string
// and PHP warns. Dropping empty values first avoids it. (feeds_tamper's own
// FeedsSubscriber carries a @todo acknowledging this.)
$add('affiliations', 'skip_on_empty', [], 'Drop empty cells before splitting', -1);
$add('affiliations', 'explode', ['separator' => ';', 'limit' => NULL], 'Split on semicolons', 0);
$add('affiliations', 'trim', ['character' => '', 'side' => 'trim'], 'Trim each affiliation', 1);

// Audience is comma separated.
// Tamper's Explode returns the original string for an empty cell but still
// marks the value as multiple, so the next tamper tries to iterate a string
// and PHP warns. Dropping empty values first avoids it. (feeds_tamper's own
// FeedsSubscriber carries a @todo acknowledging this.)
$add('audience', 'skip_on_empty', [], 'Drop empty cells before splitting', -1);
$add('audience', 'explode', ['separator' => ',', 'limit' => NULL], 'Split on commas', 0);
$add('audience', 'trim', ['character' => '', 'side' => 'trim'], 'Trim each audience term', 1);

// Teaser text is authored as HTML and frequently runs past the 150 character
// limit the profile form enforces.
$add('teaser_summary', 'strip_tags', ['allowed_tags' => ''], 'Strip HTML', 0);
$add('teaser_summary', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 1);
$add('teaser_summary', 'truncate_text', ['num_char' => 150, 'ellipses' => TRUE, 'wordsafe' => TRUE], 'Truncate to 150 characters', 2);

// A row with no email has no identity, so it cannot be synchronised. Skip it
// rather than creating an orphan that the next import would duplicate.
$add('email', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);
$add('email', 'required', ['invert' => FALSE], 'Skip rows with no email address', 1);

$feed_type->setThirdPartySetting('feeds_tamper', 'tampers', $tampers);
$feed_type->save();

echo "Created feed type: " . $feed_type->id() . "\n";
echo "Mappings: " . count($feed_type->getMappings()) . "\n";
echo "Tampers: " . count($tampers) . "\n";
