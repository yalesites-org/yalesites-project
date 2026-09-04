#!/bin/bash
# Read-only preflight check. Safe to run at any time; changes nothing.
# Exits non-zero if something would block a reset.

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
ys_cd_root
ys_require_root
ys_setup_env

FAIL=0
BLOCK=""

_hdr "Tooling"
NODE_V=$(node -v 2>/dev/null)
case "$NODE_V" in
  v20.*) _ok "node $NODE_V" ;;
  "")    _bad "node not found"; FAIL=1; BLOCK="$BLOCK\n  - Install Node 20 (nvm install 20)" ;;
  *)     _bad "node $NODE_V — project needs Node 20 (.nvmrc: $(cat .nvmrc 2>/dev/null))"
         FAIL=1; BLOCK="$BLOCK\n  - Run: nvm install 20 && nvm use 20" ;;
esac

if [ -n "$YALESITES_BUILD_TOKEN" ]; then
  _ok "YALESITES_BUILD_TOKEN present (${#YALESITES_BUILD_TOKEN} chars)"
else
  _bad "YALESITES_BUILD_TOKEN not set — @yalesites-org npm installs will 401"
  FAIL=1; BLOCK="$BLOCK\n  - export YALESITES_BUILD_TOKEN=<github PAT with write:packages>"
fi

for bin in lando terminus composer python3 rsync; do
  if command -v "$bin" >/dev/null 2>&1; then _ok "$bin"; else
    _bad "$bin not found"; FAIL=1; BLOCK="$BLOCK\n  - Install $bin"
  fi
done

_hdr "Services"
if docker info >/dev/null 2>&1; then _ok "docker responding"; else
  _bad "docker not responding — start Docker/OrbStack"; FAIL=1
  BLOCK="$BLOCK\n  - Start Docker or OrbStack"
fi

if lando list --app yalesites-platform 2>/dev/null | grep -q "running: true"; then
  _ok "lando app running"
else
  _warn "lando app not running (the reset will start it)"
fi

_hdr "Pantheon access"
WHO=$(terminus auth:whoami 2>/dev/null | head -1)
if [ -n "$WHO" ]; then
  _ok "terminus authenticated as $WHO"
else
  _bad "terminus not authenticated"; FAIL=1
  BLOCK="$BLOCK\n  - terminus auth:login --email=<you> --machine-token=<token>"
fi

# lando pull needs SSH into Pantheon, which is frequently broken locally
# (keys mounted at /user/.ssh but never symlinked into the container's
# ~/.ssh). The reset uses terminus over HTTPS instead, so this is advisory
# only -- but it explains why `npm run db:get` fails if you try it by hand.
if lando ssh -c "ls /var/www/.ssh/" 2>/dev/null | grep -qE '^id_'; then
  _ok "lando container has ssh keys (lando pull should work)"
else
  _warn "lando container has no ssh keys — 'lando pull' / 'npm run db:get' will fail"
  _warn "  the reset works around this using terminus over HTTPS (see references/gotchas.md)"
fi

_hdr "Repos"
for entry in "${YS_REPOS[@]}"; do
  IFS='|' read -r name path fallback <<< "$entry"
  if [ ! -d "$path/.git" ]; then
    _bad "$name missing at $path"; FAIL=1
    BLOCK="$BLOCK\n  - $name not cloned; run npm run local:git-checkout"
    continue
  fi
  branch=$(git -C "$path" rev-parse --abbrev-ref HEAD)
  target=$(ys_default_branch "$path" "$fallback")
  # Only *tracked* modifications are at risk: the reset switches branches and
  # lets composer re-checkout atomic, both of which can clobber edits to
  # tracked files. Nothing in the reset runs `git clean`, so untracked files
  # (.claude/, secrets.json, reference/, .lando.local.yml) survive fine and
  # are reported as information rather than treated as blockers.
  tracked=$(git -C "$path" status --porcelain --untracked-files=no | wc -l | tr -d ' ')
  untracked=$(git -C "$path" ls-files --others --exclude-standard | wc -l | tr -d ' ')
  if [ "$tracked" -gt 0 ]; then
    _bad "$name: $tracked tracked change(s) — the reset would overwrite them"
    git -C "$path" status --short --untracked-files=no | sed 's/^/       /' | head -5
    FAIL=1
    BLOCK="$BLOCK\n  - Commit or stash your work in $name ($path)"
  elif [ "$branch" != "$target" ]; then
    _warn "$name: on '$branch', will be switched to '$target'"
  else
    _ok "$name: on $target (clean)"
  fi
  [ "$untracked" -gt 0 ] && _warn "  ($name has $untracked untracked file(s); these are left alone)"
done

_hdr "Result"
if [ "$FAIL" -eq 0 ]; then
  _ok "Ready to reset."
  URL=$(ys_site_url); [ -n "$URL" ] && echo "  Current site URL: $URL"
  exit 0
fi
_bad "Blocked. Fix these first:"
printf "%b\n" "$BLOCK"
exit 1
