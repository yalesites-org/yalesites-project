<?php

/**
 * @file
 * Local-only defaults for PostgreSQL + pgvector (AI VDB).
 *
 * Used when running under Lando; Pantheon stays on MariaDB for Drupal. The
 * ai_vdb_provider_postgres module reads these values from config — set here so
 * Drush/UI work without exporting credentials to config sync.
 *
 * @see https://www.drupal.org/project/ai_vdb_provider_postgres
 */

$config['ai_vdb_provider_postgres.settings']['host'] = 'pgvector';
$config['ai_vdb_provider_postgres.settings']['port'] = 5432;
$config['ai_vdb_provider_postgres.settings']['username'] = 'postgres';
$config['ai_vdb_provider_postgres.settings']['password'] = 'postgres';
$config['ai_vdb_provider_postgres.settings']['default_database'] = 'pgvector';
