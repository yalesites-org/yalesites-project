<?php

/**
 * @file
 * Initializes the AI search pgvector collection and field schema.
 *
 * Run via: lando ai-search-init
 *
 * Two steps are required after a fresh install or rebuild:
 * 1. createCollection — creates the base pgvector table in Postgres.
 * 2. Index::save()    — fires hook_search_api_index_update, which calls
 *    updateFields() to add the Search API field columns (status, type, uid,
 *    etc.) via ALTER TABLE. Without this the first index attempt fails with
 *    "column status does not exist".
 *
 * Both steps are idempotent: createCollection logs a warning if the table
 * already exists; saving the index is always safe.
 */

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;

$server = Server::load('ai_search');
if (!$server) {
  echo "AI search server 'ai_search' not found — run 'lando drush cim -y' first.\n";
  return;
}

$config = $server->getBackendConfig();
$provider = \Drupal::service('ai.vdb_provider')->createInstance('postgres');

$provider->createCollection(
  collection_name: $config['database_settings']['collection'],
  dimension: (int) $config['embeddings_engine_configuration']['dimensions'],
  database: $config['database_settings']['database_name'],
);

$index = Index::load('ai_content');
if (!$index) {
  echo "Search API index 'ai_content' not found — run 'lando drush cim -y' first.\n";
  return;
}

$index->save();

echo "AI search collection initialized and field schema synced.\n";
