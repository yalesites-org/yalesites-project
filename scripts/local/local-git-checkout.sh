#!/bin/bash

# default values
cl_branch="main"
token_branch="main"
atomic_branch="main"
debug=false
verbose=false

# current_branch_for_path
#
# This function will return the current branch for a given path.
#
# params:
#  git_path: the path to the repo
#
function current_branch_for_path() {
  local git_path=${1-$(pwd)}

  git -C "$git_path" rev-parse --abbrev-ref HEAD
}

# clone_or_switch_branch
#
# This function will clone a repo if it doesn't exist, or switch to a branch if
# it does exist.
# It ensures that there are no uncommitted changes before switching to save the
# developer from losing work.
#
# params:
#  name: the name of the repo
#  git_path: the path to the repo
#  target_branch: the branch to switch to
#
function clone_or_switch_branch() {
  local name=$1
  local git_path=$2
  local target_branch=${3:-main}
  local current_branch

  [ -z "$name" ] && _error "You must provide a name" && exit 1
  [ -z "$git_path" ] && _error "You must provide a git_path" && exit 1

  # Clone if directory doesn't exist
  [ ! -d "$git_path" ] && 
    _say "Cloning the $target_branch branch of the $name repo" && 
    git clone git@github.com:yalesites-org/"$name".git "$git_path" -b "$target_branch"

  # Get current branch of repo
  current_branch=$(current_branch_for_path "$git_path")

  # If current branch is not the target branch
  if [ "$current_branch" != "$target_branch" ]; then
    # If there are no uncommitted changes, switch to the target branch
    if git -C "$git_path" diff --quiet --exit-code; then
      _say "Current branch of $name is $current_branch, switching to $target_branch"
      git -C "$git_path" fetch --all
      git -C "$git_path" checkout "$target_branch"
    else
      _error "You have uncommitted changes to the $name repo.  Please commit or stash them before running this script."
      exit 1
    fi
  else
    _say "Already on $target_branch branch of $name repo"
  fi
}

function repo_has_changes() {
  local git_path=$1

  # If directory doesn't exist, return 0 (bypass)
  [ ! -d "$git_path" ] && return 0

  # Check for uncommitted changes
  if ! git -C "$git_path" diff --quiet --exit-code; then
    return 1
  fi

  # Check for untracked files
  if [ "$(git -C "$git_path" status --porcelain | grep -c '^??')" -gt 0 ]; then
    return 1
  fi
}

# getopts
while getopts ":dvc:t:a:b:" opt; do
  case ${opt} in
    d )
      debug=true
      ;;
    c )
      cl_branch=$OPTARG
      ;;
    t )
      token_branch=$OPTARG
      ;;
    a )
      atomic_branch=$OPTARG
      ;;
    v )
      verbose=true
      ;;
    b )
      atomic_branch=$OPTARG
      cl_branch=$OPTARG
      token_branch=$OPTARG
      ;;
    \? )
      echo "Usage: $0 [-d] [-b <branch-for-all-repos>] [-c <component-library-branch>] [-t <tokens-branch>] [-a <atomic-branch>]"
      exit 1
      ;;
    :)
      echo "Usage: $0 [-d] [-b <branch-for-all-repos>] [-c <component-library-branch>] [-t <tokens-branch>] [-a <atomic-branch>]"
      echo "Option -$OPTARG requires an argument." >&2
      exit 1
      ;;
  esac
done

[ -e "./scripts/local/util/say.sh" ] || (echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: " && exit 1)
source ./scripts/local/util/say.sh

[ "$debug" = true ] && _say "Debug mode enabled" && set -x
[ "$verbose" = true ] && _say "Verbose mode enabled"

# Shortcircuit running if there are changes already present
repo_has_changes 'web/themes/contrib/atomic'
if [ $? -eq 1 ]; then
  _error "You have uncommitted changes to the atomic repo.  Please commit or stash them before running this script."
  exit 1
fi

repo_has_changes 'web/themes/contrib/atomic/_yale-packages/tokens'
if [ $? -eq 1 ]; then
  _error "You have uncommitted changes to the tokens repo.  Please commit or stash them before running this script."
  exit 1
fi

repo_has_changes 'web/themes/contrib/atomic/_yale-packages/component-library-twig'
if [ $? -eq 1 ]; then
  _error "You have uncommitted changes to the component-library-twig repo.  Please commit or stash them before running this script."
  exit 1
fi

_say "Let the magic begin!"
_say "********************"

atomic_changed=false
# If current branch did change
if [ "$(current_branch_for_path 'web/themes/contrib/atomic')" != "$atomic_branch" ]; then
  atomic_changed=true
fi

_say "Attempting to clone $atomic_branch branch of atomic repo"
clone_or_switch_branch "atomic" "web/themes/contrib/atomic" "$atomic_branch"

[ "$verbose" = true ] && _say "Moving to atomic repo"
cd web/themes/contrib/atomic || (_error "Could not find atomic theme. Are you in the right directory?" && exit 1)

_say "Attempting to clone $token_branch branch of tokens repo"
clone_or_switch_branch "tokens" "_yale-packages/tokens" "$token_branch"

_say "Moving into tokens repo and creating a global npm link"
cd _yale-packages/tokens || (_error "Could not find tokens repo. Are you in the right directory?" && exit 1)
npm link

[ "$verbose" = true ] && _say "Moving back to atomic"
cd ../..

_say "Attempting to clone $cl_branch branch of component-library-twig repo"
clone_or_switch_branch "component-library-twig" "_yale-packages/component-library-twig" "$cl_branch"

[ "$verbose" = true ] && _say "Moving into component library"
cd _yale-packages/component-library-twig || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit 1)

_say "Using the tokens global npm link inside the component library"
npm link

[ "$verbose" = true ] && _say "Moving back to atomic"
cd ../..

_say "Using the component-library global npm link inside the atomic theme"
npm link @yalesites-org/component-library-twig

[ "$verbose" = true ] && _say "Moving into the component library"
cd _yale-packages/component-library-twig || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit 1)

_say "Running clean install so we can patch Twig.js"
npm ci -y

_say "Using the tokens global npm link inside the component library"
npm link @yalesites-org/tokens

[ "$verbose" = true ] && _say "Moving back to atomic"
cd ../..

_say "Attempting to npm link tokens inside atomic"
# You can't do this because only one npm link can exist at a time on a node_module folder :(
# npm link @yalesites-org/tokens
# So we do it ourselves
rm -rf node_modules/@yalesitesorg/tokens 
cd node_modules/@yalesites-org || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit 1)
ln -s ../../_yale-packages/tokens tokens
cd ../..

[ "$verbose" = true ] && _say "Moving to tokens repo"
cd _yale-packages/tokens || (_error "Could not find tokens repo. Are you in the right directory?" && exit 1)

_say "Building tokens"
cd ../tokens || (_error "Could not find tokens repo. Are you in the right directory?" && exit 1)
npm i -D
npm run build

_say "Rebuilding component library to have dist folder"
cd ../component-library-twig || (_error "Could not find component-library-twig repo. Are you in the right directory?" && exit 1)
npm run build

_say "Symlinking to root directory"
cd ../../../../../..
[ ! -L "atomic" ] && ln -s web/themes/contrib/atomic atomic
[ ! -L "component-library-twig" ] && ln -s atomic/_yale-packages/component-library-twig component-library-twig
[ ! -L "tokens" ] && ln -s atomic/_yale-packages/tokens tokens

[ "$atomic_changed" = true ] && _say "Atomic theme changed, so we need to clear Drupal cache; this could take a while" && lando drush cr

