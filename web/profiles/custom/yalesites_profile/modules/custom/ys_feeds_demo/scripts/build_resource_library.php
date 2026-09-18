<?php

/**
 * @file
 * Builds the "demo_resource_library" feed type (Demo 1).
 *
 * CAUTION: this deletes and recreates the feed type. Deleting a feed type
 * cascades — the per-feed-type permissions held by site_admin and
 * platform_admin go with it, silently. Re-grant them before exporting, or
 * the config diff will quietly drop them.
 *
 * Run with: lando drush php:script <this file>
 */

use Drupal\feeds\Entity\FeedType;

$id = 'demo_resource_library';

if ($existing = FeedType::load($id)) {
  $existing->delete();
  echo "Deleted existing $id\n";
}

$columns = [
  'accession', 'title', 'description', 'blurb', 'abstract', 'citation',
  'journal', 'issue', 'pub_date', 'category', 'discipline', 'geo', 'years',
  'tags', 'audience', 'cas', 'source_url', 'pdf_url', 'cover_url', 'cover_alt',
];
$custom_sources = [];
foreach ($columns as $column) {
  $custom_sources[$column] = [
    'label' => $column,
    'value' => $column,
    'machine_name' => $column,
    'type' => 'csv',
  ];
}
// Blank source that a DefaultValue tamper fills in.
$custom_sources['date_format'] = [
  'label' => 'date_format',
  'value' => 'date_format',
  'machine_name' => 'date_format',
  'type' => 'blank',
];

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
  // The accession number is the collection's own identifier, so it is the
  // natural GUID: re-importing an updated catalogue updates the same nodes.
  [
    'target' => 'feeds_item',
    'map' => ['guid' => 'accession'],
    'unique' => ['guid' => TRUE],
    'settings' => [],
  ],
  ['target' => 'title', 'map' => ['value' => 'title'], 'settings' => ['language' => NULL]],
  ['target' => 'field_teaser_title', 'map' => ['value' => 'title'], 'settings' => []],
  [
    'target' => 'field_content_description',
    'map' => ['value' => 'description'],
    'settings' => ['format' => 'restricted_html'],
  ],
  ['target' => 'field_teaser_text', 'map' => ['value' => 'blurb'], 'settings' => ['format' => 'heading_html']],
  ['target' => 'field_abstract', 'map' => ['value' => 'abstract'], 'settings' => ['format' => 'restricted_html']],
  ['target' => 'field_citation', 'map' => ['value' => 'citation'], 'settings' => ['format' => 'restricted_html']],
  ['target' => 'field_journal_publication_name', 'map' => ['value' => 'journal'], 'settings' => []],
  ['target' => 'field_journal_publication_issue', 'map' => ['value' => 'issue'], 'settings' => []],
  ['target' => 'field_publish_date', 'map' => ['value' => 'pub_date'], 'settings' => []],
  ['target' => 'field_date_format', 'map' => ['value' => 'date_format'], 'settings' => []],
  ['target' => 'field_login_required', 'map' => ['value' => 'cas'], 'settings' => []],
  ['target' => 'field_external_source', 'map' => ['uri' => 'source_url', 'title' => ''], 'settings' => []],
  $term('field_category', 'category', 'resource_category'),
  $term('field_discipline', 'discipline', 'discipline'),
  $term('field_geographic_areas', 'geo', 'geographic_areas'),
  $term('field_academic_years', 'years', 'academic_years'),
  $term('field_tags', 'tags', 'tags'),
  $term('field_audience', 'audience', 'audience'),
  // Layout Builder spike: give the imported node a body, on first import only.
  [
    'target' => 'layout_builder__layout',
    'map' => ['value' => 'description'],
    'settings' => [
      'format' => 'restricted_html',
      'section_label' => 'Content Section',
      'block_label' => 'Imported description',
    ],
  ],
  // The two custom media targets: the whole point of this demo.
  [
    'target' => 'field_media',
    'map' => ['target_id' => 'pdf_url', 'alt' => ''],
    'settings' => ['media_bundle' => 'document', 'existing' => 1],
  ],
  [
    'target' => 'field_teaser_media',
    'map' => ['target_id' => 'cover_url', 'alt' => 'cover_alt'],
    'settings' => ['media_bundle' => 'image', 'existing' => 1],
  ],
];

