#!/bin/bash
# This script will checkout the specified branch of atomic for local review.

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

read -p "Which branch of the atomic repo do you need? " BRANCH

_say "Checking out the $BRANCH branch of Atomic"
cd "$git_root/web/themes/contrib/atomic" || exit
git checkout develop
git pull
git checkout "$BRANCH"
git pull
_say "npm ci"
npm ci
_say "Clear Drupal's cache"
cd "$git_root"
lando drush cr
