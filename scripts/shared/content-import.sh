#!/bin/bash

STARTERKIT_VERSION=master
STARTERKIT_FILE=starterkit.zip

# Get starterkit export.
curl -s -L -o "$STARTERKIT_FILE" https://github.com/yalesites-org/yalesites-starterkit/zipball/"$STARTERKIT_VERSION"

# Check if running under lando, otherwise assume CI.
if [ "$LANDO" == "ON" ]; then
  lando drush content:import ../"$STARTERKIT_FILE"
  lando drush cset system.site page.front '/homepage'
  rm "$STARTERKIT_FILE"
else
  # Get SFTP command.
  COMMAND=$(terminus connection:info "$SITE_MACHINE_NAME".dev --field=sftp_command)

  # SFTP file to Pantheon.
  eval "$COMMAND:/files/ <<< 'put $STARTERKIT_FILE'"

  # Import starterkit content.
  terminus drush $SITE_MACHINE_NAME.dev -- content:import ../../files/"$STARTERKIT_FILE"

  # Clean up starterkit files on Pantheon.
  eval "$COMMAND:/files/ <<< 'rm $STARTERKIT_FILE'"

  # Set homepage node.
  terminus drush "$SITE_MACHINE_NAME".dev -- cset system.site page.front '/homepage' -y
fi
