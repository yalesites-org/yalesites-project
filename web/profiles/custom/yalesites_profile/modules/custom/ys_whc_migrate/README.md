# Whitney Migration

This module provides the Whitney D7 into Yale Sites migration.

## Configuration

To run the migration locally, run the following steps (`ddev` is assumed to be
used):

1. Import the database: `ddev import-db --database=migrate --file=PATH_TO_DATABASE/custom.sql.gz`
2. In `settings.local.php`, add the `migrate` database connection:
```php
  $databases['migrate']['default'] = [
    'database' => 'migrate',
    'username' => 'root',
    'password' => 'root',
    'host' => 'db',
    'port' => '3306',
    'driver' => 'mysql',
    'prefix' => '',
    'collation' => 'utf8mb4_unicode_ci',
  ];
```
1. Enable the module: `ddev drush en ys_whc_migrate -y`
2. Run the migration status command: `ddev drush ms --group=ys_whc_migrate`

## Running the migration

To run the migration, run the following command:

1. First, run the media duplicate file detection (required to run the media
   migration):
   1. `ddev drush migrate:duplicate-file-detection whc_node_images`
   1. `ddev drush migrate:duplicate-file-detection whc_user_images`
2. Finally, run the migration:
   1. `ddev drush mim --tag=whc_node --execute-dependencies`
