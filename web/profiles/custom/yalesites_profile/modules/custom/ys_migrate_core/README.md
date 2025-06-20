# YaleSites Migrate Core

Core migration utilities for block and content processing, independent of any specific Drupal version.

## Overview

This module provides the foundational migration tools that can be used across different migration scenarios - whether migrating from Drupal 7, importing from external APIs, or transforming existing content. It contains no dependencies on specific source systems.

## Key Components

### ProcessBlockFields Plugin

**Location**: `src/Plugin/migrate/process/ProcessBlockFields.php`

The core field processing engine that handles all YaleSites block types dynamically.

**Features**:
- Automatic field type detection and processing
- Paragraph entity creation for complex block types
- Support for text, boolean, link, and entity reference fields
- Configurable field mapping and transformation

**Usage Example**:
```yaml
process:
  '@processed_fields':
    plugin: process_block_fields
    source: fields
  field_text:
    plugin: extract
    source: '@processed_fields'
    index:
      - field_text
```

**Supported Field Types**:
- `text_long`, `text_with_summary` - Text fields with format support
- `boolean` - Boolean/checkbox fields
- `link` - Link fields with URI and title
- `entity_reference_revisions` - Paragraph references (automatically creates paragraphs)
- `string`, `list_string` - Simple string values

### ConfigurableLayoutBuilder Plugin

**Location**: `src/Plugin/migrate/process/ConfigurableLayoutBuilder.php`

Enhanced Layout Builder integration supporting multiple sections and regions.

**Features**:
- Multiple section support
- Configurable region mapping
- Support for different block sources (migration, existing, inline)
- UUID generation for layout components

### BlockContentSource Plugin

**Location**: `src/Plugin/migrate/source/BlockContentSource.php`

Generic source plugin for YAML-driven block creation.

**Features**:
- Embedded data source for testing
- Flexible block configuration structure
- Support for all block types through YAML

## Testing

### Unit Tests

**Location**: `tests/src/Unit/ProcessBlockFieldsTest.php`

Comprehensive unit tests covering:
- Text field processing
- Boolean field handling
- Link field transformation
- Paragraph creation
- Error handling

**Run Tests**:
```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_migrate_core/tests/src/Unit/ProcessBlockFieldsTest.php
```

## Dependencies

- `migrate` - Core Drupal migration framework
- `migrate_plus` - Extended migration functionality
- `block_content` - Block content entity type
- `paragraphs` - Paragraph entity type

## Usage in Other Modules

### For D7 Migrations (ys_migrate_d7)
```yaml
dependencies:
  - ys_migrate_core

process:
  '@processed_fields':
    plugin: process_block_fields
    source: d7_block_data
```

### For Testing Tools (ys_migrate_tools)
```yaml
dependencies:
  - ys_migrate_core

source:
  plugin: block_content_source
  blocks:
    - id: test_block
      type: text
      fields:
        field_text:
          value: '<p>Test content</p>'
          format: 'basic_html'
```

### For External Integrations
```php
// Use ProcessBlockFields in custom code
$plugin = \Drupal::service('plugin.manager.migrate.process')
  ->createInstance('process_block_fields', []);

$result = $plugin->transform($fields, $executable, $row, 'fields');
```

## Architecture Design

This module follows the **Single Responsibility Principle** - it provides migration utilities without being tied to any specific source system. This allows:

- **Reusability**: Other projects can use these utilities
- **Testability**: Core logic can be unit tested in isolation  
- **Maintainability**: Changes to source systems don't affect core processing
- **Extensibility**: New field types and processing logic can be added centrally

## Block Type Support

Supports all 30+ YaleSites block types including:

**Layout Blocks**: Grand Hero, Image Banner, Content Spotlight
**Interactive Blocks**: Accordion, Tabs, Gallery, Video
**Content Blocks**: Text, Callout, Pull Quote, Facts
**Navigation Blocks**: Quick Links, Link Grid, Custom Cards
**Media Blocks**: Image, Video, Media Grid, Embed
**Functional Blocks**: Directory, Post List, Event List, Webform

## Configuration Examples

### Simple Text Block
```yaml
source:
  plugin: block_content_source
  blocks:
    - id: simple_text
      type: text
      info: 'Simple Text Block'
      fields:
        field_text:
          value: '<p>Content here</p>'
          format: 'basic_html'
        field_style_variation: 'default'
```

### Complex Accordion Block
```yaml
source:
  plugin: block_content_source
  blocks:
    - id: accordion_block
      type: accordion
      info: 'FAQ Accordion'
      fields:
        field_heading: 'Frequently Asked Questions'
        field_accordion_items:
          - type: accordion_item
            fields:
              field_heading: 'Question 1'
              field_content:
                - type: text
                  fields:
                    field_text:
                      value: '<p>Answer 1</p>'
                      format: 'basic_html'
```

## Contributing

When adding new field processing logic:

1. Add the field type handler in `ProcessBlockFields::processField()`
2. Add corresponding unit tests
3. Update this documentation with examples
4. Ensure backward compatibility

## Version History

- **1.0.0**: Initial release with core field processing
- **1.1.0**: Added ConfigurableLayoutBuilder support
- **1.2.0**: Enhanced paragraph creation logic