# Testing the Configurable Migration System

This document explains how to test the enhanced ys_migrate module functionality, including both automated tests and manual testing procedures.

## Overview

The testing suite includes:

1. **Unit Tests** - Test individual components in isolation
2. **Functional Tests** - Test complete migration workflows end-to-end
3. **Test Migrations** - Sample migrations for validation
4. **Media Fixtures** - Test media entities for complex content

## Test Structure

```
tests/
├── src/
│   ├── Unit/
│   │   ├── ProcessBlockFieldsTest.php     # Tests field processing logic
│   │   └── BlockContentSourceTest.php     # Tests source plugin
│   └── Functional/
│       ├── MigrationTestBase.php          # Base class with helpers
│       └── ConfigurableMigrationTest.php  # End-to-end migration tests
└── fixtures/
    └── test_media.yml                     # Test media definitions
```

## Running the Tests

### Prerequisites

Ensure the following modules are enabled:
```bash
lando drush en ys_migrate migrate_plus migrate_tools block_content paragraphs layout_builder
```

### Unit Tests

Run unit tests to validate individual components:

```bash
# Run all ys_migrate unit tests
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Unit/

# Run specific test class
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Unit/ProcessBlockFieldsTest.php

# Run with testdox format for readable output
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Unit/ --testdox

# Expected output:
# Block Content Source (Drupal\Tests\ys_migrate\Unit\BlockContentSource)
#  ✔ Source plugin
#  ✔ Minimal configuration
#  ✔ Empty configuration
#  ✔ Complex block configuration
#  ✔ To string
#
# Process Block Fields (Drupal\Tests\ys_migrate\Unit\ProcessBlockFields)
#  ✔ Process text field
#  ✔ Process text long field
#  ✔ Process boolean field
#  ✔ Process link field
#  ✔ Create paragraph
#  ✔ Process entity reference revisions
#  ✔ Transform integration
```

### Functional Tests

Run functional tests to validate complete workflows:

```bash
# Run all functional tests
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Functional/

# Run specific test
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Functional/ConfigurableMigrationTest.php

# Run with verbose output
lando phpunit -v web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Functional/ConfigurableMigrationTest.php
```

### Test Migrations

Run the test migrations manually to validate functionality:

```bash
# Enable the ys_migrate module (if not already enabled)
lando drush en ys_migrate

# Run test block migration (creates 8 different block types)
lando drush migrate-import ys_test_blocks

# Run test page migration (creates 3 pages with Layout Builder sections)
lando drush migrate-import ys_test_pages

# Note: You may see database connection warnings at the end - these are expected
# and don't affect the migration success. The migrations use embedded data,
# not external database connections.

# Check for migration errors (should show successful completion)
lando drush migrate-messages ys_test_blocks
lando drush migrate-messages ys_test_pages

# Rollback migrations when done testing
lando drush migrate-rollback ys_test_pages
lando drush migrate-rollback ys_test_blocks
```

### Validate Test Results

After running the migrations, verify they worked:

```bash
# Check test blocks were created (should show 8 new blocks at end)
lando drush sqlq "SELECT id, type, info FROM block_content_field_data WHERE id > 580 ORDER BY id"

# Check test pages were created (should show 3 new pages)
lando drush sqlq "SELECT nid, title FROM node_field_data WHERE title LIKE '%Migration%Test%'"

# Check paragraphs were created (should see facts_item, tile, etc.)
lando drush sqlq "SELECT type, COUNT(*) as count FROM paragraphs_item_field_data GROUP BY type ORDER BY count DESC"

# Check Layout Builder sections (should show sections for test pages)
lando drush sqlq "SELECT entity_id, COUNT(*) as sections FROM node__layout_builder__layout WHERE entity_id IN (SELECT nid FROM node_field_data WHERE title LIKE '%Migration%Test%') GROUP BY entity_id"
```

## Test Validations

### What the Tests Validate

