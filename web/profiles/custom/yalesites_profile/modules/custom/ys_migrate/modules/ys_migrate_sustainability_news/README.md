# YaleSites Migrate: Sustainability News

Migrates Drupal 7 **news** nodes from the Sustainability Yale D7 site into
Drupal 10 **post** nodes on the current YaleSites platform, including taxonomy
terms, images, media entities, and Layout Builder sections.

---

## Requirements

### Drupal modules

The following modules must be enabled before running the migration:

- `ys_migrate` (parent module)
- `ys_migrate_sustainability_news` (this module)
- `migrate_plus`
- `migrate_tools`

Enable with:

```bash
lando drush en ys_migrate_sustainability_news
```

---

## Step 1 — Set up the D7 database connection

The D7 source database must be available as a second database connection named
`d7_sustainability` in `web/sites/default/settings.local.php`:

```php
$databases['d7_sustainability']['default'] = [
  'database' => 'd7_sustainability',
  'username' => 'root',
  'password' => '',
  'host'     => 'database',
  'port'     => '3306',
  'driver'   => 'mysql',
  'prefix'   => '',
];
```

> The host `database` is Lando's internal name for the MySQL service. The
> root user has no password by default in the Lando Pantheon recipe. If your
> setup uses different credentials check `lando info` for the database service
> details.

### Import the D7 database

```bash
# 1. (Optional) Create a fresh backup on Pantheon
lando terminus backup:create <site>.<env> --element=db

# 2. Download the backup to your project root
lando terminus backup:get <site>.<env> --element=db --to=/app/d7-db.sql.gz

# 3. Create the d7_sustainability database and grant access
lando mysql -uroot -e "
  CREATE DATABASE IF NOT EXISTS d7_sustainability;
  GRANT ALL PRIVILEGES ON d7_sustainability.* TO 'root'@'%';
  FLUSH PRIVILEGES;
"

# 4. Import the dump
lando ssh -c "gunzip -c /app/d7-db.sql.gz | mysql -uroot d7_sustainability"

# 5. Verify the import
lando mysql -uroot d7_sustainability -e "SHOW TABLES;" | head -20
```

Replace `<site>.<env>` with the Pantheon site and environment, e.g.
`sustainability-d7.dev`.

---

## Step 2 — Copy D7 source files

The D7 public files must be copied into a staging directory inside the D10
public files directory **before** running the migration. The migration reads
from this directory and copies each image into `public://news/`.

**Required path:** `web/sites/default/files/d7_source/`

The D7 directory structure must be preserved. For example, a D7 file at
`public://2024/01/image.jpg` must exist at:

```
web/sites/default/files/d7_source/2024/01/image.jpg
```

After a successful migration, the staging directory can be
deleted — D10 file entities reference `public://news/` and `d7_source/` is no
longer needed.

---

## Step 3 — Run the migration

### Full run (recommended)

```bash
lando drush migrate:import --group=ys_sn --execute-dependencies
```

### Step by step

```bash
lando drush migrate:import ys_sn_news_terms
lando drush migrate:import ys_sn_files
lando drush migrate:import ys_sn_media
lando drush migrate:import ys_sn_news
```

### Check migration status

```bash
lando drush migrate:status --group=ys_sn
```

---

## Rolling back

```bash
lando drush migrate:rollback --group=ys_sn
```

> Rolling back **deletes the D10 entities** (nodes, media, file records, terms)
> and clears the migration map tables. Physical files in `news/` are also
> deleted by Drupal's file management. Files in `d7_source/` are **not**
> affected — you do not need to re-copy them before re-running.

---

## Migration overview

The migrations run in dependency order. Use `--execute-dependencies` to have
Drush handle this automatically.

| ID | Label | Depends on | Description |
|----|-------|------------|-------------|
| `ys_sn_news_terms` | Sustainability News Terms | — | Migrates D7 `take_action_topics` taxonomy terms into the D10 `post_category` vocabulary. |
| `ys_sn_files` | Sustainability News Files | — | Migrates D7 `file_managed` records for public images into D10 `entity:file`, physically copying files from `d7_source/` to `public://news/`. |
| `ys_sn_media` | Sustainability News Media | `ys_sn_files` | Creates D10 `media:image` entities for each migrated file. Alt and title text are sourced from D7 field data (`field_image2` takes precedence over `field_news_image`). All media items are tagged *Imported from migration* in the `tags` vocabulary. |
| `ys_sn_news` | Sustainability News | `ys_sn_files`, `ys_sn_media`, `ys_sn_news_terms` | Migrates D7 `news` nodes to D10 `post` nodes, including Layout Builder sections with inline image and text blocks. |

### Field mapping

| D7 field | D10 field | Notes |
|----------|-----------|-------|
| `title` | `title` | |
| `field_date` | `field_publish_date` | Converted from datetime to date |
| `field_link_to_external_story` | `field_external_source` | URL only; 123 nodes |
| `field_image2` / `field_news_image` | `field_teaser_media` | `field_image2` preferred; 199 / 132 nodes respectively |
| `body/summary` | `field_teaser_text` | 156 nodes; format: `heading_html` |
| `field_take_action_topic` | `field_category` | 161 nodes; looked up from `ys_sn_news_terms` |
| `body/value` | Layout Builder text block | 440 nodes; inline block created per node |
| `field_image2` / `field_news_image` | Layout Builder image block | Inline block; `field_image2` preferred |
| `status` | `moderation_state` | `0` → `draft`, `1` → `published` |
| `field_feature_on_homepage` | — | Skipped (unused since 2022) |
| `metatags` | — | Skipped |
| `redirect` | — | Skipped |
| Author | `uid` | Hardcoded to `uid = 1` (admin) |

