<?php

/**
 * @file
 * Local-only defaults for PostgreSQL + pgvector (AI VDB).
 *
 * Used when running under Lando; Pantheon stays on MariaDB for Drupal. The
 * ai_vdb_provider_postgres module reads these values from config — set here so
 * Drush/UI work without exporting credentials to config sync.
 *
 * Key entity `pgvector_password` uses the env provider for PGVECTOR_DB_PASSWORD.
 * If that variable is missing from the php-fpm / CLI environment (common when
 * `.lando.local.yml` overrides appserver env without merging upstream), the Key
 * resolves to an empty string and Postgres reports "password is not configured".
 *
 * @see https://www.drupal.org/project/ai_vdb_provider_postgres
 */

// Optional env overrides (set in Lando appserver — see `.lando.upstream.yml` / `.lando.local.yml`).
// Defaults match `.lando.upstream.yml` postgres service (user/database postgres / pgvector).
$pg_user = getenv('PGVECTOR_DB_USER') ?: 'postgres';
$pg_database = getenv('PGVECTOR_DB_DATABASE') ?: 'pgvector';
if (!getenv('PGVECTOR_DB_PASSWORD')) {
  putenv('PGVECTOR_DB_PASSWORD=postgres');
  $_ENV['PGVECTOR_DB_PASSWORD'] = 'postgres';
}

$config['ai_vdb_provider_postgres.settings']['host'] = 'pgvector';
$config['ai_vdb_provider_postgres.settings']['port'] = 5432;
$config['ai_vdb_provider_postgres.settings']['username'] = $pg_user;
// Password field stores the Key entity ID (key_select), not the secret itself.
$config['ai_vdb_provider_postgres.settings']['password'] = 'pgvector_password';
$config['ai_vdb_provider_postgres.settings']['default_database'] = $pg_database;
