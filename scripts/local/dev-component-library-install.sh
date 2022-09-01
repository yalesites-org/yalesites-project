#!/bin/bash

read -p "Which branch? " BRANCH

echo "Move into atmoic"
cd web/themes/contrib/atomic
echo "npm ci"
npm ci
echo "Delete installed component library"
rm -rf node_modules/@yalesites-org/component-library-twig/
echo "Clone component library"
[ ! -d "component-library-twig" ] && git clone git@github.com:yalesites-org/component-library-twig.git
echo "Move into component library"
cd component-library-twig
echo "Checkout the specified branch"
git checkout $BRANCH
echo "npm ci and npm run build"
npm ci
npm run build
echo "Move into theme and create empty component-library-twig directory"
cd ..
mkdir node_modules/@yalesites-org/component-library-twig
echo "Copy built dist folder"
cp -rp component-library-twig/dist node_modules/@yalesites-org/component-library-twig/.
echo "Copy built components folder"
cp -r component-library-twig/components node_modules/@yalesites-org/component-library-twig/.
