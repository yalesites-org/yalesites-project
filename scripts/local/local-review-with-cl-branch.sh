#!/bin/bash
# This script will clone the component libary into the atomic theme, and then
# build it and copy the dist and components directories into the node_modules
# folder so that any work-in-progress in the component library can be used
# during development in Drupal.

source ./util/say.sh

read -p "Which branch of the component-library-twig repo do you need? " BRANCH

_say "Move into atomic and checkout develop"
cd web/themes/contrib/atomic || exit
_say "Delete installed component library"
rm -rf node_modules/@yalesites-org/component-library-twig
_say "Clone component library"
[ ! -d "_yale-packages/component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git _yale-packages/component-library-twig
_say "Move into component library"
cd _yale-packages/component-library-twig || exit
_say "Checkout the specified branch"
git checkout "$BRANCH"
git pull
_say "npm ci and npm run build"
npm ci
npm run build
_say "Move into theme and create empty component-library-twig directory"
cd ../..
mkdir node_modules/@yalesites-org/component-library-twig
_say "Copy built dist folder"
cp -r _yale-packages/component-library-twig/dist node_modules/@yalesites-org/component-library-twig/.
_say "Copy built components folder"
cp -r _yale-packages/component-library-twig/components node_modules/@yalesites-org/component-library-twig/.
