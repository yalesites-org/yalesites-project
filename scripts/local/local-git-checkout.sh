#!/bin/bash

# local-git-checkout.sh
#
# Brings a local YaleSites instance onto a branch across all four repos. Run it
# from the yalesites-project root (npm run local:git-checkout -- -b <branch>).
#
# In order, it:
#  1. Refuses to run if any repo has uncommitted (staged or unstaged) changes
#     or untracked files.
#  2. Switches yalesites-project to its branch.
#  3. Runs `lando composer update` so contrib modules added or removed by the
#     branch are picked up (skip with -s). The gitignored
#     atomic/_yale-packages directory is held outside atomic while composer
#     runs, because composer may check atomic out at a release tag.
#  4. Switches atomic, tokens and component-library-twig to their branches.
#     Each repo gets an origin remote if it lacks one. A branch that does not
#     exist falls back to develop (yalesites-project) or main (the others).
#     Each repo is then fast-forwarded to its upstream when it has one.
#  5. npm-links tokens -> component-library-twig -> atomic.
#  6. Builds tokens and component-library-twig.
#  7. Creates the root atomic, component-library-twig and tokens symlinks.
#  8. Clears the Drupal cache when atomic changed branches or composer
#     changed installed packages. It never touches the database: run
#     `npm run confim` after.
#
# Flags:
#  -b <branch>  branch for all repos
#  -c <branch>  component-library-twig branch
#  -t <branch>  tokens branch
#  -a <branch>  atomic branch
#  -m <branch>  yalesites-project branch
#  -s           skip the composer update
#  -v           verbose mode
#  -d           debug mode (set -x)
#  -h           show help

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

