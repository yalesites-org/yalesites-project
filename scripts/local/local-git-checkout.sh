#!/bin/bash

# current_branch_for_path
#
# This function will return the current branch for a given path.
#
# params:
#  git_path: the path to the repo
#
function current_branch_for_path() {
  local git_path="${1-$(pwd)}"

  git -C "$git_path" rev-parse --abbrev-ref HEAD
}

# branch_exists
#
# This function will return 1 if a branch exists, 0 if it does not.
#
# params:
#  branch_name: the name of the branch
#
function branch_exists() {
  [ -z "$1" ] && _error "You must provide a branch name" && exit 1

  local branch_name="$1"
  local git_path="${2-$(pwd)}"
  local remote_branches

  if git -C "$git_path" rev-parse --quiet --verify "$branch_name" > /dev/null; then
    return 0
  fi

  remote_branches=$(git ls-remote --heads origin | awk '{print $2}' | sed 's#refs/heads/##')
  if echo "$remote_branches" | grep -q "^$branch_name$"; then
    return 0
  fi

  return 1 # Branch does not exist
}

# yalesites_git_clone
#
# This function will clone a repo if it doesn't exist.
#
# params:
#  name: the name of the repo
#  git_path: the path to the repo
#  branch: the branch to clone
#
function yalesites_git_clone() {
  local name="$1"
  local git_path="${2-$(pwd)}"
  local branch="${3-main}"

  [ -z "$name" ] && _error "You must provide a name" && exit 1

  # Clone if directory doesn't exist
  [ ! -d "$git_path" ] && 
    _say "Cloning the $branch branch of the $name repo" && 
    git clone git@github.com:yalesites-org/"$name".git "$git_path" -b "$branch"
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
  [ -z "$1" ] && _error "You must provide a name" && exit 1

  local name="$1"
  local git_path="${2-$(pwd)}"
  local target_branch=${3:-main}
  local backup_branch=${4:-main}
  local origin=${5:-origin}
  local current_branch

  [ -z "$name" ] && _error "You must provide a name" && exit 1

  # Clone if directory doesn't exist
  yalesites_git_clone "$name" "$git_path" "$target_branch"

  # Check if it was successful, if not, use the backup branch
  [ ! -d "$git_path" ] && _error "$target_branch not found, defaulting to $backup_branch" && 
    yalesites_git_clone "$name" "$git_path" "$backup_branch"

  # Fail if still not there
  [ ! -d "$git_path" ] && _error "$backup_branch not found; houston, we have a problem..." && exit 1 

  # Get current branch of repo
  current_branch=$(current_branch_for_path "$git_path")

  # If current branch is not the target branch
  if [ "$current_branch" != "$target_branch" ]; then
    git -C "$git_path" fetch --all
    # If there are no uncommitted changes, prepare to switch to the target branch
    if git -C "$git_path" diff --quiet --exit-code; then
      # If the target branch doesn't exist, switch to the backup branch
      (! branch_exists "$target_branch" "$git_path") && _error "Target branch $target_branch does not exist, switching to $backup_branch" && target_branch="$backup_branch"

      _say "Current branch of $name is $current_branch, switching to $target_branch"
      git -C "$git_path" checkout "$target_branch" || git -C "$git_path" checkout --track "$origin/$target_branch"

      # Verify that the checkout was successful
      current_branch=$(current_branch_for_path "$git_path")
      if [ "$current_branch" != "$target_branch" ]; then
        # If not successful, try the backup branch
        _error "Failed to switch to $target_branch branch of $name repo"
        _say "Attempting to switch to $backup_branch branch of $name repo"
        git -C "$git_path" checkout "$backup_branch" || git -C "$git_path" checkout --track "$origin/$backup_branch"

        # check if that was successful
        current_branch=$(current_branch_for_path "$git_path")
        if [ "$current_branch" != "$backup_branch" ]; then
          # If not successful, fail
          _error "Failed to switch to $backup_branch branch of $name repo"
          exit 1
        fi
      fi
    else
      _error "You have uncommitted changes to the $name repo.  Please commit or stash them before running this script."
      exit 1
    fi
  else
    _say "Already on $target_branch branch of $name repo"
  fi
}

