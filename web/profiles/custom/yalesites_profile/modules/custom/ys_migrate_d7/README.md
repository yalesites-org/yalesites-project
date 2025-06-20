# YaleSites Migrate D7

Drupal 7 to Drupal 10 migration implementations for YaleSites.

## Overview

This module contains all the Drupal 7 specific migration logic and configurations. It depends on `ys_migrate_core` for the core field processing utilities and `migrate_drupal` for D7 database connectivity.

## Features

- **D7 Database Integration**: Connects to legacy Drupal 7 databases
- **ONHA Migrations**: Office of New Haven Affairs content migration
- **Content Type Mapping**: Maps D7 content types to D10 equivalents
- **Field Transformation**: Uses ys_migrate_core for consistent field processing

## Migration Groups

### ONHA (Office of New Haven Affairs)
**Location**: `config/optional/migrate_plus.migration_group.ys_onha.yml`

Handles migration of ONHA content from their legacy D7 site.

**Database Configuration**:
```yaml
shared_configuration:
  source:
    key: d7  # Must be defined in settings.php
```

**Included Migrations**:
- `ys_onha_news` - News articles and blog posts
- `ys_onha_news_terms` - News category taxonomy
- `ys_onha_programs` - Program content
- `ys_onha_program_body` - Program body content

## Database Setup

### Required Settings.php Configuration

Add to your `settings.php` file:

```php
// Drupal 7 database connection for migrations
$databases['d7'] = [
  'default' => [
    'database' => 'onha_d7_database',
    'username' => 'db_user',
    'password' => 'db_password',
    'host' => 'localhost',
    'port' => '3306',
    'driver' => 'mysql',
    'prefix' => '',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  ],
];
```

### Pantheon Environment Setup

For Pantheon environments, the D7 database connection is typically configured through environment variables or Pantheon's database tools.

## Running Migrations

### Prerequisites

1. **Enable required modules**:
   ```bash
   lando drush en ys_migrate_core ys_migrate_d7 -y
   ```

2. **Verify database connection**:
   ```bash
   lando drush sql-connect --database=d7
   ```

3. **Check migration status**:
   ```bash
   lando drush migrate-status --group=ys_onha
   ```

### Execute Migrations

**Run all ONHA migrations**:
```bash
lando drush migrate-import --group=ys_onha
```

**Run specific migration**:
```bash
lando drush migrate-import ys_onha_news
```

**Rollback migrations**:
```bash
lando drush migrate-rollback --group=ys_onha
```

## Migration Configurations

### News Articles Migration
**File**: `config/optional/migrate_plus.migration.ys_onha_news.yml`

Maps D7 news articles to D10 post content type:

```yaml
source:
  plugin: d7_node
  node_type: news
  
process:
  title: title
  body/value: body/value
  body/format: body/format
  field_categories:
    plugin: migration_lookup
    migration: ys_onha_news_terms
    source: field_categories
    
destination:
  plugin: entity:node
  default_bundle: post
```

### Field Processing Integration

Uses ys_migrate_core for consistent field processing:

```yaml
process:
  '@processed_fields':
    plugin: process_block_fields
    source: d7_field_data
  field_text:
    plugin: extract
    source: '@processed_fields'
    index:
      - field_text
```

## Content Mapping

### Node Types
| D7 Type | D10 Type | Notes |
|---------|----------|-------|
| news | post | News articles become blog posts |
| program | page | Programs become pages with custom fields |
| event | event | Direct mapping with field updates |

### Taxonomy
| D7 Vocabulary | D10 Vocabulary | Notes |
|---------------|----------------|-------|
| news_categories | categories | News categorization |
| program_types | tags | Program classification |

### Fields
| D7 Field | D10 Field | Processing |
|----------|-----------|------------|
| body | field_text | Via ProcessBlockFields |
| field_image | field_media | Media reference conversion |
| field_tags | field_categories | Taxonomy reference |

## Troubleshooting

### Common Issues

**Database Connection Errors**:
```
Error: The specified database connection is not defined: d7
```
**Solution**: Verify D7 database configuration in settings.php

**Migration Plugin Not Found**:
```
Error: Migration ys_onha_news does not exist
```
**Solution**: 
```bash
lando drush cr
lando drush config-import
```

**Field Processing Errors**:
```
Error: Field field_text could not be processed
```
**Solution**: Check that ys_migrate_core is enabled and field definitions exist

### Debug Commands

**Check migration configuration**:
```bash
lando drush config-get migrate_plus.migration.ys_onha_news
```

**View migration messages**:
```bash
lando drush migrate-messages ys_onha_news
```

**Reset migration status**:
```bash
lando drush migrate-reset-status ys_onha_news
```

## Dependencies

- `migrate_drupal` - Core D7 migration support
- `ys_migrate_core` - Field processing utilities
- `migrate` - Base migration framework
- `migrate_plus` - Extended migration tools

## Adding New D7 Migrations

1. **Create migration configuration**:
   ```yaml
   # config/optional/migrate_plus.migration.my_d7_content.yml
   id: my_d7_content
   migration_group: ys_onha
   source:
     plugin: d7_node
     node_type: my_content_type
   process:
     # Use ys_migrate_core plugins
     '@processed_fields':
       plugin: process_block_fields
       source: field_data
   destination:
     plugin: entity:node
   ```

2. **Test the migration**:
   ```bash
   lando drush migrate-import my_d7_content --limit=1
   ```

3. **Add to migration group** if needed

## Security Considerations

- **Database Credentials**: Store D7 database credentials securely
- **Content Sanitization**: Ensure migrated content is properly sanitized
- **User Permissions**: Verify user role mappings are appropriate
- **File Security**: Check file permissions and access controls

## Performance Optimization

- **Batch Processing**: Use `--limit` flag for large migrations
- **Memory Limits**: Increase PHP memory for large datasets
- **Database Indexing**: Ensure D7 source database is properly indexed
- **Caching**: Clear caches regularly during migration development