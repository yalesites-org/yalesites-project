# YaleSites Migrate Tools

Development and testing tools for YaleSites migrations, including content cloning and test migrations.

## Overview

This module provides development utilities and testing tools for migration work. It depends only on `ys_migrate_core` and does NOT require `migrate_drupal`, making it perfect for development environments and testing scenarios.

## Features

- **Content Cloning**: Duplicate existing content for testing
- **Test Migrations**: Validate migration logic without external dependencies
- **Development Utilities**: Tools for migration development and debugging
- **D10 Source Plugin**: Read from existing D10 content as migration source

## Key Components

### D10BlockContent Source Plugin

**Location**: `src/Plugin/migrate/source/D10BlockContent.php`

Reads existing Drupal 10 block content and converts it to migration source data.

**Features**:
- Reads from current site's block_content tables
- Extracts all field data including paragraphs
- Converts entities to migration-friendly arrays
- Filters by block types

**Usage**:
```yaml
source:
  plugin: d10_block_content
  block_types:
    - text
    - accordion
    - custom_cards
```

## Migration Groups

### ys_field_tests

**Location**: `config/optional/migrate_plus.migration_group.ys_field_tests.yml`

Testing migration group that doesn't require external database connections.

**Features**:
- No D7 database dependency
- Self-contained test migrations
- Useful for development and CI/CD

## Available Migrations

### Clone Existing Blocks

**File**: `config/optional/migrate_plus.migration.ys_clone_existing_blocks.yml`

Creates copies of existing blocks with field processing validation.

**Purpose**:
- Test field processing logic on real data
- Create development content
- Validate migration transformations

**Configuration**:
```yaml
source:
  plugin: d10_block_content
  block_types:
    - text
    - custom_cards
    - accordion
    
process:
  info:
    plugin: concat
    source:
      - constants/clone_prefix
      - info
    delimiter: ' '
      
  '@processed_fields':
    plugin: process_block_fields
    source: '@block_data'
```

## Development Workflows

### Testing Field Processing

1. **Create test content** manually in the UI
2. **Run clone migration** to test field processing:
   ```bash
   lando drush migrate-import ys_clone_existing_blocks --limit=1
   ```
3. **Compare original vs cloned** content
4. **Validate field transformations**

### Content Development

1. **Create base content** in production/staging
2. **Clone to development** environment:
   ```bash
   lando drush migrate-import ys_clone_existing_blocks
   ```
3. **Develop against realistic** content

### Migration Testing

1. **Enable only tools module**:
   ```bash
   lando drush en ys_migrate_core ys_migrate_tools -y
   ```
2. **Run test migrations** without D7 dependencies
3. **Validate core functionality**

## Usage Examples

### Basic Content Cloning

```bash
# Clone specific block types
lando drush migrate-import ys_clone_existing_blocks

# Check what was created
lando drush migrate-messages ys_clone_existing_blocks
```

### Custom Test Migration

Create a new test migration:

```yaml
# my_test_migration.yml
id: my_test_blocks
migration_group: ys_field_tests

source:
  plugin: embedded_data
  data_rows:
    - id: test_1
      type: text
      info: 'Test Block'
      field_text_value: '<p>Test content</p>'
      field_text_format: 'basic_html'

process:
  type: type
  info: info
  field_text/value: field_text_value
  field_text/format: field_text_format

destination:
  plugin: 'entity:block_content'
```

### Field Processing Validation

Test complex field processing:

```yaml
process:
  # Test the core field processor
  '@processed_fields':
    plugin: process_block_fields
    source: block_data
    
  # Validate specific field extraction
  field_accordion_items:
    plugin: extract
    source: '@processed_fields'
    index:
      - field_accordion_items
```

## Development Commands

### Status and Debugging

```bash
# Check available migrations
lando drush migrate-status --group=ys_field_tests

# View migration configuration
lando drush config-get migrate_plus.migration.ys_clone_existing_blocks

# Check for errors
lando drush migrate-messages ys_clone_existing_blocks

# Reset if needed
lando drush migrate-reset-status ys_clone_existing_blocks
```

### Content Management

```bash
# Create test content
lando drush migrate-import ys_clone_existing_blocks --limit=5

# Remove test content
lando drush migrate-rollback ys_clone_existing_blocks

# Update existing content
lando drush migrate-import ys_clone_existing_blocks --update
```

## Testing Scenarios

### Unit Testing Integration

The tools module works great with the core unit tests:

```bash
# Test core functionality
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate_core/tests/

# Test with real data via tools
lando drush migrate-import ys_clone_existing_blocks --limit=1
```

### Block Type Coverage

Test all major block types:

```yaml
source:
  plugin: d10_block_content
  block_types:
    # Layout blocks
    - grand_hero
    - image_banner
    - content_spotlight
    
    # Interactive blocks  
    - accordion
    - tabs
    - gallery
    
    # Content blocks
    - text
    - callout
    - pull_quote
    
    # And so on...
```

### Field Type Testing

Validate different field processing scenarios:

- **Simple fields**: text, boolean, string
- **Complex fields**: links with title/URI
- **Reference fields**: paragraphs, media, taxonomy
- **Nested structures**: accordion items, card collections

## CI/CD Integration

### Automated Testing

```bash
#!/bin/bash
# migration-test.sh

# Enable modules
lando drush en ys_migrate_core ys_migrate_tools -y

# Run unit tests
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate_core/tests/

# Test real migration
lando drush migrate-import ys_clone_existing_blocks --limit=1

# Validate results
lando drush migrate-status --group=ys_field_tests
```

### Environment Setup

The tools module is perfect for setting up consistent development environments:

```bash
# Setup script
lando drush en ys_migrate_tools -y
lando drush migrate-import ys_clone_existing_blocks
# Now developers have realistic test content
```

## Dependencies

- `ys_migrate_core` - Core field processing utilities
- `migrate` - Base migration framework  
- `migrate_plus` - Extended migration tools

**NO dependency on**:
- `migrate_drupal` - Not needed for tools
- External databases - All self-contained

## Troubleshooting

### Common Issues

**No source data**:
```
Error: No items to process
```
**Solution**: Create some block content first, then run the clone migration

**Field processing errors**:
```
Error: ProcessBlockFields plugin not found
```
**Solution**: Ensure ys_migrate_core is enabled

**Memory issues with large clones**:
```bash
# Limit the migration
lando drush migrate-import ys_clone_existing_blocks --limit=10
```

### Debug Mode

Enable detailed logging:

```bash
lando drush migrate-import ys_clone_existing_blocks --debug
```

## Future Enhancements

- **Content generators**: Create realistic test content
- **Performance testing**: Large-scale migration testing
- **Field comparison tools**: Validate migration accuracy
- **Rollback utilities**: Sophisticated cleanup tools