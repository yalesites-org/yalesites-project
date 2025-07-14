#!/bin/bash
# This script will clone the component libary into the atomic theme, and then
# build it and copy the dist and components directories into the node_modules
# folder so that any work-in-progress in the component library can be used
# during development in Drupal.

# Source git utilities
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/git-utils.sh"

# Validate git setup
if ! validate_git_setup; then
  echo "Error: Invalid git setup. Please ensure you are in a git repository."
  exit 1
fi

# Get git root for worktree support
git_root=$(get_git_root)
if [ $? -ne 0 ]; then
  echo "Error: Could not determine git root directory"
  exit 1
fi

# Source say.sh from git root
[ -e "$git_root/scripts/local/util/say.sh" ] || (echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: " && exit 1)
source "$git_root/scripts/local/util/say.sh"

read -p "Which branch of the component-library-twig repo do you need? " BRANCH

_say "Move into atomic and checkout develop"
cd "$git_root/web/themes/contrib/atomic" || exit
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
