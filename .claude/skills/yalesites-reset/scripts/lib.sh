#!/bin/bash
# Shared helpers for the yalesites-reset skill.
# Source this from the other scripts: source "$(dirname "$0")/lib.sh"

# --- output helpers -----------------------------------------------------
_ok()   { printf '  \033[0;32m✔\033[0m %s\n' "$*"; }
_warn() { printf '  \033[0;33m!\033[0m %s\n' "$*"; }
_bad()  { printf '  \033[0;31m✖\033[0m %s\n' "$*"; }
_hdr()  { printf '\n\033[1m%s\033[0m\n' "$*"; }

# --- repo layout --------------------------------------------------------
# All four repos, in the order they must be synced.
# Format: "label|path|default-branch-fallback"
# The fallback is only used if the remote HEAD can't be read; the scripts
# prefer to ask the remote what its default branch actually is, because
# these have changed before (atomic and component-library both moved from
# main to develop) and hardcoding them is how you end up silently building
# the wrong code.
YS_REPOS=(
  "yalesites-project|.|develop"
  "atomic|web/themes/contrib/atomic|develop"
  "tokens|web/themes/contrib/atomic/_yale-packages/tokens|main"
  "component-library-twig|web/themes/contrib/atomic/_yale-packages/component-library-twig|develop"
)

# Ask the remote which branch it considers default. Falls back to the
# hardcoded value if the remote is unreachable (offline, VPN, etc).
ys_default_branch() {
  local path="$1" fallback="$2" head
  head=$(git -C "$path" ls-remote --symref origin HEAD 2>/dev/null \
         | awk '/^ref:/ {sub("refs/heads/","",$2); print $2; exit}')
  printf '%s' "${head:-$fallback}"
}

# --- environment --------------------------------------------------------
# Non-interactive shells don't source ~/.zshrc, so node and the GitHub
# Packages token are both missing unless we go get them. Every npm step in
# this project needs both.
ys_setup_env() {
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  # shellcheck disable=SC1091
  [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" >/dev/null 2>&1

  # .nvmrc may pin a patch version that isn't installed. Any Node 20 works,
  # so fall back to the newest installed 20.x rather than failing outright.
  if command -v nvm >/dev/null 2>&1; then
    nvm use >/dev/null 2>&1 || nvm use 20 >/dev/null 2>&1 || true
  fi

  # Guard the expansion: callers run with `set -u`, where a bare
  # "$YALESITES_BUILD_TOKEN" on an unset variable aborts the script -- which
  # is precisely the case this block exists to repair.
  if [ -z "${YALESITES_BUILD_TOKEN:-}" ] && [ -f "$HOME/.zshrc" ]; then
    export YALESITES_BUILD_TOKEN="$(grep -m1 '^export YALESITES_BUILD_TOKEN=' "$HOME/.zshrc" \
      | sed 's/^export YALESITES_BUILD_TOKEN=//' | tr -d '"'"'"'\n')"
  fi
}

# --- project root -------------------------------------------------------
# Walk up to the yalesites-project root and cd there.
#
# `git rev-parse --show-toplevel` is not good enough on its own: atomic,
# tokens and component-library-twig are each their own git repo nested
# inside this one, so running from the theme directory would land you on
# atomic's root instead. Look for the marker file instead of trusting git.
ys_cd_root() {
  local dir="$PWD"
  while [ "$dir" != "/" ]; do
    if [ -f "$dir/.lando.upstream.yml" ] && [ -d "$dir/web/profiles/custom/yalesites_profile" ]; then
      cd "$dir" || return 1
      return 0
    fi
    dir=$(dirname "$dir")
  done
  return 1
}

# Guard against running any of this from the wrong directory: several steps
# delete and re-import a database, so being in the wrong repo is expensive.
ys_require_root() {
  if [ ! -f .lando.upstream.yml ] || [ ! -d web/profiles/custom/yalesites_profile ]; then
    _bad "Not in the yalesites-project root (no .lando.upstream.yml + yalesites_profile)."
    _bad "cd to the project root and try again."
    exit 1
  fi
}

# --- site url -----------------------------------------------------------
# OrbStack (or anything else holding :80/:443) pushes Lando's proxy onto the
# fallback ports declared in .lando.local.yml, so the canonical lndo.site URL
# is frequently NOT on 443. Read the real URL out of lando rather than
# assuming; a bare https://<site>.lndo.site/ will 404 from the Lando proxy
# and look exactly like a broken Drupal install.
ys_site_url() {
  lando info --format=json 2>/dev/null | python3 -c '
import json, sys
try:
    services = json.load(sys.stdin)
except Exception:
    sys.exit(0)
urls = [u for s in services for u in (s.get("urls") or [])]
https = [u for u in urls if u.startswith("https://") and "lndo.site" in u]
http  = [u for u in urls if u.startswith("http://")  and "lndo.site" in u]
best = (https or http or [""])[0]
print(best.rstrip("/"))
' 2>/dev/null
}
