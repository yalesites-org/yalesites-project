#!/bin/bash

STARTERKIT_VERSION="latest"
STARTERKIT_FILE="starterkit.zip"
DOWNLOAD_URL="https://github.com/yalesites-org/yalesites-starterkit/releases/$STARTERKIT_VERSION/download/$STARTERKIT_FILE"

# Download starterkit export.
if curl -s -O -L "$DOWNLOAD_URL"; then
  echo "Starterkit file downloaded successfully."
else
  echo "Failed to download starterkit file."
  exit 1
fi

# Check if running under lando, otherwise assume CI.
# Put starterkit file in place and import.
# Get home page node path and set as front page in config.
nid_command="print \Drupal::service('path_alias.manager')->getPathByAlias('/homepage');"

if [ "$YALESITES_IS_LOCAL" == "1" ]; then
  lando drush content:import ../"$STARTERKIT_FILE" && rm "$STARTERKIT_FILE"
  homepage_nid=$(lando drush ev "$nid_command")
  lando drush cset system.site page.front "$homepage_nid" -y
else
  [ -z "$env" ] && env="dev"
  COMMAND=$(terminus connection:info "$SITE_MACHINE_NAME"."$env" --field=sftp_command)
  eval "$COMMAND:/files/ <<< 'put $STARTERKIT_FILE'"
  terminus drush "$SITE_MACHINE_NAME"."$env" -- content:import ../../files/"$STARTERKIT_FILE"
  eval "$COMMAND:/files/ <<< 'rm $STARTERKIT_FILE'"
  homepage_nid=$(echo "$nid_command" | terminus drush "$SITE_MACHINE_NAME"."$env" -- php-script - 2>/dev/null)
  terminus drush "$SITE_MACHINE_NAME"."$env" -- cset system.site page.front "$homepage_nid" -y
fi
