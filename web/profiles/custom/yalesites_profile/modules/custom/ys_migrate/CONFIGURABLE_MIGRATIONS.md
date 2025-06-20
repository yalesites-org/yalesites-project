# Configurable Block Migrations

This document explains how to use the enhanced ys_migrate module to create migrations that can configure any block type and organize them into Layout Builder sections.

## Overview

The enhanced migration system provides:

1. **Generic block creation** - Create any block type through YAML configuration
2. **Dynamic field processing** - Automatically handle field types and validation
3. **Flexible Layout Builder sections** - Configure multiple blocks within regions
4. **Reusable components** - Mix migration-sourced and existing blocks

## Block Creation Migration

### Basic Structure

```yaml
id: my_block_migration
label: 'My Block Migration'
migration_group: my_group

source:
  plugin: block_content_source
  blocks:
    - id: unique_block_id
      type: block_content_type
      info: 'Administrative Label'
      reusable: 0  # 0 = not reusable, 1 = reusable
      fields:
        field_name: field_value
        # ... more fields

process:
  type: type
  info: info
  reusable: reusable
  'field_*':
    plugin: process_block_fields
    source: fields

destination:
  plugin: 'entity:block_content'
```

### Field Value Examples

#### Text Fields
```yaml
field_text:
  value: '<p>Your HTML content here</p>'
  format: 'basic_html'  # or 'restricted_html', 'full_html'

# Simple text
field_heading: 'Your Heading Text'
```

#### Boolean Fields
```yaml
field_enable_animation: true
field_overlay_png: false
```

#### Link Fields
```yaml
field_link:
  uri: 'https://example.com'
  title: 'Link Text'

# Multiple links
field_links:
  - uri: 'https://example.com/page1'
    title: 'Page 1'
  - uri: 'https://example.com/page2'
    title: 'Page 2'
```

#### List/Select Fields
```yaml
field_style_color: 'blue'
field_style_alignment: 'center'
field_heading_level: 'h2'
```

#### Media References
```yaml
# By filename (will be resolved automatically)
field_media: 'my-image.jpg'

# By media entity ID
field_media:
  target_id: 123
```

#### Paragraph Fields (Complex)
```yaml
field_accordion_items:
  - type: accordion_item
    fields:
      field_heading: 'Question 1'
      field_text:
        value: '<p>Answer 1</p>'
        format: 'basic_html'
  - type: accordion_item
    fields:
      field_heading: 'Question 2'
      field_text:
        value: '<p>Answer 2</p>'
        format: 'basic_html'
```

## Layout Builder Migration

### Basic Structure

```yaml
id: my_page_migration
label: 'My Page Migration'
migration_dependencies:
  required:
    - my_block_migration

source:
  plugin: embedded_data  # or your source plugin
  data_rows:
    - id: page_1
      title: 'Page Title'
      # ... other source data

process:
  # ... standard node fields
  
  layout_builder__layout:
    plugin: configurable_layout_builder
    source: id  # or whatever identifies your source
    sections:
      - layout: layout_name
        layout_settings:
          label: 'Section Label'
          # ... layout-specific settings
        regions:
          region_name:
            - type: block_type
              source: migration|existing|create
              # ... block configuration

destination:
  plugin: 'entity:node'
  default_bundle: page
```

### Section Configuration

#### Available Layouts
- `layout_onecol` - Single column
- `layout_twocol` - Two columns (50/50)
- `layout_twocol_section` - Two columns with full-width header/footer
- `layout_threecol_section` - Three columns with full-width header/footer
- `layout_threecol_25_50_25` - Three columns (25/50/25)

#### Layout Settings Examples
```yaml
# Two column layout
layout_settings:
  label: 'Content Section'
  column_widths: '50-50'  # or '33-67', '67-33'

# Three column layout  
layout_settings:
  label: 'Feature Section'
  column_widths: '25-50-25'
```

### Block Source Types

#### From Migration
```yaml
- type: text
  source: migration
  migration_id: my_block_migration
  source_id: my_block_id  # ID from blocks migration
  view_mode: full
  label_display: false
```

