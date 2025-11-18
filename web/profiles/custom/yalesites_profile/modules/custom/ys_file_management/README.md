# YaleSites File Management

Provides conditional file deletion capabilities for privileged users when deleting media entities.

## Overview

This module extends the [Media File Delete](https://www.drupal.org/project/media_file_delete) contrib module to provide role-based file deletion with immediate physical file removal from the filesystem.

## Features

- **Role-based Access**: File deletion is restricted to users with the "File Manager" role
- **Immediate Deletion**: Physical files are removed from the filesystem immediately, not marked for cron cleanup
- **Usage Warnings**: Displays warnings when files are used in multiple locations
- **Conditional UI**: File deletion checkbox only appears for authorized users
- **Error Handling**: Graceful error handling with user feedback if deletion fails

## Roles and Permissions

### File Manager Role

Users with the "File Manager" role have the following capabilities:

- Delete media entities regardless of who created them
- Delete associated physical files from the filesystem
- View file usage warnings before deletion
- Access the "Also delete the associated file?" checkbox on media deletion forms

**Permissions granted to File Manager:**
- `access files overview`
- `access media overview`
- `delete any document media`
- `delete any image media`
- `delete any background_video media`
- `delete any file`
- `delete media`
- `manage media files` (custom permission from this module)
- `update any media`
- `update media`
- `view all media revisions`

### Platform Admin Role

Platform Admins do **not** automatically receive File Manager permissions. If needed, the File Manager role can be assigned to Platform Admin users on a case-by-case basis.

### Regular Users

Users without the File Manager role:
- Can only delete media entities they have permission to delete
- Cannot delete associated physical files
- Do not see file deletion options on the media delete form

## How It Works

### Form Override

The module implements `hook_entity_type_alter()` to replace the default media delete form with `ConditionalMediaDeleteForm`.

### Conditional Display

`ConditionalMediaDeleteForm` extends the Media File Delete module's `MediaDeleteForm` and:

1. **For File Managers** (`manage media files` permission):
   - Shows the parent form with file deletion checkbox
   - Displays usage warnings if file is referenced elsewhere
   - Provides "Also delete the associated file?" option

2. **For other users**:
   - Shows standard Drupal media deletion form
   - No file deletion options
   - Files are retained when media is deleted

### Immediate File Deletion

When a File Manager deletes media with the checkbox checked:

1. Physical file is immediately deleted using `FileSystemInterface::delete()`
2. File entity is deleted from the database
3. Media entity is deleted
4. User receives confirmation message

This differs from the default Drupal behavior where `$file->delete()` only marks files as temporary for cron cleanup (default 6-hour delay).

## Configuration

### Module Settings

File deletion behavior is controlled by `media_file_delete.settings.yml`:

```yaml
delete_file_default: false  # Checkbox unchecked by default
disable_delete_control: false  # Checkbox can be toggled
```

### Dependencies

- `drupal:media` - Core media module
- `media_file_delete:media_file_delete` - Contrib module for file deletion UI

## Usage

### Assigning the File Manager Role

1. Navigate to People (`/admin/people`)
2. Edit a user account
3. Check "File Manager" role
4. Save

### Deleting Media with Files

1. Navigate to Media overview (`/admin/content/media`)
2. Find the media entity to delete
3. Click "Delete"
4. If checkbox appears, check "Also delete the associated file?" to remove the physical file
   - If file is used by other media, a message will appear instead and the file will be retained
5. Confirm deletion

### File Usage Detection

The Media File Delete module checks for file usage via Drupal core's file_usage table:

- **Single usage** (file used by only this media): Checkbox appears, file can be deleted
- **Multiple usages** (file used by other media): Message shown that file will be retained, checkbox hidden
- **No delete permission**: Message shown that file is owned by another user and will be retained

## Technical Details

### Architecture

The module uses a service-oriented architecture to separate concerns:

**Service Layer:**
- `MediaFileDeleter` - Business logic for file deletion
  - Validates file objects and URIs
  - Handles immediate filesystem deletion
  - Manages error handling and user feedback
  - Ensures security through URI scheme validation

**Form Layer:**
- `ConditionalMediaDeleteForm` - UI and permission handling
  - Extends `MediaDeleteForm` (from media_file_delete)
  - Implements defense-in-depth permission checking
  - Delegates file deletion to service layer
  - Manages form display based on user roles

### Class Structure

**`ConditionalMediaDeleteForm`**
- Extends: `MediaDeleteForm` (from media_file_delete)
- Services: `ys_file_management.media_file_deleter`
- Methods:
  - `create()` - Dependency injection
  - `buildForm()` - Conditionally show file deletion options
  - `submitForm()` - Delegates to service for file deletion

**`MediaFileDeleter`** (Service)
- Service ID: `ys_file_management.media_file_deleter`
- Dependencies: `file_system`, `messenger`, `logger.factory`, `stream_wrapper_manager`
- Methods:
  - `validateFile()` - Validates FileInterface objects
  - `validateFileUri()` - Security check for URI schemes
  - `deleteFile()` - Immediate file deletion with error handling

### File Deletion Flow

```
User clicks Delete
  ↓
ConditionalMediaDeleteForm::buildForm() checks permission
  ↓
If 'manage media files' → Show file deletion checkbox (parent class)
If not → Show standard form (grandparent class)
  ↓
User submits form
  ↓
ConditionalMediaDeleteForm::submitForm() re-checks permission
  ↓
If checkbox checked:
  ↓
  MediaFileDeleter::deleteFile() service method
    ↓
    1. validateFile() - Check FileInterface
    2. validateFileUri() - Check stream wrapper scheme
    3. $fileSystem->delete($uri) - Remove from disk
    4. $file->delete() - Remove DB record
    5. Display success message or errors
  ↓
ContentEntityDeleteForm::submitForm() - Delete media entity
```

### Error Handling

The module handles errors gracefully:

- **Filesystem deletion fails**: Warning shown, file entity still deleted, media deletion continues
- **Exception thrown**: Error logged, user notified, media deletion continues
- **File doesn't exist**: No error, media deletion continues

## Limitations

- File usage tracking relies on Media File Delete module's usage resolver
- Layout Builder inline block usage may not be detected without additional modules
- Deleting a file that's still referenced elsewhere will result in broken image displays (no PHP errors, but 404s)

## Development

### Code Standards

All code follows Drupal coding standards:

```bash
lando composer code-sniff  # Check standards
lando composer code-fix    # Auto-fix violations
```

### Testing Checklist

- [ ] File Manager can delete media with files
- [ ] File is immediately removed from filesystem
- [ ] Users without role cannot see deletion option
- [ ] Error handling works for permission errors
- [ ] Platform Admins don't have permission by default
- [ ] Multiple media types work (document, image, background_video)
- [ ] Files used by multiple media show retention message (no checkbox)

## Troubleshooting

### Files not being deleted

**Check permissions:**
- User has File Manager role assigned
- Checkbox is checked on delete form
- File permissions allow deletion

### Usage warnings appear incorrectly

**Possible causes:**
- File is actually used elsewhere (check Media references)
- File entity has multiple media referencing it
- Check database: `SELECT * FROM file_usage WHERE fid = X`

### Errors in log

**Check:**
- File exists at the URI
- Web server has write permissions to files directory
- File is not locked by another process

## See Also

- [Media File Delete module](https://www.drupal.org/project/media_file_delete)
- [Drupal File API](https://api.drupal.org/api/drupal/core%21modules%21file%21file.module/group/file/10)
- [File System Service](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21File%21FileSystemInterface.php/interface/FileSystemInterface/10)
