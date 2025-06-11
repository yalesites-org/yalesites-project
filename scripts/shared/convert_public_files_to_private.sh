#!/bin/bash

# Drupal Files Migration Script: Public to Private
# Migrates database references and moves year-based directories (20*) to private

set -e  # Exit on any error

# Configuration
PUBLIC_DIR="web/sites/default/files"
PRIVATE_DIR="web/sites/default/files/private"

echo "=== Drupal Files Migration: Public to Private ==="
echo "Public directory: $PUBLIC_DIR"
echo "Private directory: $PRIVATE_DIR"
echo

# Step 1: Update database references
echo "Step 1: Updating database references from public:// to private://"
echo "----------------------------------------"

lando drush php-eval "
\$query = \Drupal::entityQuery('file')
  ->condition('uri', 'public://', 'STARTS_WITH')
  ->accessCheck(FALSE);
\$fids = \$query->execute();
\$files = \Drupal::entityTypeManager()->getStorage('file')->loadMultiple(\$fids);
\$count = 0;
foreach (\$files as \$file) {
  \$old_uri = \$file->getFileUri();
  \$new_uri = str_replace('public://', 'private://', \$old_uri);
  \$file->setFileUri(\$new_uri);
  \$file->save();
  \$count++;
  if (\$count <= 10) {
    echo 'Updated: ' . \$old_uri . ' -> ' . \$new_uri . PHP_EOL;
  }
}
if (\$count > 10) {
  echo '... and ' . (\$count - 10) . ' more files.' . PHP_EOL;
}
echo 'Total updated: ' . \$count . ' files.' . PHP_EOL;
"

echo
echo "Step 2: Moving year-based directories (20*) to private"
echo "----------------------------------------"

# Ensure private directory exists
if [ ! -d "$PRIVATE_DIR" ]; then
    echo "Creating private directory: $PRIVATE_DIR"
    mkdir -p "$PRIVATE_DIR"
fi

# Check if public directory exists
if [ ! -d "$PUBLIC_DIR" ]; then
    echo "Error: Public directory $PUBLIC_DIR does not exist!"
    exit 1
fi

# Find directories starting with "20" in the public directory
year_dirs=$(find "$PUBLIC_DIR" -maxdepth 1 -type d -name "20*" 2>/dev/null || true)

if [ -z "$year_dirs" ]; then
    echo "No year-based directories (20*) found in $PUBLIC_DIR"
else
    echo "Found year-based directories to migrate:"
    echo "$year_dirs"
    echo

    for dir in $year_dirs; do
        dir_name=$(basename "$dir")
        target_dir="$PRIVATE_DIR/$dir_name"
        
        echo "Processing directory: $dir_name"
        
        if [ -d "$target_dir" ]; then
            echo "  → Target directory exists, merging contents..."
            
            # Move individual files/subdirectories
            for item in "$dir"/*; do
                if [ -e "$item" ]; then
                    item_name=$(basename "$item")
                    target_item="$target_dir/$item_name"
                    
                    if [ -e "$target_item" ]; then
                        echo "    ⚠ Conflict: $item_name already exists in target, skipping..."
                    else
                        echo "    ✓ Moving: $item_name"
                        mv "$item" "$target_item"
                    fi
                fi
            done
            
            # Remove original directory if empty
            if [ -z "$(ls -A "$dir" 2>/dev/null)" ]; then
                echo "  ✓ Removing empty source directory: $dir_name"
                rmdir "$dir"
            else
                echo "  ⚠ Source directory not empty after merge: $dir_name"
                echo "    Remaining items:"
                ls -la "$dir"
            fi
        else
            echo "  ✓ Moving entire directory: $dir_name"
            mv "$dir" "$target_dir"
        fi
        echo
    done
fi

echo "Step 3: Clearing Drupal caches"
echo "----------------------------------------"
lando drush cr

echo
echo "=== Migration Complete ==="
echo "Next steps:"
echo "1. Verify files are accessible through Drupal"
echo "2. Test file downloads/access permissions"
echo "3. Consider updating .htaccess rules if needed"
echo "4. Monitor error logs for any file access issues"
