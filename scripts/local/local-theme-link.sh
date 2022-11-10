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

GREEN='\033[1;32m'
ENDCOLOR='\033[0m'

echo -e "${GREEN}Move into tokens repo and create a global link${ENDCOLOR}"
cd web/themes/contrib/atomic
[ ! -d "_yale-packages/tokens" ] && git clone git@github.com:yalesites-org/tokens.git _yale-packages/tokens
cd _yale-packages/tokens || exit
npm link
cd ../..
echo -e "${GREEN}Move into component library, use the tokens link, and create a global link${ENDCOLOR}"
[ ! -d "_yale-packages/component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git _yale-packages/component-library-twig
cd _yale-packages/component-library-twig || exit
npm link
echo -e "${GREEN}Move into Atomic and use the component-library global link${ENDCOLOR}"
cd ../..
npm link @yalesites-org/component-library-twig
echo -e "${GREEN}Move into the component-library and use the tokens global link${ENDCOLOR}"
cd _yale-packages/component-library-twig || exit
# Run npm ci. This is required to patch our version of Twig.js.
npm ci
npm link @yalesites-org/tokens
echo -e "${GREEN}Run the develop script in the component library${ENDCOLOR}"