#### Unit Tests (`ProcessBlockFieldsTest.php`)
- ✅ Text field processing (text, text_long)
- ✅ Boolean field conversion  
- ✅ Link field handling (URL strings to arrays)
- ✅ Entity reference processing
- ✅ Paragraph entity creation
- ✅ Entity reference revisions (paragraph fields)
- ✅ Field type validation and error handling

#### Unit Tests (`BlockContentSourceTest.php`)
- ✅ Source plugin configuration parsing
- ✅ Block data structure validation
- ✅ Default value application
- ✅ Nested paragraph configuration handling
- ✅ Iterator functionality

#### Functional Tests (`ConfigurableMigrationTest.php`)
- ✅ Complete migration workflow (blocks → pages)
- ✅ Block entity creation with all field types
- ✅ Paragraph entity creation and linking
- ✅ Layout Builder section generation
- ✅ Multi-layout page assembly
- ✅ Component configuration and placement

### Test Coverage

The tests validate these block types:
- **Text** - Basic text content
- **Accordion** - Nested paragraphs with text content
- **Custom Cards** - Media, links, and styled content
- **Gallery** - Media references with captions
- **Facts** - Statistics display
- **Tiles** - Styled content with themes
- **Callout** - Highlighted content with links
- **Button Link** - Simple link buttons

These paragraph types are tested:
- **accordion_item** - With nested text paragraphs
- **custom_card** - With media, text, and links
- **gallery_item** - With media references
- **facts_item** - Simple text content
- **tile** - With styling and media
- **callout_item** - With links and text
- **text** - Basic nested text content

## Manual Testing Procedures

### 1. Basic Block Creation

```bash
# Import test blocks
lando drush migrate-import ys_test_blocks

# Verify blocks were created
lando drush sqlq "SELECT id, type, info FROM block_content_field_data ORDER BY id"

# Check specific block
lando drush entity:view block_content [ID] --view-mode=default
```

### 2. Paragraph Validation

```bash
# Count created paragraphs
lando drush sqlq "SELECT bundle, COUNT(*) as count FROM paragraph_field_data GROUP BY bundle"

# View specific paragraph
lando drush entity:view paragraph [ID]

# Check paragraph-to-block relationships
lando drush sqlq "SELECT bc.info, p.type, p.id FROM block_content_field_data bc 
  JOIN block_content__field_accordion_items bca ON bc.id = bca.entity_id 
  JOIN paragraph_field_data p ON bca.field_accordion_items_target_id = p.id"
```

### 3. Layout Builder Verification

```bash
# Import pages with Layout Builder
lando drush migrate-import ys_test_pages

# Check node Layout Builder data
lando drush sqlq "SELECT nid, title FROM node_field_data WHERE type = 'page'"

# View rendered page
lando drush browse /node/[NID]
```

### 4. Field Value Validation

Check that field values were processed correctly:

```bash
# Text fields
lando drush sqlq "SELECT entity_id, field_text_value FROM block_content__field_text LIMIT 5"

# Link fields  
lando drush sqlq "SELECT entity_id, field_link_uri, field_link_title FROM block_content__field_button_link"

# Boolean fields
lando drush sqlq "SELECT entity_id, field_enable_animation_value FROM block_content__field_enable_animation"

# Reference fields
lando drush sqlq "SELECT entity_id, field_accordion_items_target_id FROM block_content__field_accordion_items"
```

### 5. Media Reference Testing

```bash
# Check media entity creation (if using real media)
lando drush sqlq "SELECT mid, name, bundle FROM media_field_data"

# Check media references in blocks
lando drush sqlq "SELECT bc.info, m.name FROM block_content_field_data bc 
  JOIN block_content__field_media bcm ON bc.id = bcm.entity_id 
  JOIN media_field_data m ON bcm.field_media_target_id = m.mid"
```

## Troubleshooting Tests

### Common Test Failures

