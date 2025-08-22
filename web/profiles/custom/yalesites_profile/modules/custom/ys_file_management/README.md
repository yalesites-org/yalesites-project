# YaleSites File Management

## Description

The YaleSites File Management module extends the `media_file_delete` contrib module to provide enhanced safety controls for deleting media entities and their associated files. This module implements a sophisticated three-tier permission system and comprehensive usage validation to prevent accidental deletion of files that are referenced elsewhere in the site.

## Background

While Drupal core and the `media_file_delete` module provide basic file deletion capabilities, they lack the granular permission controls and usage validation needed for a multi-user content management environment. This module addresses these limitations by:

- Adding permission-based access controls for file deletion
- Implementing comprehensive usage detection across entity reference fields
- Providing both individual and bulk deletion operations
- Ensuring consistent messaging and user experience
- Maintaining detailed audit logs for all file operations

## Features

### Individual Media Deletion
- **Custom Delete Form**: Replaces the default media delete form with enhanced safety checks
- **Usage Validation**: Scans entity reference fields across content types to detect file usage
- **Permission-Based Controls**: Restricts deletion based on user permission level
- **Ownership Validation**: Respects file ownership unless user has elevated permissions
- **Safe File Handling**: Files are marked as temporary for cron cleanup by default

### Bulk Media Deletion
- **Batch Operations**: Handles multiple media deletions with comprehensive safety analysis
- **Batch Validation**: Analyzes entire batch before allowing any deletions
- **Consistent Safety**: Applies same permission and usage checks as individual operations
- **Progress Tracking**: Provides clear feedback during bulk operations

### Permission-Based Controls
Three distinct permission levels provide granular control:

1. **Standard Users**: Can delete media with attached files only if the file is not used elsewhere and they own the media
2. **Site Administrators** (`delete media files regardless of owner`): Can delete unused files regardless of ownership but are still blocked by usage validation
3. **Platform Administrators** (`force delete media files`): Can force delete files even if used elsewhere, with explicit warnings about potential content breakage

### Usage Detection
- **Entity Reference Scanning**: Checks nodes, paragraphs, and block content for media usage
- **Cross-Bundle Analysis**: Examines all bundles within each entity type
- **Real-Time Validation**: Performs usage checks at deletion time for current data
- **Detailed Reporting**: Provides specific information about where media is used

### Safe File Handling
- **Temporary Marking**: Standard deletion marks files as temporary for cron cleanup (6-hour window)
- **Force Deletion**: Platform administrators can immediately delete files with full cleanup
- **Usage Record Management**: Properly removes all file usage tracking records
- **Physical File Removal**: Handles both managed file entity and physical file deletion

### Audit Logging
- **Comprehensive Logging**: Records all file deletion actions with user context
- **Permission Level Tracking**: Logs which permission level was used for deletion
- **Usage Information**: Records whether files were used elsewhere at deletion time
- **Safety Warnings**: Logs when force deletions occur despite usage warnings

## Architecture

### Form Override System
The module uses Drupal's `hook_entity_type_alter()` to replace default media delete forms:

```php
// Individual media deletion
$entity_types['media']->setFormClass('delete', YsMediaDeleteForm::class);
// Bulk media deletion  
$entity_types['media']->setFormClass('delete-multiple-confirm', YsMediaDeleteMultipleForm::class);
```

### Service Architecture
The module implements a clean service-oriented architecture:

- **MediaUsageDetector**: Scans entity reference fields to detect media usage
- **MediaFileHandler**: Manages file deletion operations and safety controls
- **MediaDeleteMessageBuilder**: Provides consistent messaging across operations

### Bundle Filtering
Operations are limited to file-based media bundles only:
- `image` - Image files
- `document` - Document files  
- `background_video` - Video files with physical assets

Embed and remote video media types are excluded as they don't have associated files to manage.

### Entity Usage Integration
The module is optimized to work with the Entity Usage module for relationship tracking:
- Tracks media entities as targets only (not sources)
- Focused plugin configuration (entity_reference, media_embed, etc.)
- Reduced database overhead compared to full entity usage tracking

