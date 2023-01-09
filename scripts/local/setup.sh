#!/bin/bash

# Ignore the composer.lock file on local dev only.
if ! grep -qxF 'composer.lock' .git/info/exclude; then
  echo "Excluding composer.lock to keep it out of the repo."
  echo 'composer.lock' >> .git/info/exclude
fi

# Create a local lando settings file if it does not exist.
if [[ ! -f ".lando.local.yml" ]]; then
  echo "Creating a local lando file for connecting to Pantheon"
  cp .lando.local.example.yml .lando.local.yml
fi

# YALESITES_BUILD_TOKEN is needed for authentication to Github.
if [[ -z "$YALESITES_BUILD_TOKEN" ]]; then
  echo "The YALESITES_BUILD_TOKEN variable must be set before setup can continue."
  exit 1
fi

# Start lando and create containers.
lando start

# Install packages and install Drupal using yalesites_profile.
npm install
npm run build-with-install

# Configure Composer to use source packaged versions.
lando composer config --global 'preferred-install.yalesites-org/*' source

# Manually remove the originally downloaded dist packed version.
lando ssh -c "rm -rf web/themes/contrib/atomic"

# Use Composer to download the new version of the Yale projects.
lando composer update atomic

# Create a login link.
lando drush uli