# repo_has_changes
#
# This function will return 1 if a repo has changes, 0 if it does not.
#
# params:
#  git_path: the path to the repo
#
function repo_has_changes() {
  local git_path="${1-$(pwd)}"

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

# Main function that does the work
function _local-git-checkout() {
  # Default values
  local cl_branch="main"
  local token_branch="main"
  local atomic_branch="main"
  local yalesites_branch="develop"
  local debug=false
  local verbose=false

  # Default paths
  local atomic_path="web/themes/contrib/atomic"
  local tokens_path="$atomic_path/_yale-packages/tokens"
  local cl_path="$atomic_path/_yale-packages/component-library-twig"
  local yalesites_path="./"

  # If atomic changes branches, we need to know this so we can know to 
  # clear Drupal's cache toward the end of the script.
  local atomic_changed=false

  # getopts - Parse the options to then use
  while getopts ":dvc:t:a:b:m:" opt; do
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
      m )
        yalesites_branch=$OPTARG
        ;;
      v )
        verbose=true
        ;;
      b )
        atomic_branch=$OPTARG
        cl_branch=$OPTARG
        token_branch=$OPTARG
        yalesites_branch=$OPTARG
        ;;
      \? )
        echo "Usage: $0 [-d] [-b <branch-for-all-repos>] [-c <component-library-branch>] [-t <tokens-branch>] [-a <atomic-branch>] [-m <yalesites-branch>]"
        echo ""
        echo "-d: debug mode"
        echo "-v: verbose mode"
        echo "-b <branch>: branch for all repos - use if all repos are on the same branch name"
        echo "-c <branch>: component library branch to use"
        echo "-t <branch>: tokens branch to use"
        echo "-a <branch>: atomic branch to use"
        echo "-m <branch>: yalesites branch to use"
        exit 1
        ;;
      :)
        echo "Usage: $0 [-d] [-b <branch-for-all-repos>] [-c <component-library-branch>] [-t <tokens-branch>] [-a <atomic-branch>] [-m <yalesites-branch>]"
        echo "Option -$OPTARG requires an argument." >&2
        exit 1
        ;;
    esac
  done

  # Check for utilities directory
  local utils_path="./scripts/local/util"
  [ -e "$utils_path" ] || (echo -e "[$0] Utilities not found.  You must run this from the yalesites root directory: " && exit 1)

  # source say.sh so we can use the _say and _error functions
  [ -e "$utils_path/say.sh" ] || (echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: " && exit 1)
  source ./scripts/local/util/say.sh

  # enable debugging or verbose mode if requested
  [ "$debug" = true ] && _say "Debug mode enabled" && set -x
  [ "$verbose" = true ] && _say "Verbose mode enabled"

  # Shortcircuit running if there are changes already present
  repo_has_changes "$yalesites_path"
  if [ $? -eq 1 ]; then
    _error "You have uncommitted changes to the yalesites repo.  Please commit or stash them before running this script."
    exit 1
  fi

  repo_has_changes "$atomic_path"
  if [ $? -eq 1 ]; then
    _error "You have uncommitted changes to the atomic repo.  Please commit or stash them before running this script."
    exit 1
  fi

  repo_has_changes "$tokens_path"
  if [ $? -eq 1 ]; then
    _error "You have uncommitted changes to the tokens repo.  Please commit or stash them before running this script."
    exit 1
  fi

  repo_has_changes "$cl_path"
  if [ $? -eq 1 ]; then
    _error "You have uncommitted changes to the component-library-twig repo.  Please commit or stash them before running this script."
    exit 1
  fi

  # Now that all checks have passed, let's do some work
  _say "Let the magic begin!"
  _say "********************"

  # Move yalesites branch
  clone_or_switch_branch "yalesites-project" "$yalesites_path" "$yalesites_branch"
  
  # If current branch did change
  if [ "$(current_branch_for_path "$atomic_path")" != "$atomic_branch" ]; then
    atomic_changed=true
  fi

  _say "Attempting to clone $atomic_branch branch of atomic repo"
  clone_or_switch_branch "atomic" "$atomic_path" "$atomic_branch"

  [ "$verbose" = true ] && _say "Moving to atomic repo"
  cd $atomic_path || (_error "Could not find atomic theme. Are you in the right directory?" && exit 1)

  [ ! -d "_yale-packages" ] && mkdir _yale-packages && _say "Creating _yale-packages directory for cloning"

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
  [ ! -L "atomic" ] && ln -s $atomic_path atomic
  [ ! -L "component-library-twig" ] && ln -s atomic/_yale-packages/component-library-twig component-library-twig
  [ ! -L "tokens" ] && ln -s atomic/_yale-packages/tokens tokens

  [ "$atomic_changed" = true ] && _say "Atomic theme changed, so we need to clear Drupal cache; this could take a while" && lando drush cr

  _say "********************"
  _say "All done!"
  _say "********************"
  _say "Current branches"
  _say "YaleSites:         $(current_branch_for_path "$yalesites_path")"
  _say "Atomic:            $(current_branch_for_path "$atomic_path")"
  _say "Component Library: $(current_branch_for_path "$cl_path")"
  _say "Tokens:            $(current_branch_for_path "$tokens_path")"
  _say "********************"
}

_local-git-checkout "$@"
