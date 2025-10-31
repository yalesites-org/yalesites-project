# YaleSites File Management

## Description

The YaleSites File Management module extends the `media_file_delete` contrib module to provide enhanced safety controls for deleting media entities and their associated files. This module implements comprehensive usage validation to prevent accidental deletion of files that are referenced elsewhere in the site. File deletion from the filesystem is restricted to platform administrators only - all other users get standard Drupal behavior (media deleted, file unreferenced).

## Background

While Drupal core and the `media_file_delete` module provide basic file deletion capabilities, they lack the comprehensive usage validation needed for a multi-user content management environment. This module addresses these limitations by:

- Implementing comprehensive usage detection across entity reference fields
- Restricting file deletion to platform administrators only
- Providing both individual and bulk deletion operations
- Ensuring consistent messaging and user experience
- Maintaining detailed audit logs for all file operations
- Allowing platform administrators to delete files even when in use (with warnings)

## Features

### Individual Media Deletion
- **Custom Delete Form**: Replaces the default media delete form with enhanced safety checks
- **Usage Validation**: Scans entity reference fields across content types to detect file usage
- **Platform Admin File Deletion**: Only platform administrators see a checkbox to delete files from the filesystem (default checked)
- **Safe File Handling**: Files are marked as temporary for cron cleanup (typically within 6 hours)
- **Standard User Behavior**: Non-admin users get standard Drupal deletion (media deleted, file unreferenced but remains)

### Bulk Media Deletion
- **Batch Operations**: Handles multiple media deletions with comprehensive safety analysis
- **Batch Validation**: Analyzes entire batch before allowing any deletions
- **Consistent Safety**: Applies same permission and usage checks as individual operations
- **Progress Tracking**: Provides clear feedback during bulk operations

### Permission-Based Controls
Single custom permission controls file deletion from filesystem:

- **Standard Users & Site Admins** (no special permission): Can delete media, files remain in filesystem (standard Drupal behavior)
- **Platform Administrators** (`force delete media files` permission): Can delete files from filesystem via checkbox, even when files are in use (with warnings)

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

The module defines one custom permission for advanced file deletion capabilities:

### force delete media files
- **Title**: Delete media files with usage
- **Description**: Allows users to delete files attached to media entities even if they are used elsewhere. This bypasses usage safety checks.
- **Restriction**: Access restricted (admin-only by default)

### Permission Model
- **Standard users & site admins** (no special permissions): Can delete media, files remain in filesystem (unreferenced)
- **Platform administrators** (`force delete media files` permission): Can delete files from filesystem via checkbox (default checked), even when files are in use elsewhere

## Usage

### Individual Media Deletion
1. Navigate to a media entity
2. Click "Delete"
3. The system will:
   - **For standard users/site admins**: Show simple confirmation, media deleted, file remains (unreferenced)
   - **For platform admins**: Show "Delete the associated file" checkbox (default checked), with warnings if file is in use

### Bulk Media Deletion
1. Go to the media library (`/admin/content/media`)
2. Select multiple media items
3. Choose "Delete media" from the action dropdown
4. The system will:
   - **For standard users/site admins**: Delete media entities, files remain (unreferenced)
   - **For platform admins**: Show "Delete the associated files" checkbox (default checked), with warnings if files are in use

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