#!/bin/bash

# Define variable to check from other scripts if this script is being invoked.
export YALESITES_IS_LOCAL=1

# Ignore the composer.lock file on local dev only.
if ! grep -qxF 'composer.lock' .git/info/exclude; then
  echo "Excluding composer.lock to keep it out of the repo."
  echo 'composer.lock' >> .git/info/exclude
fi

# Create a local DDEV config file if it does not exist.
if [[ ! -f ".ddev/config.local.yaml" ]]; then
  echo "Creating a local DDEV config for connecting to Pantheon"
  cp .ddev/config.local.example.yaml .ddev/config.local.yaml
fi

# Create a local Drupal settings file if it does not exist.
if [[ ! -f "web/sites/default/settings.local.php" ]]; then
  echo "Creating a local Drupal settings file"
  cp web/sites/ys.settings.local.php web/sites/default/settings.local.php

fi

# YALESITES_BUILD_TOKEN is needed for authentication to Github.
if [[ -z "$YALESITES_BUILD_TOKEN" ]]; then
  echo "The YALESITES_BUILD_TOKEN variable must be set before setup can continue."
  exit 1
fi

# Start DDEV and create containers.
ddev start

# Generate local secrets file.
terminus plugin:install pantheon-systems/terminus-secrets-manager-plugin
terminus secret:site:local-generate yalesites-platform --filepath=./secrets.json

# Install packages and install Drupal using yalesites_profile.
npm install
npm run build-with-install

# Configure Composer to use source packaged versions.
ddev composer config --global 'preferred-install.yalesites-org/*' source

# Manually remove the originally downloaded dist packed version.
ddev ssh -c "rm -rf web/themes/contrib/atomic"

# Use Composer to download the new version of the Yale projects.
ddev composer update atomic

# Setup npm linked packages for theme dependencies
# See https://yaleits.atlassian.net/browse/YALB-971git
# npm run local:theme-link

# Create a login link.
ddev drush uli
