#!/bin/bash
# Post-reset verification. Read-only.
#
# This exists because the failure modes in this stack are quiet: composer
# leaves atomic on a detached tag, a broken symlink silently swaps in a
# stale tokens package, and `lando pull` skips itself while exiting 0. A
# reset that "succeeded" can still leave you building the wrong code, so
# check the things that actually break rather than trusting exit codes.

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
ys_cd_root
ys_require_root
ys_setup_env

FAIL=0

_hdr "Repos"
for entry in "${YS_REPOS[@]}"; do
  IFS='|' read -r name path fallback <<< "$entry"
  branch=$(git -C "$path" rev-parse --abbrev-ref HEAD 2>/dev/null)
  target=$(ys_default_branch "$path" "$fallback")
  short=$(git -C "$path" rev-parse --short HEAD 2>/dev/null)
  if [ "$branch" = "HEAD" ]; then
    # composer with preferred-install=source checks atomic out at its
    # released tag, which silently takes you off develop.
    _bad "$name: DETACHED at $(git -C "$path" describe --all 2>/dev/null) — expected $target"
    FAIL=1
  elif [ "$branch" != "$target" ]; then
    _bad "$name: on '$branch', expected '$target'"; FAIL=1
  else
    behind=$(git -C "$path" rev-list --count "HEAD..origin/$branch" 2>/dev/null || echo "?")
    if [ "$behind" != "0" ] && [ "$behind" != "?" ]; then
      _warn "$name: $branch @ $short ($behind behind origin)"
    else
      _ok "$name: $branch @ $short"
    fi
  fi
done

_hdr "Frontend links"
TOK="web/themes/contrib/atomic/node_modules/@yalesites-org/tokens"
if [ -L "$TOK" ] && [ -d "$TOK" ]; then
  RESOLVED=$(cd "$TOK" && pwd -P)
  EXPECTED=$(cd web/themes/contrib/atomic/_yale-packages/tokens && pwd -P)
  if [ "$RESOLVED" = "$EXPECTED" ]; then
    _ok "atomic → local tokens ($(grep -m1 '"version"' "$TOK/package.json" | tr -dc '0-9.'))"
  else
    _bad "atomic tokens symlink points at $RESOLVED"; FAIL=1
  fi
else
  # Caused by the @yalesitesorg typo in scripts/local/local-git-checkout.sh
  # -- see references/gotchas.md.
  _bad "atomic tokens is NOT a symlink — stale registry copy is shadowing the local repo"
  [ -f "$TOK/package.json" ] && _bad "  resolving to v$(grep -m1 '"version"' "$TOK/package.json" | tr -dc '0-9.') instead of the local repo"
  FAIL=1
fi

CLLINK="web/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig"
if [ -L "$CLLINK" ]; then _ok "atomic → local component-library"; else
  _bad "atomic component-library is not linked"; FAIL=1; fi

if [ -d "web/themes/contrib/atomic/_yale-packages/component-library-twig/dist/css" ]; then
  _ok "component-library dist built"
else
  _bad "component-library dist missing — run npm run local:git-checkout"; FAIL=1
fi

if [ -d "web/themes/contrib/atomic/_yale-packages/tokens/build" ]; then
  _ok "tokens build present"
else
  _bad "tokens build missing"; FAIL=1
fi

_hdr "Drupal"
STATUS=$(lando drush status 2>/dev/null)
if echo "$STATUS" | grep -q "Successful"; then
  _ok "bootstrap OK — $(echo "$STATUS" | awk -F': *' '/Drupal version/{print $2}' | xargs)"
  _ok "profile: $(echo "$STATUS" | awk -F': *' '/Install profile/{print $2}' | xargs), theme: $(echo "$STATUS" | awk -F': *' '/Default theme/{print $2}' | xargs)"
else
  _bad "Drupal did not bootstrap"; FAIL=1
fi

# The AI module needs yethee/tiktoken on the autoloader. If `composer
# update` dies before dumping the autoloader, the class is on disk but
# unregistered and config:import fatals on the AI search server.
if lando ssh -c "php -r 'require \"/app/vendor/autoload.php\"; exit(class_exists(\"Yethee\\\\Tiktoken\\\\EncoderProvider\") ? 0 : 1);'" >/dev/null 2>&1; then
  _ok "composer autoloader healthy (tiktoken resolves)"
else
  _bad "tiktoken not autoloadable — run: lando composer dump-autoload"; FAIL=1
fi

# drush writes its [success] lines to stderr, so this must merge streams --
# checking stdout alone always looks like there are pending updates.
if lando drush updatedb:status 2>&1 | grep -q "No database updates required"; then
  _ok "no pending database updates"
else
  _warn "pending database updates — run lando drush deploy"
fi

DIFFS=$(lando drush config:status --format=list 2>/dev/null | grep -v '^$' || true)
if [ -z "$DIFFS" ]; then
  _ok "config fully in sync"
else
  for c in $DIFFS; do
    case "$c" in
      # Values are identical; only the ordering of field_icon within
      # `content` differs. Pre-existing artifact of the committed sync file.
      core.entity_form_display.block_content.inline_message.default)
        _ok "config in sync (ignoring known benign ordering diff: $c)" ;;
      *) _warn "config differs from sync: $c" ;;
    esac
  done
fi

_hdr "HTTP"
URL=$(ys_site_url)
if [ -z "$URL" ]; then
  _bad "Could not determine the site URL from lando info"; FAIL=1
else
  CODE=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 45 "$URL/user/login")
  if [ "$CODE" = "200" ]; then
    _ok "$URL/user/login → 200"
  else
    _bad "$URL/user/login → $CODE"; FAIL=1
  fi

  # Confirms the theme is actually serving the linked component library,
  # not just that Drupal returned some HTML.
  CSS="$URL/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig/dist/css/style.css"
  CCODE=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 45 "$CSS")
  if [ "$CCODE" = "200" ]; then _ok "component-library CSS served"; else
    _bad "component-library CSS → $CCODE"; FAIL=1; fi
fi

_hdr "Result"
if [ "$FAIL" -eq 0 ]; then
  _ok "Environment is clean and verified."
  echo ""
  echo "  Site:  $URL"
  echo "  Login: lando drush uli --uri=\"$URL\""
  exit 0
fi
_bad "Verification failed — see above."
exit 1