## Installation

### Requirements
- Drupal 9 or 10
- `media_file_delete` contrib module
- `entity_usage` module (recommended for comprehensive usage tracking)

### Installation Steps
1. Install the required contrib modules
2. Install and enable `ys_file_management`
3. Configure Entity Usage settings to optimize for file deletion tracking
4. Assign appropriate permissions to user roles

### Module Weight
The module automatically sets its weight to 10 during installation to ensure its hooks execute after the `media_file_delete` module.

## Configuration

### Entity Usage Optimization
For optimal performance, configure Entity Usage to track only what's needed for file deletion safety:

```yaml
# Track media as targets only
track_enabled_target_entity_types:
  media: media

# Disable source tracking to reduce overhead  
track_enabled_source_entity_types: {}

# Focus on relevant plugins
enabled_plugins:
  - entity_reference
  - media_embed
  - entity_embed
  - ckeditor_image
```

This configuration reduces database overhead by 80%+ while maintaining the usage detection needed for safe file deletion.

## Permissions

The module defines two custom permissions that work together to create a three-tier system:

### delete media files regardless of owner
- **Title**: Delete media files regardless of owner
- **Description**: Allows users to delete files attached to media entities even if they do not own the file
- **Restriction**: Access restricted (admin-only by default)

### force delete media files  
- **Title**: Force delete media files
- **Description**: Allows users to force delete files attached to media entities even if they are used elsewhere. This bypasses all safety checks.
- **Restriction**: Access restricted (admin-only by default)

### Permission Combinations
- **Neither permission**: Standard user - can only delete unused files they own
- **Owner bypass only**: Site administrator - can delete unused files regardless of ownership
- **Both permissions**: Platform administrator - can force delete files even when in use

## Usage

### Individual Media Deletion
1. Navigate to a media entity
2. Click "Delete" 
3. The system will:
   - Check your permissions
   - Validate file ownership (if applicable)
   - Scan for usage across the site
   - Present appropriate options based on findings

### Bulk Media Deletion
1. Go to the media library (`/admin/content/media`)
2. Select multiple media items
3. Choose "Delete media" from the action dropdown
4. The system will:
   - Analyze the entire batch for permissions and usage
   - Block the operation if any file requires higher permissions
   - Present a summary of what will be deleted

### Force Deletion (Platform Administrators)
When files are used elsewhere, platform administrators will see:
- Clear warnings about potential content breakage
- A checkbox to confirm force deletion
- The delete button is hidden until force deletion is confirmed
- Explicit warnings in confirmation messages

## Technical Details

### Database Schema
The module doesn't introduce new database tables but leverages:
- Drupal's file usage tracking system
- Entity reference field data for usage detection
- Standard logging tables for audit trails

### Performance Considerations
- Usage detection queries are optimized with appropriate limits
- Bulk operations include batch processing to handle large datasets
- Entity Usage integration is configured for minimal overhead
- Database queries use proper indexing on entity reference fields

### Security
- All operations respect Drupal's access control system
- File deletion includes proper usage record cleanup
- Comprehensive logging provides audit trails
- Permission system prevents unauthorized file deletion

### Extensibility
The service architecture allows for easy extension:
- Additional entity types can be added to usage detection
- New permission levels can be integrated
- Custom messaging can be implemented via the message builder service
- Additional safety checks can be added through service decoration

## Code References

- [YsMediaDeleteForm.php](./src/Form/YsMediaDeleteForm.php) - Individual media deletion form
- [YsMediaDeleteMultipleForm.php](./src/Form/YsMediaDeleteMultipleForm.php) - Bulk media deletion form  
- [MediaUsageDetector.php](./src/Service/MediaUsageDetector.php) - Usage detection service
- [MediaFileHandler.php](./src/Service/MediaFileHandler.php) - File deletion operations
- [MediaDeleteMessageBuilder.php](./src/Service/MediaDeleteMessageBuilder.php) - Consistent messaging
- [ys_file_management.module](./ys_file_management.module) - Hook implementations and form overrides