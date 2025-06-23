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

### ProcessMediaField Plugin

**Location**: `src/Plugin/migrate/process/ProcessMediaField.php`

Smart media migration with reuse and alt text enforcement.

**Features**:
- **Smart Media Reuse**: Checks for existing media by filename before creating new entities
- **Alt Text Enforcement**: Ensures all images have alt text with helpful placeholder defaults
- **Multi-format Support**: Handles images, videos, audio, and documents
- **External Download**: Downloads media from URLs and creates local file entities
- **Accessibility Focus**: Enforces alt text with admin-friendly placeholder when missing

**Alt Text Strategy**:
```yaml
# Good: Proper alt text provided
- url: 'path/to/image.jpg'
  alt: 'Descriptive alt text for accessibility'

# Fallback: Missing alt text gets helpful placeholder
- url: 'path/to/mountain_photo.jpg'
  # Results in: "[ALT TEXT NEEDED] Image: mountain photo"
```

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

**ProcessMediaField Usage**:
```yaml
process:
  # Process single media item
  field_media:
    plugin: process_media_field
    source: image_data
    
  # Process array of media items
  '@processed_images':
    plugin: process_media_field
    source: gallery_images
    
  # Use in block creation
  layout_builder__layout:
    plugin: configurable_layout_builder
    sections:
      - layout: layout_onecol
        regions:
          content:
            - type: image
              source: create
              data:
                field_media: '@processed_images/0'
                field_caption: 'Migrated image with enforced alt text'
```

**Media Input Formats**:
```yaml
# Simple URL string
image_data: 'https://example.com/image.jpg'

# Full media object
image_data:
  url: 'https://example.com/photo.jpg'
  alt: 'Descriptive alt text for screen readers'
  title: 'Photo Title'
  
# Array of media items
gallery_images:
  - url: 'image1.jpg'
    alt: 'First image description'
  - url: 'image2.jpg'
    # No alt - will get "[ALT TEXT NEEDED] Image: image2"
```

**Supported Field Types**:
- `text_long`, `text_with_summary` - Text fields with format support
- `boolean` - Boolean/checkbox fields
- `link` - Link fields with URI and title
- `entity_reference_revisions` - Paragraph references (automatically creates paragraphs)
- `string`, `list_string` - Simple string values

### ConfigurableLayoutBuilder Plugin

**Location**: `src/Plugin/migrate/process/ConfigurableLayoutBuilder.php`

Enhanced Layout Builder integration supporting multiple sections and regions with prescriptive "Content Section" targeting.

**Features**:
- **Content Section Targeting**: Always appends to existing "Content Section" for safety and consistency
- **Append Mode**: Adds blocks to existing sections instead of replacing entire layouts
- Multiple section support with configurable region mapping
- Support for different block sources (migration, existing, inline)
- UUID generation for layout components
- System section protection (prevents modification of headers, footers, metadata sections)

**Safe Section Targeting Strategy** (Recommended Approach):

YaleSites uses a prescriptive approach for migrated content placement:

1. **Target Safe Content Sections**: Migrated blocks go into approved content sections only
2. **Preserve System Sections**: Never modify headers, footers, navigation, or other system sections
3. **Append, Don't Replace**: Add to existing content rather than overwriting layouts
4. **Create If Missing**: Automatically creates target section if it doesn't exist

**Approved Target Sections**:
- `Content Section` (default) - Main content area
- `Banner Section` - Hero/banner content area  
- `Title and Metadata` - Page title and metadata area

**Configuration Options**:
- `target_section`: Section to target (Content Section, Banner Section, Title and Metadata)
- `append_mode`: Set to `true` to add blocks to existing sections
- `sections`: Array of section configurations with layouts and blocks

**Why This Approach?**

- **Safety**: Prevents accidental modification of critical layout sections
- **Consistency**: Content always appears in the expected location
- **Maintainability**: Single code path reduces complexity and testing burden
- **User Experience**: Matches where content authors manually add blocks
- **Future-Proof**: New system sections won't break migrations
- **Predictable**: Developers know exactly where migrated content will appear

**Usage Examples**:

**Content Section** (Most Common):
```yaml
layout_builder__layout:
  plugin: configurable_layout_builder
  source: node_id
  target_section: 'Content Section'  # Default content area
  append_mode: true                  # Add to existing content
  sections:
    - layout: layout_onecol
      regions:
        content:
          - type: text
            source: migration
            migration_id: my_text_blocks
```

**Banner Section** (Hero Content):
```yaml
layout_builder__layout:
  plugin: configurable_layout_builder
  source: node_id
  target_section: 'Banner Section'   # Hero/banner area
  append_mode: true
  sections:
    - layout: layout_onecol
      regions:
        content:
          - type: grand_hero
            source: create
            data:
              field_heading: '@hero_title'
              field_media: '@hero_image'
```

**Title and Metadata** (Page Headers):
```yaml
layout_builder__layout:
  plugin: configurable_layout_builder
  source: node_id
  target_section: 'Title and Metadata'  # Page title area
  append_mode: true
  sections:
    - layout: layout_onecol
      regions:
        content:
          - type: text
            source: create
            data:
              field_text: '@page_subtitle'
```

**Alternative Usage** (Legacy support):
```yaml
layout_builder__layout:
  plugin: configurable_layout_builder
  source: node_id
  sections:
    - layout: layout_onecol
      layout_settings:
        label: 'Custom Section'
      regions:
        content:
          - type: accordion
            source: existing
            block_id: 123
```

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