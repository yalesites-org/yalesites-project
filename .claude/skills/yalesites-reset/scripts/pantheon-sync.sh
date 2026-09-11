#!/bin/bash
# Pull the Pantheon dev database and/or files WITHOUT using `lando pull`.
#
# Why not `lando pull`: it rsyncs over SSH into Pantheon, and Lando
# frequently fails to link your SSH keys into its containers, producing
# "Permission denied (publickey)". Worse, when run without a TTY it first
# prompts "Choose a Pantheon account", gets EOF, and silently skips the
# pull while still exiting 0 -- so a build appears to succeed while
# actually running against your stale local database.
#
# terminus backup:get downloads over HTTPS with a machine token, so it
# sidesteps both problems.
#
# Usage: pantheon-sync.sh [--db] [--files] [--yes] [--env dev]
#        (no --db/--files flags means do both)

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
ys_cd_root
ys_require_root
ys_setup_env

SITE="yalesites-platform"
ENVIRONMENT="dev"
DO_DB=0
DO_FILES=0
ASSUME_YES=0

while [ $# -gt 0 ]; do
  case "$1" in
    --db)    DO_DB=1 ;;
    --files) DO_FILES=1 ;;
    --yes|-y) ASSUME_YES=1 ;;
    --env)   ENVIRONMENT="$2"; shift ;;
    --site)  SITE="$2"; shift ;;
    *) _bad "Unknown option: $1"; exit 1 ;;
  esac
  shift
done
if [ "$DO_DB" -eq 0 ] && [ "$DO_FILES" -eq 0 ]; then DO_DB=1; DO_FILES=1; fi

if ! terminus auth:whoami >/dev/null 2>&1; then
  _bad "terminus is not authenticated. Run: terminus auth:login --email=<you> --machine-token=<token>"
  exit 1
fi

if [ "$DO_DB" -eq 1 ] && [ "$ASSUME_YES" -eq 0 ]; then
  _warn "This DROPS your local database and replaces it with $SITE.$ENVIRONMENT."
  read -r -p "  Continue? [y/N] " reply
  case "$reply" in [yY]*) ;; *) echo "Aborted."; exit 1 ;; esac
fi

SCRATCH=$(mktemp -d)
trap 'rm -rf "$SCRATCH"' EXIT

# --- database -----------------------------------------------------------
if [ "$DO_DB" -eq 1 ]; then
  _hdr "Database"
  mkdir -p reference
  [ -f reference/backup.sql.gz ] && mv -f reference/backup.sql.gz reference/backup-prev.sql.gz

  echo "  Newest available backup:"
  terminus backup:list "$SITE.$ENVIRONMENT" --element=database 2>/dev/null | sed -n '4,6p' | sed 's/^/    /'

  terminus backup:get "$SITE.$ENVIRONMENT" --element=database --to=reference/backup.sql.gz
  _ok "downloaded ($(du -h reference/backup.sql.gz | cut -f1))"

  # lando db-import can only read files inside the app mount, so the
  # decompressed dump has to land in the project (reference/ is gitignored).
  gunzip -c reference/backup.sql.gz > reference/backup.sql
  lando drush sql:drop -y >/dev/null 2>&1 || true
  lando db-import reference/backup.sql
  rm -f reference/backup.sql

  TABLES=$(lando drush sql:query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();" 2>/dev/null | tr -dc '0-9')
  if [ "${TABLES:-0}" -lt 100 ]; then
    _bad "Only ${TABLES:-0} tables after import — the import did not work."
    exit 1
  fi
  _ok "imported ($TABLES tables)"
fi

# --- files --------------------------------------------------------------
if [ "$DO_FILES" -eq 1 ]; then
  _hdr "Files"
  terminus backup:get "$SITE.$ENVIRONMENT" --element=files --to="$SCRATCH/files.tar.gz"
  _ok "downloaded ($(du -h "$SCRATCH/files.tar.gz" | cut -f1))"

  mkdir -p "$SCRATCH/x"
  tar -xzf "$SCRATCH/files.tar.gz" -C "$SCRATCH/x"

  # Pantheon wraps everything in a single files_<env> directory.
  SRC=$(find "$SCRATCH/x" -maxdepth 1 -mindepth 1 -type d | head -1)
  if [ -z "$SRC" ]; then _bad "Unexpected archive layout"; exit 1; fi

  mkdir -p web/sites/default/files
  # No --delete: this mirrors what `lando pull --files` does, and it means
  # local test uploads survive a reset.
  rsync -a "$SRC/" web/sites/default/files/
  _ok "synced into web/sites/default/files ($(du -sh web/sites/default/files | cut -f1))"
fi

_hdr "Done"
_ok "Pantheon $ENVIRONMENT assets are in place."
