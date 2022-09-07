#!/bin/bash

GREEN='\033[1;32m'
ENDCOLOR='\033[0m'

read -p "Which branch? " BRANCH

echo -e "${GREEN}Move into atomic and checkout develop"
cd web/themes/contrib/atomic
git checkout develop
echo -e "${GREEN}npm ci${ENDCOLOR}"
npm ci
echo -e "${GREEN}Delete installed component library${ENDCOLOR}"
rm -rf node_modules/@yalesites-org/component-library-twig/
echo -e "${GREEN}Clone component library${ENDCOLOR}"
[ ! -d "component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git
echo -e "${GREEN}Move into component library${ENDCOLOR}"
cd component-library-twig
echo -e "${GREEN}Checkout the specified branch${ENDCOLOR}"
git checkout $BRANCH
git pull
echo -e "${GREEN}npm ci and npm run build${ENDCOLOR}"
npm ci
npm run build
echo -e "${GREEN}Move into theme and create empty component-library-twig directory${ENDCOLOR}"
cd ..
mkdir node_modules/@yalesites-org/component-library-twig
echo -e "${GREEN}Copy built dist folder${ENDCOLOR}"
cp -rp component-library-twig/dist node_modules/@yalesites-org/component-library-twig/.
echo -e "${GREEN}Copy built components folder${ENDCOLOR}"
cp -r component-library-twig/components node_modules/@yalesites-org/component-library-twig/.
