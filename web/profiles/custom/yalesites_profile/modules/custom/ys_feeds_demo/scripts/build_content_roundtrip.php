<?php

/**
 * @file
 * Builds the "demo_content_roundtrip" feed type (Demo 3).
 *
 * Consumes the CSV that ys_content_export already produces, so a site owner
 * can export existing content, bulk-edit it in a spreadsheet, and load the
 * result back onto the same nodes.
 *
 * Run with: lando drush php:script <this file>
 */

use Drupal\feeds\Entity\FeedType;

$id = 'demo_content_roundtrip';

if ($existing = FeedType::load($id)) {
  $existing->delete();
  echo "Deleted existing $id\n";
}

// Keyed by machine name; 'value' is the exact header ys_content_export writes.
$headers = [
  'title' => 'Title',
  'publish_date' => 'Resource Publication Date',
  'url' => 'URL',
  'published' => 'Published',
  'cas_protected' => 'CAS Protected',
  'teaser_title' => 'Teaser Title',
  'teaser_text' => 'Teaser Text',
  'tags' => 'Tags',
  'audience' => 'Audience',
  'custom_vocab' => 'Custom Vocab',
  'resource_category' => 'Resource Category',
  'uuid' => 'UUID (do not edit)',
];

$custom_sources = [];
foreach ($headers as $machine_name => $header) {
  $custom_sources[$machine_name] = [
    'label' => $header,
    'value' => $header,
    'machine_name' => $machine_name,
    'type' => 'csv',
  ];
}

$term = function ($field, $src, $bundle) {
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
  // The UUID is the only stable handle on a node that already exists. Matching
  // on title would break the moment someone corrected a typo in a title, which
  // is exactly the kind of edit this workflow is for.
  [
    'target' => 'uuid',
    'map' => ['value' => 'uuid'],
    'unique' => ['value' => TRUE],
    'settings' => [],
  ],
  ['target' => 'title', 'map' => ['value' => 'title'], 'settings' => ['language' => NULL]],
  ['target' => 'field_teaser_title', 'map' => ['value' => 'teaser_title'], 'settings' => []],
  ['target' => 'field_teaser_text', 'map' => ['value' => 'teaser_text'], 'settings' => ['format' => 'heading_html']],
  ['target' => 'field_login_required', 'map' => ['value' => 'cas_protected'], 'settings' => []],
  $term('field_tags', 'tags', 'tags'),
  $term('field_audience', 'audience', 'audience'),
  $term('field_custom_vocab', 'custom_vocab', 'custom_vocab'),
  $term('field_category', 'resource_category', 'resource_category'),
];

$feed_type = FeedType::create([
  'id' => $id,
  'label' => 'Demo: bulk edit via export and re-import',
  'description' => 'Takes the CSV that the Manage Resources export produces, and loads an edited copy back onto the same nodes. Matches on the UUID column, so retitling and retagging in a spreadsheet updates existing content instead of creating copies.',
  'help' => 'Export from Manage Resources, edit in a spreadsheet, then upload the file here. Leave the UUID column alone: it is what identifies each row. Rows whose UUID is not recognised are reported and skipped, never created.',
  'import_period' => -1,
  // A person uploads a file they just edited. That is the actual workflow.
  'fetcher' => 'upload',
  'fetcher_configuration' => [
    'allowed_extensions' => 'csv tsv txt',
    'directory' => 'private://feeds',
  ],
  'parser' => 'csv',
  'parser_configuration' => ['delimiter' => ',', 'no_headers' => FALSE, 'line_limit' => 100],
  'processor' => 'entity:node',
  'processor_configuration' => [
    'langcode' => 'en',
    // SKIP_NEW. A row whose UUID does not match must never quietly mint a new
    // node: that would turn a typo in a spreadsheet into duplicate content.
    'insert_new' => 0,
    'update_existing' => 2,
    'update_non_existent' => '_keep',
    'expire' => -1,
    'owner_feed_author' => FALSE,
    'owner_id' => 1,
    'authorize' => FALSE,
    'skip_hash_check' => FALSE,
    'values' => ['type' => 'resource'],
  ],
  'custom_sources' => $custom_sources,
  'mappings' => $mappings,
]);

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

// The exporter joins multi-value taxonomy with ", ".
// Tamper's Explode returns the original string for an empty cell but still
// marks the value as multiple, so the next tamper tries to iterate a string
// and PHP warns. Dropping empty values first avoids it. (feeds_tamper's own
// FeedsSubscriber carries a @todo acknowledging this.)
foreach (['tags', 'audience', 'custom_vocab', 'resource_category'] as $multi) {
  $add($multi, 'skip_on_empty', [], 'Drop empty cells before splitting', -1);
  $add($multi, 'explode', ['separator' => ',', 'limit' => NULL], 'Split on commas', 0);
  $add($multi, 'trim', ['character' => '', 'side' => 'trim'], 'Trim each term', 1);
  $add($multi, 'array_filter', [], 'Drop empty values', 2);
}

$add('cas_protected', 'convert_boolean', [
  'truth_value' => 'Yes',
  'false_value' => 'No',
  'match_case' => FALSE,
  'no_match_value' => FALSE,
  'other_text' => '',
], 'Yes/No to boolean', 0);

// ContentExportBuilder::sanitizeCell() prefixes anything starting with = + - @
// with an apostrophe to defuse spreadsheet formula injection. That guard is
// correct on the way out and has to be undone on the way back in.
foreach (['title', 'teaser_title', 'teaser_text'] as $text) {
  $add($text, 'find_replace_regex', ['find' => '/^\'/', 'replace' => '', 'limit' => NULL], 'Strip the export formula guard', 0);
}

// A row with no UUID cannot be matched to anything.
$add('uuid', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);
$add('uuid', 'required', ['invert' => FALSE], 'Skip rows with no UUID', 1);

$feed_type->setThirdPartySetting('feeds_tamper', 'tampers', $tampers);
$feed_type->save();

echo "Created feed type: " . $feed_type->id() . "\n";
echo "Mappings: " . count($feed_type->getMappings()) . "\n";
echo "Tampers: " . count($tampers) . "\n";