$feed_type = FeedType::create([
  'id' => $id,
  'label' => 'Demo: library resource collection',
  'description' => 'Turns an uploaded catalogue export into Resource nodes, downloading each record\'s PDF and cover image into real media entities. Keyed on accession number, so re-uploading an updated export updates the existing resources rather than duplicating them.',
  'help' => 'Upload a CSV export from the collection management system. Rows are matched on the accession column, so re-uploading a corrected export updates the existing resources instead of duplicating them. Each row may point at a PDF and a cover image by URL; those are downloaded and become media entities.',
  // Manual only: nothing should start importing halfway through a demo, and
  // an uploaded file has no URL to poll anyway.
  'import_period' => -1,
  // A collections team sends over a CSV export and someone uploads it. That
  // is the actual workflow, and it keeps the demo honest: the catalogue rows
  // arrive as a file, while the PDFs and cover images they point at are still
  // fetched over HTTP, which is the part that matters.
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
    'insert_new' => 1,
    'update_existing' => 2,
    // Never remove a catalogue record just because a row was dropped from an
    // export; a library decides that, not an importer.
    'update_non_existent' => '_keep',
    'expire' => -1,
    'owner_feed_author' => FALSE,
    'owner_id' => 1,
    'authorize' => FALSE,
    'skip_hash_check' => FALSE,
    // layout_builder__layout is declared with cardinality 1 in config. The
    // Layout Builder UI writes several sections regardless and never notices,
    // because it does not run full entity validation. Feeds does, so without
    // this exemption every imported node fails on a Count violation. Exempting
    // the single constraint is far safer than skip_validation, which would
    // disable every check including the required and format ones.
    'skip_validation' => FALSE,
    'skip_validation_types' => ['Count'],
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

// Publication dates arrive in whatever shape the source system produced.
// Note for the evaluation: strtotime() *guesses*, and 3/4/2015 is March in the
// United States and April almost everywhere else. The existing ys_migrate
// importer deliberately refuses to guess. This is a real trade-off, not a win.
$add('pub_date', 'strtotime', ['date_format' => '', 'fallback' => TRUE], 'Parse whatever date shape arrived', 0);
$add('pub_date', 'timetodate', ['date_format' => 'Y-m-d'], 'Normalise to Y-m-d', 1);

// Multi-value taxonomy columns are pipe separated, with inconsistent spacing
// and the occasional trailing separator.
// Tamper's Explode returns the original string for an empty cell but still
// marks the value as multiple, so the next tamper tries to iterate a string
// and PHP warns. Dropping empty values first avoids it. (feeds_tamper's own
// FeedsSubscriber carries a @todo acknowledging this.)
foreach (['geo', 'years', 'tags'] as $multi) {
  $add($multi, 'skip_on_empty', [], 'Drop empty cells before splitting', -1);
  $add($multi, 'explode', ['separator' => '|', 'limit' => NULL], 'Split on pipes', 0);
  $add($multi, 'trim', ['character' => '', 'side' => 'trim'], 'Trim each value', 1);
  $add($multi, 'array_filter', [], 'Drop empty values left by trailing separators', 2);
}

// Tamper's Explode returns the original string for an empty cell but still
// marks the value as multiple, so the next tamper tries to iterate a string
// and PHP warns. Dropping empty values first avoids it. (feeds_tamper's own
// FeedsSubscriber carries a @todo acknowledging this.)
$add('audience', 'skip_on_empty', [], 'Drop empty cells before splitting', -1);
$add('audience', 'explode', ['separator' => ',', 'limit' => NULL], 'Split on commas', 0);
$add('audience', 'trim', ['character' => '', 'side' => 'trim'], 'Trim each value', 1);

// Discipline is single valued in the content model. A pipe separated cell
// would silently lose everything after the first value, so take the first
// deliberately rather than by accident.
$add('discipline', 'find_replace_regex', ['find' => '/\\|.*$/', 'replace' => '', 'limit' => NULL], 'Keep only the first discipline', 0);
$add('discipline', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 1);

$add('cas', 'convert_boolean', [
  'truth_value' => 'Yes',
  'false_value' => 'No',
  'match_case' => FALSE,
  'no_match_value' => FALSE,
  'other_text' => '',
], 'Yes/No to boolean', 0);

$add('blurb', 'strip_tags', ['allowed_tags' => ''], 'Strip HTML', 0);
$add('blurb', 'truncate_text', ['num_char' => 150, 'ellipses' => TRUE, 'wordsafe' => TRUE], 'Truncate to 150 characters', 1);

$add('date_format', 'default_value', ['default_value' => 'date', 'only_if_empty' => FALSE], 'Always use the full-date display format', 0);

// A record with no accession number cannot be synchronised.
$add('accession', 'trim', ['character' => '', 'side' => 'trim'], 'Trim', 0);
$add('accession', 'required', ['invert' => FALSE], 'Skip rows with no accession number', 1);

$feed_type->setThirdPartySetting('feeds_tamper', 'tampers', $tampers);
$feed_type->save();

echo "Created feed type: " . $feed_type->id() . "\n";
echo "Mappings: " . count($feed_type->getMappings()) . "\n";
echo "Tampers: " . count($tampers) . "\n";
