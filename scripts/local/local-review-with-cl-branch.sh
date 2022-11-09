#!/bin/bash
# This script will clone the component libary into the atomic theme, and then
# build it and copy the dist and components directories into the node_modules
# folder so that any work-in-progress in the component library can be used
# during development in Drupal.

GREEN='\033[1;32m'
ENDCOLOR='\033[0m'

read -p "Which branch of the component-library-twig repo do you need? " BRANCH

echo -e "${GREEN}Move into atomic and checkout develop"
cd web/themes/contrib/atomic || exit
echo -e "${GREEN}Delete installed component library${ENDCOLOR}"
rm -rf node_modules/@yalesites-org/component-library-twig
echo -e "${GREEN}Clone component library${ENDCOLOR}"
[ ! -d "_node_modules/component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git _node_modules/component-library-twig
echo -e "${GREEN}Move into component library${ENDCOLOR}"
cd _node_modules/component-library-twig || exit
echo -e "${GREEN}Checkout the specified branch${ENDCOLOR}"
git checkout "$BRANCH"
git pull
echo -e "${GREEN}npm ci and npm run build${ENDCOLOR}"
npm ci
npm run build
echo -e "${GREEN}Move into theme and create empty component-library-twig directory${ENDCOLOR}"
cd ../..
mkdir node_modules/@yalesites-org/component-library-twig
echo -e "${GREEN}Copy built dist folder${ENDCOLOR}"
cp -r component-library-twig/dist node_modules/@yalesites-org/component-library-twig/.
echo -e "${GREEN}Copy built components folder${ENDCOLOR}"
cp -r component-library-twig/components node_modules/@yalesites-org/component-library-twig/.
