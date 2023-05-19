#!/bin/bash
# This script will use npm link under-the-hood (inside the Docker container) to
# enable frontend developers to work inside Storybook as they normally would,
# but also make those changes imediately available to the local Drupal instance.
# This is particularly useful when a wiring ticket surfaces required component
# library updates.

# Note: Changes to the component library are still tracked ONLY in the component
# library's repository, so in order for the changes to be seen on a remotely
# built environment (multidev, or any live site) the changes will have to go
# through the regular component library release process, and be included in an
# official release of Atomic.

[ -e "./scripts/local/util/say.sh" ] || (echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: " && exit 1)
source ./scripts/local/util/say.sh

if ! ./scripts/local/local-git-checkout.sh -c develop -a develop; then
  exit 1
fi

cd atomic || exit

_say "Running the develop script in the component library"
npm run develop
