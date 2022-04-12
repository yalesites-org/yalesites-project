#!/bin/bash
# Check the yalesites_profile repository for local changes. Pull any new
# dependencies into the parent project repository.

# Exit early if there are changes to composer.json. Better be safe than sorry.
if [[ $(git diff --exit-code composer.json) ]]; then
  echo "Can not run command with uncommitted changes in composer.json"
  exit 1
fi

# Add the local version of the yalesites_profile repository to composer so that
# updates are pulled from local files rather than what's on GitHub.
lando composer config repositories.profile_local path "./web/profiles/contrib/yalesites_profile"

# Remove the lock file so that composer will check for updates. This file is not
# tracked in this repository, so rebuilding it has little consequence.
lando ssh -c "rm composer.lock"

# Make a backup of the profile in case there are any uncommitted changes.
lando ssh -c "cp -r ./web/profiles/contrib/yalesites_profile /tmp"

# Check for updates and install packages.
lando composer install

# Composer install will deletes profile as does not match what is in the lock.
# Manually restore our local version from the saved copy in the tmp folder.
lando ssh -c "cp -r /tmp/yalesites_profile ./web/profiles/contrib"
lando ssh -c "rm -rf /tmp/yalesites_profile"

# Remove the local version of the profile repository.
lando composer config --unset repositories.profile_local
