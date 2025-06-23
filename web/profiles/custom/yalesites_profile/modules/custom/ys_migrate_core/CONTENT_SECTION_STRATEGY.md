# Content Section Strategy for YaleSites Migrations

## Overview

This document explains the prescriptive approach YaleSites uses for placing migrated content within Layout Builder layouts. This strategy ensures consistency, safety, and maintainability across all migration scenarios.

## The Problem

When migrating content to Layout Builder-enabled content types, we need to decide where to place the migrated blocks within the existing layout structure. Content types in YaleSites have complex default layouts with multiple sections:

- **Banner Section**: Hero images, announcements
- **Title and Metadata**: Page titles, dates, moderation controls
- **Content Section**: User-authored content blocks
- **Footer/Navigation**: Site-wide elements

Allowing migrations to modify any section could:
- Overwrite critical system components
- Break site consistency and design
- Create unpredictable content placement
- Complicate maintenance and debugging

## The Solution: Content Section Targeting

YaleSites implements a **prescriptive approach** that always targets the "Content Section" for migrated content.

### Key Principles

1. **Single Target**: All migrated blocks go into the "Content Section"
2. **System Protection**: Never modify Banner, Title/Metadata, or other system sections
3. **Append Mode**: Add to existing content rather than replacing entire layouts
4. **Auto-Creation**: Create "Content Section" if it doesn't exist in the target layout
5. **Predictable Placement**: Content always appears where users expect it

### Implementation

The `ConfigurableLayoutBuilder` plugin implements this strategy through:

```php
// Always target Content Section
$target_section = $this->configuration['target_section'] ?? 'Content Section';
$append_mode = $this->configuration['append_mode'] ?? false;

// Find existing Content Section or create it
if ($append_mode && $target_section) {
  return $this->appendToContentSection($value, $sections_config, $target_section, $row);
}
```

## Configuration

### Recommended Configuration

```yaml
layout_builder__layout:
  plugin: configurable_layout_builder
  source: node_id
  target_section: 'Content Section'  # Always use this
  append_mode: true                  # Add to existing content
  sections:
    - layout: layout_onecol
      regions:
        content:
          - type: text
            source: migration
            migration_id: my_text_blocks
          - type: accordion
            source: migration
            migration_id: my_accordion_blocks
```

### Configuration Options

- **`target_section`**: Always set to `"Content Section"` (default)
- **`append_mode`**: Set to `true` to add blocks to existing sections
- **`sections`**: Array of section configurations defining blocks to migrate

## Benefits

### Safety
- **No System Corruption**: Cannot accidentally overwrite headers, footers, or metadata
- **Layout Preservation**: Existing layout structure remains intact
- **Rollback Safety**: Easy to identify and remove migrated content

### Consistency
- **Predictable Placement**: Content always appears in the standard content area
- **Design Integrity**: Maintains YaleSites design patterns and user expectations
- **Cross-Site Consistency**: Same behavior across all YaleSites installations

### Maintainability
- **Single Code Path**: One implementation to test and maintain
- **Clear Intent**: Migration purpose is obvious from configuration
- **Debugging Simplicity**: Easy to identify migration-created content

### User Experience
- **Expected Location**: Content appears where users manually add blocks
- **Familiar Interface**: Content authors see content in the expected section
- **Natural Workflow**: Migrated content integrates seamlessly with manual authoring

### Future-Proofing
- **New Sections**: Adding new system sections won't break existing migrations
- **Layout Changes**: Updates to default layouts don't affect migration logic
- **Extensibility**: Additional content sections can be added without code changes

## Technical Implementation

### Section Detection

```php
protected function appendToContentSection($node_id, array $sections_config, string $target_section_label, Row $row) {
  // Load existing layout sections
  $existing_sections = $node->get('layout_builder__layout')->getSections();
  
  // Find Content Section by label
  foreach ($existing_sections as $index => $section) {
    $section_settings = $section->getLayoutSettings();
    if (isset($section_settings['label']) && $section_settings['label'] === $target_section_label) {
      $content_section_found = true;
      break;
    }
  }
}
```

### Content Addition

```php
if ($content_section_found) {
  // Append to existing Content Section
  foreach ($new_components as $component) {
    $content_section->appendComponent($component);
  }
} else {
  // Create new Content Section
  $content_section = new Section('layout_onecol', [
    'label' => $target_section_label,
  ], $new_components);
  $existing_sections[] = $content_section;
}
```

## Alternative Approaches Considered

### 1. User-Specified Target Sections
**Considered**: Allow users to specify any target section in migration configuration.

**Rejected Because**:
- Requires users to understand internal layout structure
- Risk of modifying system sections
- Inconsistent behavior across migrations
- Additional validation and error handling required

### 2. Blacklist Approach
**Considered**: Allow any section except blacklisted system sections.

**Rejected Because**:
- Requires maintaining exclusion lists
- New system sections could break migrations
- More complex logic to test and maintain
- Still allows unpredictable content placement

### 3. Complete Layout Replacement
**Considered**: Replace entire layout with migrated content.

**Rejected Because**:
- Loses existing content and system sections
- Breaks site functionality and design
- No rollback capability
- User experience disruption

## Migration Examples

### D7 to D10 Page Migration

```yaml
# D7 page with multiple blocks becomes D10 page with Content Section
source: d7_node_page
process:
  layout_builder__layout:
    plugin: configurable_layout_builder
    source: nid
    target_section: 'Content Section'
    append_mode: true
    sections:
      - layout: layout_onecol
        regions:
          content:
            - type: text
              source: migration
              migration_id: d7_text_blocks
            - type: image
              source: migration  
              migration_id: d7_image_blocks
```

### Existing Content Enhancement

```yaml
# Add FAQ section to existing pages
source: existing_pages_needing_faqs
process:
  layout_builder__layout:
    plugin: configurable_layout_builder
    source: node_id
    target_section: 'Content Section'
    append_mode: true
    sections:
      - layout: layout_onecol
        regions:
          content:
            - type: accordion
              source: external_api
              migration_id: faq_accordions
```

## Testing Strategy

### Unit Tests
- Verify Content Section detection logic
- Test section creation when missing
- Validate component appending behavior
- Check system section protection

### Integration Tests
- Test with various content types (page, post, event)
- Verify behavior with existing vs. empty Content Sections
- Test multiple block migration scenarios
- Validate layout lock preservation

### Manual Testing Checklist
- [ ] Migrated content appears in Content Section
- [ ] System sections remain unchanged
- [ ] Layout Builder UI shows content in expected location
- [ ] Content editing works normally after migration
- [ ] Migration can be rolled back cleanly

## Conclusion

The Content Section strategy provides a safe, consistent, and maintainable approach to content migration in YaleSites. By prescriptively targeting the "Content Section", we ensure:

- **Predictable behavior** across all migration scenarios
- **Protection of system components** and site functionality  
- **Consistent user experience** that matches manual content authoring
- **Long-term maintainability** with a single, well-tested code path

This approach reflects YaleSites' commitment to providing a robust, reliable platform for institutional web presence while maintaining the flexibility needed for content migration and management.