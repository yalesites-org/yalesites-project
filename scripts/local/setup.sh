#!/bin/bash

# Ignore the composer.lock file on local dev only.
if grep -qxF 'composer.lock' .git/info/exclude; then
  echo "Excluding composer.lock to keep it out of the repo."
  echo 'composer.lock' >> .git/info/exclude
fi

# Create a local lando settings file if it does not exist.
if [[ ! -f ".lando.local.yml" ]]; then
  echo "Creating a local lando file for connecting to Pantheon"
  cp .lando.local.example.yml .lando.local.yml
fi

# Start lando and create containers.
lando start

# Install packages and pull down latest database and files.
lando composer install
#lando pull --database=dev --files=dev --code=none

# Install site from config.
lando drush si --existing-config --db-url=mysql://pantheon:pantheon@database:3306/pantheon -y

# Ensure everything is up to date.
lando drush cim -y
lando drush cr

# Import demo content.
lando drush yaml-content-import-profile yalesites_profile

# Configure Composer to use source packaged versions.
lando composer config --global 'preferred-install.yalesites-org/*' source

# Manually remove the originally downloaded dist packed version.
lando ssh -c "rm -rf web/profiles/contrib/yalesites_profile"
lando ssh -c "rm -rf web/themes/contrib/atomic"

# Use Composer to download the new version of the Yale projects.
lando composer update yalesites_profile
lando composer update atomic

# Create a login link.
lando drush uli
