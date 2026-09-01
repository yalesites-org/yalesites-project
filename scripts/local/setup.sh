#!/bin/bash

# Define variable to check from other scripts if this script is being invoked.
export YALESITES_IS_LOCAL=1

source ./scripts/local/local-dev-tool.sh

# Ignore the composer.lock file on local dev only.
if ! grep -qxF 'composer.lock' .git/info/exclude; then
  echo "Excluding composer.lock to keep it out of the repo."
  echo 'composer.lock' >> .git/info/exclude
fi

# Create a local lando settings file if it does not exist.
if [[ "$YALESITES_LOCAL_DOCKER_TOOL" == "lando" && ! -f ".lando.local.yml" ]]; then
  echo "Creating a local lando file for connecting to Pantheon"
  cp .lando.local.example.yml .lando.local.yml
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

# Start local development containers.
ys_local_start

# Generate local secrets file.
terminus plugin:install pantheon-systems/terminus-secrets-manager-plugin
terminus secret:site:local-generate yalesites-platform --filepath=./secrets.json

# Install packages and install Drupal using yalesites_profile.
npm install
npm run build-with-install

# Configure Composer to use source packaged versions.
ys_local_composer config --global 'preferred-install.yalesites-org/*' source

# Manually remove the originally downloaded dist packed version.
ys_local_exec rm -rf web/themes/contrib/atomic

# Use Composer to download the new version of the Yale projects.
ys_local_composer update atomic

# Setup npm linked packages for theme dependencies
# See https://yaleits.atlassian.net/browse/YALB-971git
# npm run local:theme-link

# Create a login link.
ys_local_drush uli
