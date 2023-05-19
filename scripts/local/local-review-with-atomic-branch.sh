#!/bin/bash
# This script will checkout the specified branch of atomic for local review.

source ./util/say.sh

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
