#!/bin/bash

# Check for compromised NPM packages in package-lock.json files
# Usage: ./check-compromised-packages.sh [package-lock.json file]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Compromised package scopes and specific packages
COMPROMISED_SCOPES=(
  "@ahmedhfarag"
  "@art-ws"
  "@crowdstrike"
  "@ctrl"
  "@hestjs"
  "@nativescript-community"
  "@nexe"
  "@nstudio"
  "@operato"
  "@rxap"
  "@teriyakibomb"
  "@teselagen"
  "@thangved"
  "@things-factory"
  "@tnf-dev"
  "@ui-ux-gang"
  "@yoobic"
)

COMPROMISED_PACKAGES=(
  "airchief"
  "airpilot"
  "angulartics2"
  "another-shai"
  "browser-webdriver-downloader"
  "capacitor-notificationhandler"
  "capacitor-plugin-healthapp"
  "capacitor-plugin-ihealth"
  "capacitor-plugin-vonage"
  "capacitorandroidpermissions"
  "config-cordova"
  "cordova-plugin-voxeet2"
  "cordova-voxeet"
  "create-hest-app"
  "db-evo"
  "devextreme-angular-rpk"
  "ember-browser-services"
  "ember-headless-form-yup"
  "ember-headless-form"
  "ember-headless-table"
  "ember-url-hash-polyfill"
  "ember-velcro"
  "encounter-playground"
  "eslint-config-crowdstrike-node"
  "eslint-config-crowdstrike"
  "eslint-config-teselagen"
  "globalize-rpk"
  "graphql-sequelize-teselagen"
  "html-to-base64-image"
  "json-rules-engine-simplified"
  "jumpgate"
  "koa2-swagger-ui"
  "mcfly-semantic-release"
  "mcp-knowledge-base"
  "mcp-knowledge-graph"
  "mobioffice-cli"
  "monorepo-next"
  "mstate-angular"
  "mstate-cli"
  "mstate-dev-react"
  "mstate-react"
  "ng2-file-upload"
  "ngx-bootstrap"
  "ngx-color"
  "ngx-toastr"
  "ngx-trend"
  "ngx-ws"
  "oradm-to-gql"
  "oradm-to-sqlz"
  "ove-auto-annotate"
  "pm2-gelf-json"
  "printjs-rpk"
  "react-complaint-image"
  "react-jsonschema-form-conditionals"
  "react-jsonschema-form-extras"
  "react-jsonschema-rxnt-extras"
  "remark-preset-lint-crowdstrike"
  "rxnt-authentication"
  "rxnt-healthchecks-nestjs"
  "rxnt-kue"
  "swc-plugin-component-annotate"
  "tbssnch"
  "teselagen-interval-tree"
  "tg-client-query-builder"
  "tg-redbird"
  "tg-seq-gen"
  "thangved-react-grid"
  "ts-gaussian"
  "ts-imports"
  "tvi-cli"
  "ve-bamreader"
  "ve-editor"
  "verror-extra"
  "voip-callkit"
  "wdio-web-reporter"
  "yargs-help-output"
  "yoo-styles"
)

check_file() {
  local file="$1"
  local found_compromised=0
  local temp_results=$(mktemp)

  echo -e "${YELLOW}Checking: $file${NC}"

  if [[ ! -f "$file" ]]; then
    echo -e "${RED}Error: File '$file' not found${NC}"
    return 1
  fi

  # Check for compromised scopes
  for scope in "${COMPROMISED_SCOPES[@]}"; do
    if grep -q "\"$scope/" "$file" 2>/dev/null; then
      echo -e "${RED}FOUND COMPROMISED SCOPE: $scope${NC}" | tee -a "$temp_results"
      found_compromised=1
    fi
  done

  # Check for compromised individual packages
  for package in "${COMPROMISED_PACKAGES[@]}"; do
    if grep -q "\"$package\":" "$file" 2>/dev/null; then
      echo -e "${RED}FOUND COMPROMISED PACKAGE: $package${NC}" | tee -a "$temp_results"
      found_compromised=1
    fi
  done

  if [[ $found_compromised -eq 0 ]]; then
    echo -e "${GREEN}No compromised packages found${NC}"
  else
    echo -e "${RED}SECURITY ALERT: Compromised packages detected!${NC}"
    cat "$temp_results"
  fi

  rm -f "$temp_results"
  return $found_compromised
}

# Main execution
main() {
  echo "NPM Supply Chain Attack - Compromised Package Checker"
  echo "===================================================="
  echo

  if [[ $# -eq 0 ]]; then
    # No arguments - check common locations
    files_checked=0
    total_compromised=0

    for file in "package-lock.json" "./*/package-lock.json" "./*/*/package-lock.json"; do
      if compgen -G "$file" > /dev/null; then
        for f in $file; do
          check_file "$f"
          files_checked=$((files_checked + 1))
          if [[ $? -ne 0 ]]; then
            total_compromised=$((total_compromised + 1))
          fi
          echo
        done
      fi
    done

    echo "===================================================="
    echo "Summary: Checked $files_checked files"
    if [[ $total_compromised -eq 0 ]]; then
      echo -e "${GREEN}All files clean - no compromised packages detected${NC}"
    else
      echo -e "${RED}Found compromised packages in $total_compromised file(s)${NC}"
      exit 1
    fi
  else
    # Check specific file
    check_file "$1"
    exit $?
  fi
}

main "$@"
