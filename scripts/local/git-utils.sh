#!/bin/bash

# Git Utilities for YaleSites Project
# Provides functions to detect and handle both regular git repositories and git worktrees

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if current directory is inside a git repository
is_git_repo() {
  git rev-parse --is-inside-work-tree >/dev/null 2>&1
}

# Detect if current location is a git worktree
is_worktree() {
  if ! is_git_repo; then
    return 1
  fi
  
  # Check if .git is a file (worktree) or directory (regular repo)
  if [ -f .git ]; then
    # Additional check: worktree .git files contain "gitdir: " reference
    grep -q "gitdir: " .git 2>/dev/null
    return $?
  else
    return 1
  fi
}

# Get the type of git setup: "regular", "worktree", or "none"
detect_git_type() {
  if ! is_git_repo; then
    echo "none"
    return 1
  fi
  
  if is_worktree; then
    echo "worktree"
  else
    echo "regular"
  fi
}

# Get the root directory of the current git repository
get_git_root() {
  if ! is_git_repo; then
    echo "Error: Not in a git repository" >&2
    return 1
  fi
  
  git rev-parse --show-toplevel
}

# Get the main .git directory (handles both regular repos and worktrees)
get_main_git_dir() {
  if ! is_git_repo; then
    echo "Error: Not in a git repository" >&2
    return 1
  fi
  
  # This works for both regular repos and worktrees
  git rev-parse --git-common-dir
}

# Get the repository-specific .git directory
get_repo_git_dir() {
  if ! is_git_repo; then
    echo "Error: Not in a git repository" >&2
    return 1
  fi
  
  git rev-parse --git-dir
}

# Get the worktree name (basename of the worktree directory)
get_worktree_name() {
  if ! is_worktree; then
    echo "Error: Not in a git worktree" >&2
    return 1
  fi
  
  basename "$(get_git_root)"
}

# Get the main repository directory (parent of .git for regular repos, or common git dir parent for worktrees)
get_main_repo_dir() {
  if ! is_git_repo; then
    echo "Error: Not in a git repository" >&2
    return 1
  fi
  
  local main_git_dir
  main_git_dir=$(get_main_git_dir)
  
  if is_worktree; then
    # For worktrees, the main repo is the parent of the main .git directory
    dirname "$main_git_dir"
  else
    # For regular repos, it's the parent of the .git directory
    dirname "$main_git_dir"
  fi
}

# Get the path to the git exclude file (handles both regular repos and worktrees)
get_git_exclude_file() {
  if ! is_git_repo; then
    echo "Error: Not in a git repository" >&2
    return 1
  fi
  
  local main_git_dir
  main_git_dir=$(get_main_git_dir)
  echo "$main_git_dir/info/exclude"
}

# Print git repository information
print_git_info() {
  local git_type
  git_type=$(detect_git_type)
  
  echo -e "${GREEN}Git Repository Information:${NC}"
  echo "Type: $git_type"
  
  if [ "$git_type" != "none" ]; then
    echo "Root: $(get_git_root)"
    echo "Main .git dir: $(get_main_git_dir)"
    echo "Repo .git dir: $(get_repo_git_dir)"
    echo "Exclude file: $(get_git_exclude_file)"
    
    if [ "$git_type" = "worktree" ]; then
      echo "Worktree name: $(get_worktree_name)"
      echo "Main repo dir: $(get_main_repo_dir)"
    fi
  fi
}

# Validate git setup and print warnings if needed
validate_git_setup() {
  local git_type
  git_type=$(detect_git_type)
  
  case "$git_type" in
    "none")
      echo -e "${RED}Error: Not in a git repository${NC}"
      return 1
      ;;
    "regular")
      echo -e "${GREEN}✓ Regular git repository detected${NC}"
      ;;
    "worktree")
      echo -e "${GREEN}✓ Git worktree detected${NC}"
      echo -e "${YELLOW}Note: Running in worktree mode${NC}"
      ;;
  esac
  
  return 0
}

# Create a directory path that works for both regular repos and worktrees
# Usage: create_repo_relative_path <relative_path>
create_repo_relative_path() {
  local relative_path="$1"
  local git_root
  
  if [ -z "$relative_path" ]; then
    echo "Error: No relative path provided" >&2
    return 1
  fi
  
  git_root=$(get_git_root)
  if [ $? -ne 0 ]; then
    return 1
  fi
  
  echo "$git_root/$relative_path"
}

# Check if a path exists and is a git repository
is_git_repo_at_path() {
  local path="$1"
  
  if [ -z "$path" ]; then
    echo "Error: No path provided" >&2
    return 1
  fi
  
  if [ ! -d "$path" ]; then
    return 1
  fi
  
  (cd "$path" && is_git_repo)
}

# Clone or update a repository at a specific path
# Usage: clone_or_update_repo <git_url> <target_path> [branch]
clone_or_update_repo() {
  local git_url="$1"
  local target_path="$2"
  local branch="${3:-main}"
  
  if [ -z "$git_url" ] || [ -z "$target_path" ]; then
    echo "Error: git_url and target_path are required" >&2
    return 1
  fi
  
  if is_git_repo_at_path "$target_path"; then
    echo "Repository already exists at $target_path, updating..."
    (cd "$target_path" && git fetch --all && git checkout "$branch" && git pull)
  else
    echo "Cloning repository to $target_path..."
    git clone "$git_url" "$target_path" -b "$branch"
  fi
}

# If this script is being sourced, don't run the main function
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  # Script is being executed directly, show git info
  print_git_info
fi