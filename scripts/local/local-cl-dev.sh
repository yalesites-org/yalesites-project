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

[ $# -eq 1 ] && [ "$1" == "-h" ] && echo "Usage: $0 [-d]" && exit
[ $# -eq 1 ] && [ "$1" == "-d" ] && _say "Debug mode enabled" && set -x

[ -f "./scripts/local/util/say.sh" ] || (echo -e "You must run this from the yalesites root directory" && exit)
source ./scripts/local/util/say.sh

 _say "Moving to atomic repo"
cd web/themes/contrib/atomic || (_error "Could not find atomic theme. Are you in the right directory?" && exit)

_say "Attempting to clone tokens repo"
[ ! -d "_yale-packages/tokens" ] && git clone git@github.com:yalesites-org/tokens.git _yale-packages/tokens

_say "Moving into tokens repo and creating a global npm link"
cd _yale-packages/tokens || (_error "Could not find tokens repo. Are you in the right directory?" && exit)
npm link

_say "Moving back to atomic"
cd ../..

_say "Attempting to clone component-library-twig repo"
[ ! -d "_yale-packages/component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git _yale-packages/component-library-twig

_say "Moving into component library and creating a global npm link"
cd _yale-packages/component-library-twig || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit)
npm link

_say "Moving back to atomic"
cd ../..

_say "Using the component-library global npm link inside the atomic theme"
npm link @yalesites-org/component-library-twig

_say "Moving into the component library"
cd _yale-packages/component-library-twig || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit)

_say "Running clean install so we can patch Twig.js"
npm ci -y

_say "Using the tokens global npm link inside the component library"
npm link @yalesites-org/tokens

_say "Running the develop script in the component library"
npm run develop
