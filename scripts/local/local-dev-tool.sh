#!/bin/bash

# Local Docker development tool selection.
#
# The local scripts default to Lando so existing developer workflows continue
# to work. To use DDEV for the current shell session instead, run:
#
#   export YALESITES_LOCAL_DOCKER_TOOL=ddev
#
# You can also change the default below from "lando" to "ddev" for your local
# checkout if you prefer DDEV for all local scripts.
YALESITES_LOCAL_DOCKER_TOOL="${YALESITES_LOCAL_DOCKER_TOOL:-ddev}"

function ys_local_validate_tool() {
  case "$YALESITES_LOCAL_DOCKER_TOOL" in
    lando|ddev)
      ;;
    *)
      echo "Unsupported YALESITES_LOCAL_DOCKER_TOOL: $YALESITES_LOCAL_DOCKER_TOOL"
      echo "Supported values are: lando, ddev"
      exit 1
      ;;
  esac

  if ! command -v "$YALESITES_LOCAL_DOCKER_TOOL" > /dev/null 2>&1; then
    echo "$YALESITES_LOCAL_DOCKER_TOOL is not installed or is not available in PATH."
    exit 1
  fi
}

function ys_local_start() {
  ys_local_validate_tool
  "$YALESITES_LOCAL_DOCKER_TOOL" start
}

function ys_local_drush() {
  ys_local_validate_tool
  "$YALESITES_LOCAL_DOCKER_TOOL" drush "$@"
}

function ys_local_composer() {
  ys_local_validate_tool
  "$YALESITES_LOCAL_DOCKER_TOOL" composer "$@"
}

function ys_local_exec() {
  ys_local_validate_tool

  case "$YALESITES_LOCAL_DOCKER_TOOL" in
    lando)
      lando ssh -c "$*"
      ;;
    ddev)
      ddev exec "$@"
      ;;
  esac
}

function ys_local_pull_db() {
  ys_local_validate_tool

  case "$YALESITES_LOCAL_DOCKER_TOOL" in
    lando)
      lando pull --code=none --database=dev --files=none
      ;;
    ddev)
      ddev pull pantheon --skip-files
      ;;
  esac
}

function ys_local_pull_files() {
  ys_local_validate_tool

  case "$YALESITES_LOCAL_DOCKER_TOOL" in
    lando)
      lando pull --code=none --database=none --files=dev
      ;;
    ddev)
      ddev pull pantheon --skip-db
      ;;
  esac
}
