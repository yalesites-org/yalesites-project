#!/bin/bash

STARTERKIT_VERSION=master
STARTERKIT_FILE=starterkit.zip

# Download starterkit export.
curl -s -L -o "$STARTERKIT_FILE" https://github.com/yalesites-org/yalesites-starterkit/zipball/"$STARTERKIT_VERSION"

# Check if running under lando, otherwise assume CI.
# Put starterkit file in place and import.
# Clean up, and set front page in config.
if [ "$YALESITES_INSTALL" == "1" ]; then
  lando drush content:import ../"$STARTERKIT_FILE"
  lando drush cset system.site page.front '/homepage'
  rm "$STARTERKIT_FILE"
else
  COMMAND=$(terminus connection:info "$SITE_MACHINE_NAME".dev --field=sftp_command)
  eval "$COMMAND:/files/ <<< 'put $STARTERKIT_FILE'"
  terminus drush "$SITE_MACHINE_NAME".dev -- content:import ../../files/"$STARTERKIT_FILE"
  eval "$COMMAND:/files/ <<< 'rm $STARTERKIT_FILE'"
  terminus drush "$SITE_MACHINE_NAME".dev -- cset system.site page.front '/homepage' -y
fi
