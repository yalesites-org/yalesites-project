#!/bin/bash
# This script will checkout the specified branch of atomic for local review.

[ -e "./scripts/local/util/say.sh" ] || (echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: " && exit 1)
source ./scripts/local/util/say.sh

read -p "Which branch of the atomic repo do you need? " BRANCH

_say "Checking out the $BRANCH branch of Atomic"
cd web/themes/contrib/atomic || exit
git checkout develop
git pull
git checkout "$BRANCH"
git pull
_say "npm ci"
npm ci
_say "Clear Drupal's cache"
cd ../../../..
lando drush cr