# branch_label
#
# This function will print the current branch for a given path, or
# "detached at <commit>" when HEAD is detached.
#
# params:
#  git_path: the path to the repo
#
function branch_label() {
  local git_path="${1-$(pwd)}"

  git -C "$git_path" symbolic-ref --quiet --short HEAD ||
    echo "detached at $(git -C "$git_path" rev-parse --short HEAD)"
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
  local origin="${3-origin}"
  local remote_branches

  if git -C "$git_path" rev-parse --quiet --verify "$origin/$branch_name" > /dev/null; then
    return 0
  fi

  remote_branches=$(git -C "$git_path" ls-remote --heads "$origin" | awk '{print $2}' | sed 's#refs/heads/##')
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

# git_checkout
#
# This function will checkout a branch if it exists.
#
# params:
#  git_path: the path to the repo
#  target_branch: the branch to switch to
#  origin: the origin to use
#
function git_checkout() {
  local git_path="${1-$(pwd)}"
  local target_branch=${2:-main}
  local origin=${3:-origin}
  local worktree_path

  # Git refuses to check out a branch that another worktree has checked out,
  # so check out that branch's commit detached instead.
  worktree_path=$(branch_worktree_path "$git_path" "$target_branch")
  if [ -n "$worktree_path" ]; then
    _say "$target_branch is checked out in the worktree at $worktree_path; checking out its latest commit detached instead"
    git -C "$git_path" checkout --detach "$target_branch"
    return
  fi

  git -C "$git_path" checkout "$target_branch" || git -C "$git_path" checkout --track "$origin/$target_branch"
}

# branch_worktree_path
#
# This function will print the path of the worktree that has a branch checked
# out, or nothing if no worktree does.
#
# params:
#  git_path: the path to the repo
#  branch: the branch to look for
#
function branch_worktree_path() {
  local git_path="$1"
  local branch="$2"

  git -C "$git_path" worktree list --porcelain |
    awk -v ref="refs/heads/$branch" '/^worktree /{path=substr($0, 10)} $0 == "branch " ref {print path; exit}'
}

# is_on_branch
#
# This function will return 0 if a repo is on a branch, or is detached at the
# commit of a branch that another worktree holds (see git_checkout), and 1
# otherwise.
#
# params:
#  git_path: the path to the repo
#  branch: the branch to check for
#
function is_on_branch() {
  local git_path="$1"
  local branch="$2"
  local current_branch

  current_branch=$(current_branch_for_path "$git_path")
  [ "$current_branch" = "$branch" ] && return 0
  [ "$current_branch" = "HEAD" ] &&
    [ -n "$(branch_worktree_path "$git_path" "$branch")" ] &&
    [ "$(git -C "$git_path" rev-parse HEAD)" = "$(git -C "$git_path" rev-parse --quiet --verify "refs/heads/$branch")" ]
}

# ensure_origin_remote
#
# This function will add an origin remote to a repo that has none. It reuses
# the URL of the composer remote when there is one, otherwise it uses the
# yalesites-org GitHub URL. An existing composer remote is left alone.
#
# params:
#  name: the name of the repo
#  git_path: the path to the repo
#
function ensure_origin_remote() {
  local name="$1"
  local git_path="${2-$(pwd)}"
  local url

  git -C "$git_path" remote get-url origin > /dev/null 2>&1 && return 0

  url=$(git -C "$git_path" remote get-url composer 2> /dev/null) ||
    url="https://github.com/yalesites-org/$name.git"

  _say "Adding origin remote $url to the $name repo"
  git -C "$git_path" remote add origin "$url" ||
    { _error "Could not add an origin remote to the $name repo"; exit 1; }
}

# fast_forward
#
# This function will fast-forward the current branch of a repo to its
# upstream, if it has one. A branch that has diverged is left alone with a
# warning.
#
# params:
#  name: the name of the repo
#  git_path: the path to the repo
#
function fast_forward() {
  local name="$1"
  local git_path="${2-$(pwd)}"

  git -C "$git_path" rev-parse --abbrev-ref '@{u}' > /dev/null 2>&1 || return 0

  if ! git -C "$git_path" fetch --quiet; then
    _error "Warning: could not fetch the $name repo; not fast-forwarding it"
    return 0
  fi

  git -C "$git_path" merge --ff-only '@{u}' ||
    _error "Warning: could not fast-forward the $name repo (it may have diverged from its upstream); continuing"
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

  ensure_origin_remote "$name" "$git_path"

  # Get current branch of repo
  current_branch=$(current_branch_for_path "$git_path")

  # If current branch is not the target branch
  if ! is_on_branch "$git_path" "$target_branch"; then
    git -C "$git_path" fetch --all
    # If there are no uncommitted changes, prepare to switch to the target branch
    if git -C "$git_path" diff --quiet --exit-code && git -C "$git_path" diff --cached --quiet; then
      # If the target branch doesn't exist, switch to the backup branch
      (! branch_exists "$target_branch" "$git_path" "$origin") && _error "Target branch $target_branch does not exist, switching to $backup_branch" && target_branch="$backup_branch"

      _say "Current branch of $name is $current_branch, switching to $target_branch"
      git_checkout "$git_path" "$target_branch" "$origin"

      # Verify that the checkout was successful
      if ! is_on_branch "$git_path" "$target_branch"; then
        # If not successful, try the backup branch
        _error "Failed to switch to $target_branch branch of $name repo"
        _say "Attempting to switch to $backup_branch branch of $name repo"
        git_checkout "$git_path" "$backup_branch" "$origin"

        # check if that was successful
        if ! is_on_branch "$git_path" "$backup_branch"; then
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

  fast_forward "$name" "$git_path"
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

  # Check for uncommitted changes, staged or not
  if ! git -C "$git_path" diff --quiet --exit-code || ! git -C "$git_path" diff --cached --quiet; then
    return 1
  fi

  # Check for untracked files
  if [ "$(git -C "$git_path" status --porcelain | grep -c '^??')" -gt 0 ]; then
    return 1
  fi

  return 0
}

# restore_yale_packages
#
# This function will move a held _yale-packages directory back into atomic.
# It does nothing if nothing is held.
#
# params:
#  atomic_path: the path to the atomic theme
#  hold_path: where _yale-packages is held
#
function restore_yale_packages() {
  local atomic_path="$1"
  local hold_path="$2"

  [ -e "$hold_path" ] || return 0

  if [ ! -d "$atomic_path" ] || [ -e "$atomic_path/_yale-packages" ]; then
    _error "Could not restore $hold_path to $atomic_path/_yale-packages. Inspect both and move $hold_path back by hand."
    exit 1
  fi

  _say "Restoring _yale-packages into $atomic_path"
  mv "$hold_path" "$atomic_path/_yale-packages" ||
    { _error "Could not move $hold_path back to $atomic_path/_yale-packages. Move it back by hand."; exit 1; }
}

# refresh_composer_dependencies
#
# This function will run `lando composer update` so contrib modules added or
# removed by the yalesites-project branch are picked up.
# When the pinned atomic version changes, composer checks atomic out at the
# release tag in place, so _yale-packages is held outside atomic while it runs.
# The caller restores it with restore_yale_packages after atomic is switched.
# If composer leaves atomic without a .git directory, atomic is moved aside so
# the clone step re-clones it.
#
# params:
#  atomic_path: the path to the atomic theme
#  hold_path: where to hold _yale-packages while composer runs
#
function refresh_composer_dependencies() {
  local atomic_path="$1"
  local hold_path="$2"
  local aside_path

  if [ -d "$atomic_path/_yale-packages" ]; then
    _say "Holding $atomic_path/_yale-packages at $hold_path while composer runs"
    mv "$atomic_path/_yale-packages" "$hold_path" ||
      { _error "Could not move $atomic_path/_yale-packages to $hold_path"; exit 1; }
  fi

  _say "Updating composer dependencies (skip with -s)"
  if ! lando composer update; then
    restore_yale_packages "$atomic_path" "$hold_path"
    _error "lando composer update failed"
    exit 1
  fi

  if [ -d "$atomic_path" ] && [ ! -d "$atomic_path/.git" ]; then
    # Dot-prefixed so Drupal does not discover it as a second atomic theme
    aside_path="$(dirname "$atomic_path")/.atomic.composer-$(date +%Y%m%d%H%M%S)"
    _say "Composer installed atomic without git history; moving it to $aside_path (hidden from Drupal's theme scan) so it is re-cloned"
    if ! mv "$atomic_path" "$aside_path"; then
      restore_yale_packages "$atomic_path" "$hold_path"
      _error "Could not move $atomic_path to $aside_path"
      exit 1
    fi
  fi
}

# atomic_install_source
#
# This function will print how composer installed atomic ("source" for a git
# clone, "dist" for a zip), read from vendor/composer/installed.json. It
# prints nothing if node, the file, or the package is missing. Handles both
# the current {"packages": [...]} shape and the older top-level array.
#
# params:
#  installed_json: path to installed.json (default: vendor/composer/installed.json)
#
function atomic_install_source() {
  local installed_json="${1-$(pwd)/vendor/composer/installed.json}"

  command -v node > /dev/null 2>&1 || return 0
  [ -f "$installed_json" ] || return 0

  node -e 'const d=require(process.argv[1]);const p=(d.packages||d).find(x=>x.name==="yalesites-org/atomic");process.stdout.write(p?p["installation-source"]||"":"")' "$installed_json" 2> /dev/null
}

# usage
#
# This function will print the usage text.
#
function usage() {
  echo "Usage: $0 [-h] [-d] [-v] [-s] [-b <branch>] [-c <branch>] [-t <branch>] [-a <branch>] [-m <branch>]"
  echo ""
  echo "-b <branch>: branch for all repos - use if all repos are on the same branch name"
  echo "-c <branch>: component library branch to use"
  echo "-t <branch>: tokens branch to use"
  echo "-a <branch>: atomic branch to use"
  echo "-m <branch>: yalesites branch to use"
  echo "-s: skip the composer update"
  echo "-v: verbose mode"
  echo "-d: debug mode"
  echo "-h: show this help"
}

# Main function that does the work
function _local-git-checkout() {
  # Default values
  local default_branch="main"
  local default_yalesites_branch="develop"
  local cl_branch="$default_branch"
  local token_branch="$default_branch"
  local atomic_branch="$default_branch"
  local yalesites_branch="$default_yalesites_branch"
  local debug=false
  local verbose=false
  local skip_composer=false

  # Default paths
  local atomic_path="web/themes/contrib/atomic"
  local tokens_path="$atomic_path/_yale-packages/tokens"
  local cl_path="$atomic_path/_yale-packages/component-library-twig"
  local yalesites_path="./"
  local yale_packages_hold="web/themes/contrib/.yale-packages-hold"

  # If atomic changes branches, we need to know this so we can know to 
  # clear Drupal's cache toward the end of the script.
  local atomic_changed=false
  # Set when composer changed installed packages, which also needs a cache clear.
  local deps_refreshed=false

  # getopts - Parse the options to then use
  while getopts ":dvshc:t:a:b:m:" opt; do
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
      s )
        skip_composer=true
        ;;
      h )
        usage
        exit 0
        ;;
      b )
        atomic_branch=$OPTARG
        cl_branch=$OPTARG
        token_branch=$OPTARG
        yalesites_branch=$OPTARG
        ;;
      \? )
        echo "Invalid option: -$OPTARG" >&2
        usage
        exit 1
        ;;
      :)
        echo "Option -$OPTARG requires an argument." >&2
        usage
        exit 1
        ;;
    esac
  done

  # `npm run` puts yalesites-project's node_modules/.bin first on PATH, where
  # semantic-release installs its own older npm. That npm reads the overrides
  # in the other repos' lockfiles differently and makes `npm ci` fail, so drop
  # node_modules/.bin from PATH and use the developer's npm.
  PATH=$(echo "$PATH" | tr ':' '\n' | grep -v '/node_modules/\.bin$' | paste -sd ':' -)

  # Check for utilities directory
  local utils_path="./scripts/local/util"
  [ -e "$utils_path" ] || { echo -e "[$0] Utilities not found.  You must run this from the yalesites root directory: "; exit 1; }

  # source say.sh so we can use the _say and _error functions
  [ -e "$utils_path/say.sh" ] || { echo -e "[$0] Say utility not found.  You must run this from the yalesites root directory: "; exit 1; }
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

  if [ -e "$yale_packages_hold" ]; then
    _error "$yale_packages_hold exists, probably left over from an interrupted run. Inspect it and move it back to $atomic_path/_yale-packages (or remove it) before running this script again."
    exit 1
  fi

  # Composer may replace the atomic directory, which would lose anything that
  # lives only in atomic's .git: unpushed commits, local-only branches, stashes.
  # A source (git) install is updated in place with `git checkout <tag>`, which
  # keeps all of that, so the guard is skipped for it.
  if [ "$skip_composer" = false ] && [ -d "$atomic_path/.git" ] &&
    [ "$(atomic_install_source)" != "source" ]; then
    local atomic_unpushed atomic_stashes
    atomic_unpushed=$(git -C "$atomic_path" log --branches --not --remotes --oneline)
    atomic_stashes=$(git -C "$atomic_path" stash list)
    if [ -n "$atomic_unpushed" ] || [ -n "$atomic_stashes" ]; then
      _error "The atomic repo has work that exists only locally, which the composer update could lose:"
      [ -n "$atomic_unpushed" ] && _error "Unpushed commits or local-only branches:\n$atomic_unpushed"
      [ -n "$atomic_stashes" ] && _error "Stashes:\n$atomic_stashes"
      _error "Push it (or drop it), or rerun with -s to skip the composer update."
      exit 1
    fi
  fi

  # Now that all checks have passed, let's do some work
  _say "Let the magic begin!"
  _say "********************"

  # Move yalesites branch
  clone_or_switch_branch "yalesites-project" "$yalesites_path" "$yalesites_branch" "$default_yalesites_branch"

  # Composer runs before the atomic switch, which puts atomic back on its
  # branch if composer checked it out at a release tag.
  if [ "$skip_composer" = false ]; then
    # Compare composer's record of installed packages before and after, so the
    # cache is only cleared when composer actually changed something.
    local installed_before
    installed_before=$(cksum vendor/composer/installed.json 2> /dev/null)
    refresh_composer_dependencies "$atomic_path" "$yale_packages_hold"
    if [ "$(cksum vendor/composer/installed.json 2> /dev/null)" != "$installed_before" ]; then
      deps_refreshed=true
    fi
  fi

  # If current branch did change (checked after composer, so a detached or
  # missing atomic counts as changed)
  if ! is_on_branch "$atomic_path" "$atomic_branch" 2> /dev/null; then
    atomic_changed=true
  fi

  _say "Attempting to clone $atomic_branch branch of atomic repo"
  clone_or_switch_branch "atomic" "$atomic_path" "$atomic_branch" "$default_branch"

  # Put _yale-packages back now that atomic is on its branch (or re-cloned)
  restore_yale_packages "$atomic_path" "$yale_packages_hold"

  [ "$verbose" = true ] && _say "Moving to atomic repo"
  cd "$atomic_path" || { _error "Could not find atomic theme. Are you in the right directory?"; exit 1; }

  [ ! -d "_yale-packages" ] && mkdir _yale-packages && _say "Creating _yale-packages directory for cloning"

  _say "Attempting to clone $token_branch branch of tokens repo"
  clone_or_switch_branch "tokens" "_yale-packages/tokens" "$token_branch" "$default_branch"

  _say "Moving into tokens repo and creating a global npm link"
  cd _yale-packages/tokens || { _error "Could not find tokens repo. Are you in the right directory?"; exit 1; }
  npm link || { _error "npm link failed in the tokens repo"; exit 1; }

  [ "$verbose" = true ] && _say "Moving back to atomic"
  cd ../..

  _say "Attempting to clone $cl_branch branch of component-library-twig repo"
  clone_or_switch_branch "component-library-twig" "_yale-packages/component-library-twig" "$cl_branch" "$default_branch"

  [ "$verbose" = true ] && _say "Moving into component library"
  cd _yale-packages/component-library-twig || { _error "Could not find component-library-twig repo. Are you in the right directory?"; exit 1; }

  _say "Creating a global npm link for the component library"
  # The component library's install scripts patch its dependencies, which a
  # fresh clone does not have yet. Skip them here; the clean install below
  # installs the dependencies and runs them.
  npm link --ignore-scripts || { _error "npm link failed in the component-library-twig repo"; exit 1; }

  [ "$verbose" = true ] && _say "Moving back to atomic"
  cd ../..

  _say "Using the component-library global npm link inside the atomic theme"
  # Linking runs the component library's patch scripts mid-install, where they
  # usually fail even though the link is made; the clean install below
  # re-applies them. So the output goes to a log, and only a missing link is
  # fatal (the log is printed then).
  local link_log tmp_dir="${TMPDIR:-/tmp}"
  link_log=$(mktemp "${tmp_dir%/}/local-git-checkout-npm-link.XXXXXX") ||
    { _error "Could not create a temp file for the npm link log"; exit 1; }
  if npm link @yalesites-org/component-library-twig > "$link_log" 2>&1; then
    rm -f "$link_log"
  elif [ -L node_modules/@yalesites-org/component-library-twig ]; then
    _say "npm reported errors from the component library's install scripts (expected; the clean install below re-applies them). Log: $link_log"
  else
    cat "$link_log"
    _error "npm link @yalesites-org/component-library-twig failed in atomic. Log: $link_log"
    exit 1
  fi

  [ "$verbose" = true ] && _say "Moving into the component library"
  cd _yale-packages/component-library-twig || { _error "Could not find component-library-twig repo. Are you in the right directory?"; exit 1; }

  _say "Running clean install so we can patch Twig.js"
  npm ci -y || { _error "npm ci failed in the component-library-twig repo"; exit 1; }

  _say "Using the tokens global npm link inside the component library"
  npm link @yalesites-org/tokens || { _error "npm link @yalesites-org/tokens failed in the component-library-twig repo"; exit 1; }

  [ "$verbose" = true ] && _say "Moving back to atomic"
  cd ../..

  _say "Attempting to npm link tokens inside atomic"
  # You can't do this because only one npm link can exist at a time on a node_module folder :(
  # npm link @yalesites-org/tokens
  # So we do it ourselves
  rm -rf node_modules/@yalesites-org/tokens
  mkdir -p node_modules/@yalesites-org
  cd node_modules/@yalesites-org || { _error "Could not find atomic's node_modules/@yalesites-org directory"; exit 1; }
  ln -s ../../_yale-packages/tokens tokens || { _error "Could not link tokens into atomic"; exit 1; }
  cd ../..

  [ "$verbose" = true ] && _say "Moving to tokens repo"
  cd _yale-packages/tokens || { _error "Could not find tokens repo. Are you in the right directory?"; exit 1; }

  _say "Building tokens"
  npm ci || { _error "npm ci failed in the tokens repo"; exit 1; }
  npm run build || { _error "Tokens build failed"; exit 1; }

  _say "Rebuilding component library to have dist folder"
  cd ../component-library-twig || { _error "Could not find component-library-twig repo. Are you in the right directory?"; exit 1; }
  npm run build || { _error "Component library build failed"; exit 1; }

  _say "Symlinking to root directory"
  cd ../../../../../..
  [ ! -L "atomic" ] && ln -s "$atomic_path" atomic
  [ ! -L "component-library-twig" ] && ln -s atomic/_yale-packages/component-library-twig component-library-twig
  [ ! -L "tokens" ] && ln -s atomic/_yale-packages/tokens tokens

  if [ "$atomic_changed" = true ] || [ "$deps_refreshed" = true ]; then
    _say "Atomic or composer dependencies changed, so we need to clear Drupal cache; this could take a while"
    lando drush cr || _error "Warning: lando drush cr failed; run it by hand"
  fi

  _say "********************"
  _say "All done!"
  _say "********************"
  _say "Current branches"
  _say "YaleSites:         $(branch_label "$yalesites_path")"
  _say "Atomic:            $(branch_label "$atomic_path")"
  _say "Component Library: $(branch_label "$cl_path")"
  _say "Tokens:            $(branch_label "$tokens_path")"
  _say "********************"
  _say "Run \`npm run confim\` to import the configuration for this branch."
}

_local-git-checkout "$@"
