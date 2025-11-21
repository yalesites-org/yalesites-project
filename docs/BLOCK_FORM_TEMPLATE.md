# Block Form Three-Tab Template Structure

## Overview
This document provides the standardized template for implementing the three-tab structure (Content → Design → Advanced) across all Layout Builder block forms in YaleSites.

## Field Categorization Rules

### Content Tab
**Purpose**: Contains all content-related fields that define what the block displays
**Fields Include**:
- Text content: `field_heading`, `field_subheading`, `field_text`, `field_caption`
- Media: `field_media`, `field_image`, background media
- Links: `field_link`, `field_link_two`, `field_cta`
- References: `field_content_ref`, `field_view_ref`
- Lists/Collections: paragraph fields, entity references

### Design Tab
**Purpose**: Houses all visual design and styling options
**Fields Include**:
- Colors: `field_style_color`, theme selections
- Layout: `field_style_alignment`, `field_style_position`
- Variations: `field_style_variation`, `field_style_theme`
- Visual options: `field_overlay_png`, `field_replace_heading_with_image`
- Spacing/sizing: padding, margin, width controls

### Advanced Tab
**Purpose**: Edge case and power-user settings (conditional display)
**Fields Include**:
- Semantic markup: `field_heading_level`
- Instructions: `field_instructions` (markup fields)
- Administrative: `info`, `revision_log`
- Technical settings: advanced configuration options
- Developer tools: custom CSS classes, data attributes

## Field Group Configuration Template

```yaml
third_party_settings:
  field_group:
    group_block_tabs:
      children:
        - group_content_tab
        - group_design_tab
        - group_advanced_tab
      label: 'Block tabs'
      region: content
      parent_name: ''
      weight: 0
      format_type: tabs
      format_settings:
        classes: ''
        show_empty_fields: false
        id: ''
        direction: horizontal
        width_breakpoint: 640

    group_content_tab:
      children:
        - [content fields here]
      label: Content
      region: content
      parent_name: group_block_tabs
      weight: 1
      format_type: tab
      format_settings:
        classes: ''
        show_empty_fields: false
        id: ''
        formatter: closed
        description: ''
        required_fields: false
        open: true
        weight: -10

    group_design_tab:
      children:
        - [design fields here]
      label: Design
      region: content
      parent_name: group_block_tabs
      weight: 2
      format_type: tab
      format_settings:
        classes: ''
        show_empty_fields: false
        id: ''
        formatter: closed
        description: ''
        required_fields: false

    group_advanced_tab:
      children:
        - [advanced fields here]
      label: Advanced
      region: content
      parent_name: group_block_tabs
      weight: 3
      format_type: tab
      format_settings:
        classes: ''
        show_empty_fields: false
        id: ''
        formatter: closed
        description: ''
        required_fields: false
```

## Implementation Examples

### Content Spotlight Portrait
**Content Tab**: `field_heading`, `field_subheading`, `field_text`, `field_caption`, `field_media`, `field_link`, `field_link_two`
**Design Tab**: `field_style_alignment`, `field_style_color`, `field_style_position`, `field_style_variation`
**Advanced Tab**: `field_heading_level`, `field_instructions`, `info`, `revision_log`

### Grand Hero
**Content Tab**: `field_heading`, `field_text`, `field_link`, `field_link_two`, `field_media`
**Design Tab**: `field_style_color`, `field_style_position`, `field_style_variation`, `field_overlay_png`, `field_replace_heading_with_image`
**Advanced Tab**: `field_heading_level`, `field_instructions`, `info`

### Quote Callout
**Content Tab**: `field_text`, `field_caption`, `field_media`
**Design Tab**: `field_style_alignment`, `field_style_color`, `field_style_variation`
**Advanced Tab**: `field_instructions`, `info`

## Advanced Tab Conditional Display

### When to Show Advanced Tab
- Block has `field_heading_level` (semantic markup control)
- Block has `field_instructions` (contextual help)
- Block has administrative fields beyond basic `info`
- Block has specialized configuration options

### When to Hide Advanced Tab
- Block only has basic `info` and `revision_log` fields
- No semantic or technical configuration needed
- Simple blocks with only content and design options

## Required Fields Configuration

### Content Tab
Set `required_fields: true` when tab contains required fields like:
- `field_heading` (required)
- `field_media` (required)
- Essential content fields

### Design Tab
Set `required_fields: true` when tab contains required design fields like:
- `field_style_color` (required)
- `field_style_variation` (required)
- Core styling requirements

### Advanced Tab
Generally set `required_fields: false` unless block has required advanced settings

## Tab Order and Weights

1. **Content Tab**: `weight: 1`, `open: true` (default open)
2. **Design Tab**: `weight: 2`, `formatter: closed`
3. **Advanced Tab**: `weight: 3`, `formatter: closed`

## Accessibility Standards

### Field Group Module Provides
- Keyboard navigation between tabs (arrow keys)
- ARIA attributes for screen readers
- Proper focus management
- Tab roles and states

### Additional Requirements
- Ensure all form fields have proper labels
- Include field descriptions for complex options
- Maintain logical tab order within each group
- Test with screen readers and keyboard navigation

## Validation Context Preservation

For blocks requiring custom validation (like Grand Hero), ensure validation handlers are added:

```php
// In hook_form_alter()
if ($block_type === 'your_block_type') {
  array_unshift($form['#validate'], 'your_module_prepare_validation');
  $form['#validate'][] = 'your_module_preserve_context';
}
```

This maintains modal context and field group tabs during validation errors.

## Migration Checklist

When updating a block to use three-tab structure:

- [ ] Categorize all existing fields into Content/Design/Advanced
- [ ] Add field group configuration with proper weights
- [ ] Set appropriate `required_fields` flags
- [ ] Test accessibility with keyboard navigation
- [ ] Verify validation error handling maintains tab context
- [ ] Update any custom form alterations for the block
- [ ] Test with various user roles and permissions