#### Existing Block
```yaml
- type: text
  source: existing
  block_id: 123  # Existing block_content entity ID
  view_mode: full
```

#### Inline Creation (Future)
```yaml
- type: text
  source: create
  fields:
    field_text:
      value: '<p>Inline block content</p>'
      format: 'basic_html'
```

### Complete Example

```yaml
sections:
  # Hero section
  - layout: layout_onecol
    layout_settings:
      label: 'Hero Banner'
    regions:
      content:
        - type: grand_hero
          source: migration
          migration_id: site_blocks
          source_id: homepage_hero
          view_mode: full
          
  # Main content area
  - layout: layout_twocol_section
    layout_settings:
      label: 'Main Content'
      column_widths: '67-33'
    regions:
      first:
        - type: text
          source: migration
          migration_id: site_blocks
          source_id: main_content
        - type: accordion
          source: migration
          migration_id: site_blocks
          source_id: faq_accordion
      second:
        - type: callout
          source: existing
          block_id: 456
        - type: quick_links
          source: migration
          migration_id: site_blocks
          source_id: sidebar_links
          
  # Call to action
  - layout: layout_onecol
    regions:
      content:
        - type: cta_banner
          source: migration
          migration_id: site_blocks
          source_id: signup_cta
          component_settings:
            label_display: true
```

## Available Block Types

The system supports all 30+ block types:

### Content Blocks
- `text` - Basic text content
- `image` - Single image with caption
- `video` - Video embed
- `embed` - External embed (social media, etc.)
- `gallery` - Image gallery
- `facts` - Statistics/facts display
- `pull_quote` - Highlighted quote

### Interactive Blocks
- `accordion` - Collapsible content sections
- `tabs` - Tabbed content
- `callout` - Highlighted callout boxes

### Layout Blocks
- `grand_hero` - Full-width hero section
- `image_banner` - Image with overlay text
- `video_banner` - Video with overlay
- `cta_banner` - Call-to-action banner
- `content_spotlight` - Featured content
- `content_spotlight_portrait` - Portrait-oriented spotlight
- `divider` - Section divider
- `tiles` - Grid of tiles
- `wrapped_image` - Text-wrapped image
- `wrapped_text_callout` - Text-wrapped callout

### Navigation Blocks
- `button_link` - Single button
- `quick_links` - List of links
- `link_grid` - Grid of links
- `reference_card` - Reference/citation card

### List Blocks
- `custom_cards` - Customizable card grid
- `media_grid` - Grid of media items
- `post_list` - Blog post listings
- `event_list` - Event listings
- `directory` - People directory

### Utility Blocks
- `inline_message` - Alert/message box
- `quote_callout` - Quote with attribution
- `view` - Drupal view embed
- `webform` - Form embed

## Running Migrations

```bash
# Run block creation first
lando drush migrate-import my_block_migration

# Then run page/node migration
lando drush migrate-import my_page_migration

# Run entire group
lando drush migrate-import --group=my_group

# Reset and re-run
lando drush migrate-reset my_migration
lando drush migrate-import my_migration
```

## Tips and Best Practices

1. **Create blocks first** - Always migrate blocks before pages that reference them
2. **Use descriptive IDs** - Block IDs should be descriptive for easy reference
3. **Test field types** - Verify field configurations match your block definitions
4. **Group related migrations** - Use migration groups for logical organization
5. **Handle dependencies** - Ensure media, paragraphs, and taxonomy are migrated first
6. **Validate configurations** - Test with small datasets before full migration

## Troubleshooting

### Common Errors

**Block not found**
- Check migration dependencies
- Verify block ID matches between migrations
- Ensure block migration completed successfully

**Field validation errors**
- Check field type definitions in block configuration
- Verify required fields are provided
- Check field cardinality (single vs multiple values)

**Layout Builder errors**
- Verify layout plugin exists
- Check region names match layout definition
- Ensure all referenced blocks exist

### Debugging

```bash
# Check migration status
lando drush migrate-status

# View migration messages
lando drush migrate-messages my_migration

# Reset specific migration
lando drush migrate-reset my_migration

# Import with verbose output
lando drush migrate-import my_migration -v
```