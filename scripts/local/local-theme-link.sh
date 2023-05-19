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

echo -e "Use local-cl-dev.sh instead.  This script is deprecated."
