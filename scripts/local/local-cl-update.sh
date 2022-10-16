#!/bin/bash
# This script will rebuild the locally cloned component library, and move the
# dist and compoennts directories into node_modules so that you can develop on
# the component library at the same time as atomic or the Drupal project.

# Note: This script is intended to be used during active development. So there's
# no automated branch switching, or `npm isntall`ing or anything. It simply
# deletes the existing component library stuff from node_modules, builds what's
# in the local one, and copies that in so that Drupal can use it.

GREEN='\033[1;32m'
ENDCOLOR='\033[0m'

echo -e "${GREEN}Move into atomic"
cd web/themes/contrib/atomic
echo -e "${GREEN}Delete installed component library${ENDCOLOR}"
rm -rf node_modules/@yalesites-org/component-library-twig/
echo -e "${GREEN}Move into component library${ENDCOLOR}"
cd component-library-twig
echo -e "${GREEN}npm run build${ENDCOLOR}"
npm run build
echo -e "${GREEN}Move into theme and create empty component-library-twig directory${ENDCOLOR}"
cd ..
mkdir node_modules/@yalesites-org/component-library-twig
echo -e "${GREEN}Copy built dist folder${ENDCOLOR}"
cp -r component-library-twig/dist node_modules/@yalesites-org/component-library-twig/.
echo -e "${GREEN}Copy built components folder${ENDCOLOR}"
cp -r component-library-twig/components node_modules/@yalesites-org/component-library-twig/.
