#!/bin/bash
# This script will checkout the specified branch of atomic for local review.

GREEN='\033[1;32m'
ENDCOLOR='\033[0m'

read -p "Which branch of the atomic repo do you need? " BRANCH

echo -e "${GREEN}Checking out the $BRANCH branch of Atomic"
cd web/themes/contrib/atomic
git checkout develop
git pull
git checkout $BRANCH
git pull
echo -e "${GREEN}npm ci${ENDCOLOR}"
npm ci
echo -e "${GREEN}Clear Drupal's cache${ENDCOLOR}"
cd ../../../..
lando drush cr