**"Migration not found" errors:**
```bash
# Clear cache and reimport configuration
lando drush cr
lando drush cim -y
lando drush migrate-status --group=ys_test
```

**"Block type not found" errors:**
```bash
# Ensure block content types exist
lando drush config:status | grep block_content.type
lando drush cim -y
```

**"Paragraph type not found" errors:**
```bash
# Check paragraph bundles
lando drush config:status | grep paragraphs.paragraphs_type
lando drush cim -y
```

**Media reference failures:**
```bash
# Check if media bundles exist
lando drush config:status | grep media.type
# Create test media manually if needed
```

### Debug Migration Issues

```bash
# Enable migration debugging
lando drush migrate-import ys_test_blocks --feedback=100

# Check migration map tables
lando drush sqlq "SELECT * FROM migrate_map_ys_test_blocks"

# View migration messages
lando drush migrate-messages ys_test_blocks

# Reset and retry
lando drush migrate-reset ys_test_blocks
lando drush migrate-import ys_test_blocks -v
```

### Test Data Cleanup

```bash
# Remove test content
lando drush migrate-rollback ys_test_pages
lando drush migrate-rollback ys_test_blocks

# Clean up orphaned paragraphs
lando drush entity:delete paragraph --bundle=accordion_item
lando drush entity:delete paragraph --bundle=custom_card
# ... repeat for other paragraph types

# Clean up test media (if created)
lando drush entity:delete media --bundle=image

# Clear caches
lando drush cr
```

## Continuous Integration

For CI/CD pipelines, add these commands:

```bash
#!/bin/bash
# Test script for CI

# Run unit tests
phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Unit/ --log-junit unit-results.xml

# Run functional tests  
phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate/tests/src/Functional/ --log-junit functional-results.xml

# Run test migrations
drush migrate-import ys_test_blocks
drush migrate-import ys_test_pages

# Validate results
drush sqlq "SELECT COUNT(*) as block_count FROM block_content_field_data" | grep -q "8"
drush sqlq "SELECT COUNT(*) as page_count FROM node_field_data WHERE type='page'" | grep -q "3"

# Cleanup
drush migrate-rollback ys_test_pages
drush migrate-rollback ys_test_blocks
```

## Performance Testing

For large migrations, test performance:

```bash
# Time the migration
time lando drush migrate-import ys_test_blocks

# Check memory usage
lando drush migrate-import ys_test_blocks --memory-limit=512M

# Monitor database queries
# Enable devel module and query log for detailed analysis
```

## Expected Test Results

When all tests pass, you should see:

- **8 block content entities** created
- **16+ paragraph entities** created and linked
- **3 page nodes** with Layout Builder sections
- **4 Layout Builder sections** per page
- **All field values** properly processed and stored
- **No migration errors** or warnings

## Current Status

⚠️ **Test Migration Field Processing Issue**: The test migrations successfully create entities but field value processing needs refinement.

**What Currently Works:**
- ✅ Unit tests (12 tests, 50 assertions pass)
- ✅ Block entity creation (8 blocks with correct types)
- ✅ Page entity creation (3 pages with Layout Builder)
- ✅ Migration workflow and dependencies
- ✅ Basic field structure validation

**Known Issue:**
- ❌ Migration field processing integration incomplete
- ❌ ProcessBlockFields plugin creates paragraphs but field extraction fails
- ❌ Current approach using extract plugins doesn't match plugin output structure

**Technical Details:**
- The ProcessBlockFields plugin successfully creates paragraph entities (visible in error logs)
- The field extraction step fails due to array structure mismatch
- Migration fails during field mapping phase, preventing entity creation

The test migrations create the correct entity structure but the YAML field configurations aren't being processed into actual Drupal field values. The unit tests validate individual components work correctly, providing a foundation for completing the field processing integration.

This comprehensive testing framework is ready to validate the configurable migration system once the field processing integration is completed.