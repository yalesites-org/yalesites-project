#!/bin/bash

STARTERKIT_VERSION="v1.0.0"
STARTERKIT_FILE="starterkit.zip"

# Download starterkit export.
curl -s -O -L https://github.com/yalesites-org/yalesites-starterkit/releases/download/"$STARTERKIT_VERSION"/"$STARTERKIT_FILE"

if [ $? -eq 0 ]; then
  echo "Starterkit file downloaded successfully."
else
  echo "Failed to download starterkit file."
  exit -1
fi

# Check if running under lando, otherwise assume CI.
# Put starterkit file in place and import.
# Clean up, and set front page in config.
if [ "$YALESITES_IS_LOCAL" == "1" ]; then
  lando drush content:import ../"$STARTERKIT_FILE"
  lando drush cset system.site page.front '/homepage' -y
  rm "$STARTERKIT_FILE"
else
  COMMAND=$(terminus connection:info "$SITE_MACHINE_NAME".dev --field=sftp_command)
  eval "$COMMAND:/files/ <<< 'put $STARTERKIT_FILE'"
  terminus drush "$SITE_MACHINE_NAME".dev -- content:import ../../files/"$STARTERKIT_FILE"
  eval "$COMMAND:/files/ <<< 'rm $STARTERKIT_FILE'"
  terminus drush "$SITE_MACHINE_NAME".dev -- cset system.site page.front '/homepage' -y
fi
