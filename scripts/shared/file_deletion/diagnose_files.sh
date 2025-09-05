#!/bin/bash

# ==============================================================================
# Drupal File Usage Diagnoser with DOM Verification (v11)
#
# Diagnoses Drupal file usage issues with DOM verification and provides 
# automated cleanup commands. Handles orphaned files, ghost references, 
# phantom references, and generates environment-specific deletion commands 
# for both Lando and Terminus environments.
#
# DESCRIPTION:
# This script analyzes a list of files to determine their usage status in a
# Drupal database AND verifies actual usage in rendered DOM content. It 
# identifies various scenarios including:
# - Files with no database usage records (orphaned files)
# - Files with stale database records (ghost references)
# - Files in database but not in rendered DOM (phantom references)
# - Files actively used in published content
# - Files in unpublished/deleted content
#
# For each file, it provides specific recommendations and generates automated
# commands for safe cleanup operations.
#
# USAGE:
#   ./diagnose_files.sh [OPTIONS] <files_to_check.txt>
#
# OPTIONS:
#   --mode <lando|terminus>     Force execution mode (auto-detected if not specified)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --help                     Show this help message
#
# INPUT FILE FORMAT:
# The input file should contain one filename per line:
#   report.pdf
#   document.xlsx
#   image.jpg
#
# OUTPUT SCENARIOS:
#
# 1. No Usage Record Found (Orphaned Files):
#    🔎 Checking file: orphaned-file.pdf
#      ✅ No usage record found.
#      DIAGNOSIS: Orphaned File. No database references exist.
#      RECOMMENDATION: Safe to delete directly from filesystem.
#      COMMAND: rm "web/sites/default/files/2025-02/orphaned-file.pdf"
#
# 2. Ghost Reference (Stale Database Records):
#    🔎 Checking file: ghost-file.pdf
#      - Found usage by 'block_content' (id: 1234)
#      DIAGNOSIS: Ghost Reference. Block_content 1234 exists but file was removed from content.
#      RECOMMENDATION: Safe to delete stale file_usage record.
#      COMMAND: lando mysql -e "BEGIN; DELETE FROM pantheon.file_usage WHERE fid = 5678 AND type = 'block_content' AND id = 1234; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = 5678); UPDATE pantheon.file_managed SET status = 0 WHERE fid = 5678 AND @remaining = 0; COMMIT;"
#
# 3. Active Content (Manual Action Required):
#    🔎 Checking file: active-file.pdf
#      - Found usage by 'block_content' (id: 5678)
#      DIAGNOSIS: Active Block. Block_content 5678 is actively used.
#      RECOMMENDATION: Remove file through UI to prevent breaking content.
#      ACTION: File found in block content. Go to /node/123/layout to remove block from Layout Builder
#
# 4. Multiple Files Same Name (Duplicate Handling):
#    🔎 Checking file: duplicate.pdf
#      ✅ No usage record found.
#      DIAGNOSIS: Orphaned File. No database references exist.
#      RECOMMENDATION: Safe to delete directly from filesystem.
#      WARNING: Multiple files found with name 'duplicate.pdf'. Listing all:
#        - FID 123: 2024-01/duplicate.pdf
#        - FID 456: 2025-02/duplicate.pdf
#      COMMAND: rm "web/sites/default/files/2024-01/duplicate.pdf" # FID 123
#      COMMAND: rm "web/sites/default/files/2025-02/duplicate.pdf" # FID 456
#
# COMMAND TYPES GENERATED:
# - Targeted Drupal-native transactional SQL cleanup commands (for ghost references and stale records)
#   Uses BEGIN/COMMIT transactions with smart verification to safely remove specific file_usage records
#   Only marks files for deletion (status = 0) when removing the LAST reference to prevent overreach
# - File deletion commands (for orphaned files with no database references)
# - UI action instructions (for active content requiring manual intervention)
#
# ENVIRONMENT SUPPORT:
# - Lando (local): Uses 'lando mysql' for database queries and 'rm' for file deletion
# - Terminus (remote): Uses 'terminus drush site.env sql:cli' and 'terminus remote:drush -- eval' for operations
#
# SAFETY FEATURES:
# - Only processes files listed in the input file
# - Verifies actual file content presence before recommending deletion
# - Uses exact file paths from database to prevent wrong file deletion
# - Distinguishes between legitimate usage and ghost references
# - Provides specific UI paths for manual interventions
#
# LAYOUT BUILDER DETECTION:
# The script includes sophisticated Layout Builder detection that properly
# identifies when block_content entities are used in Drupal's Layout Builder
# by parsing PHP serialized data in the layout_builder__layout_section field.
#
# PREREQUISITES:
# - Lando environment running (for lando mode) OR Terminus CLI configured (for terminus mode)
# - Database connectivity to Pantheon Drupal database
# - Input file with list of files to analyze
# - Proper permissions to access file_managed and file_usage tables
#
# WORKFLOW:
# 1. Run this script to generate diagnostic report with commands
# 2. Review the output and identify auto-deletable vs manual cases
# 3. Use cleanup_files.sh to execute the generated commands automatically
# 4. Handle remaining manual cases through Drupal UI as indicated
#
# ==============================================================================

# --- CONFIGURATION ---
MODE=""
SITE_NAME=""
ENV=""
FILES_TO_CHECK=""
PARAGRAPH_MEDIA_FIELD_NAME="field_media"
NODE_MEDIA_FIELD_NAME="field_media"
# DOM Verification Configuration
ENABLE_DOM_VERIFICATION=true
BASE_URL=""  # Auto-detect from environment. Override with specific URL if needed.
USER_AGENT="Mozilla/5.0 (Drupal File Diagnosis) DOM Scanner/v11"
CURL_TIMEOUT=15

# Domain Detection Methods:
# Lando: 1) .lando.local.yml DRUSH_OPTIONS_URI, 2) Constructed from name, 3) lando info JSON
# Terminus: 1) terminus domain:list API, 2) terminus env:info, 3) Standard pantheonsite.io pattern
# --- END CONFIGURATION ---

# --- CORE UTILITY FUNCTIONS ---

# Detect if filename input is a path or basename
detect_file_format() {
    local input_file="$1"
    if [[ "$input_file" == *"/"* ]]; then
        echo "path"
    else
        echo "basename"
    fi
}

# Build environment-specific SQL command
build_sql_command() {
  local query="$1"
  if [ "$MODE" == "lando" ]; then
    echo "lando mysql -e \"${query}\""
  elif [ "$MODE" == "terminus" ]; then
    echo "echo \"${query}\" | terminus drush \"${SITE_NAME}.${ENV}\" sql:cli"
  else
    echo "ERROR: Invalid MODE set" >&2
    return 1
  fi
}

# Build environment-specific file operation command  
build_file_command() {
  local operation="$1"
  local file_path="$2"
  
  case "$operation" in
    "delete")
      if [ "$MODE" == "lando" ]; then
        echo "find web/sites/default/files -name '${file_path}' -type f -print0 | while IFS= read -r -d '' file; do if rm \"\$file\" 2>/dev/null; then echo \"Successfully deleted: \$(basename \"\$file\")\"; else echo \"Error deleting: \$(basename \"\$file\")\"; fi; done"
      elif [ "$MODE" == "terminus" ]; then
        echo "terminus remote:drush ${SITE_NAME}.${ENV} -- eval \"\\\$files = glob(DRUPAL_ROOT . '/sites/default/files/**/${file_path}'); \\\$deleted = 0; foreach(\\\$files as \\\$file) { if(file_exists(\\\$file)) { if(is_writable(\\\$file)) { if(unlink(\\\$file)) { echo 'Successfully deleted: ' . basename(\\\$file); \\\$deleted++; } else { echo 'Error deleting: ' . basename(\\\$file) . ' - ' . (error_get_last()['message'] ?? 'Unknown error'); } } else { echo 'Permission denied: ' . basename(\\\$file); } } else { echo 'File not found: ' . basename(\\\$file); } } if(\\\$deleted === 0 && empty(\\\$files)) { echo 'No files found matching: ${file_path}'; }\""
      else
        echo "ERROR: Invalid MODE set" >&2
        return 1
      fi
      ;;
    *)
      echo "ERROR: Unsupported file operation: $operation" >&2
      return 1
      ;;
  esac
}

# Build environment-specific Drush command
build_drush_command() {
  local drush_args="$1"
  if [ "$MODE" == "lando" ]; then
    echo "lando drush ${drush_args}"
  elif [ "$MODE" == "terminus" ]; then
    echo "terminus remote:drush ${SITE_NAME}.${ENV} -- ${drush_args}"
  else
    echo "ERROR: Invalid MODE set" >&2
    return 1
  fi
}

# --- END UTILITY FUNCTIONS ---

# --- SCRIPT LOGIC ---

if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m';
else
    RED=""; GREEN=""; YELLOW=""; BLUE=""; NC="";
fi

run_sql() {
    local query="$1"
    if [ "$MODE" == "lando" ]; then 
        lando mysql -sN -e "$query" < /dev/null
    elif [ "$MODE" == "terminus" ]; then 
        # Use a temporary file to avoid stdin conflicts with command substitution
        local temp_query=$(mktemp)
        echo "$query" > "$temp_query"
        terminus drush "${SITE_NAME}.${ENV}" sql:cli < "$temp_query"
        rm -f "$temp_query"
    else 
        echo -e "${RED}Error: Invalid MODE set.${NC}"; exit 1
    fi
}

# Auto-detect base URL for DOM verification
detect_base_url() {
    if [ -n "$BASE_URL" ]; then
        echo "$BASE_URL"
        return 0
    fi
    
    if [ "$MODE" == "lando" ] && command -v lando >/dev/null 2>&1; then
        echo "    ${YELLOW}Detecting Lando domain...${NC}" >&2
        
        # Method 1: Check .lando.local.yml for DRUSH_OPTIONS_URI
        if [ -f ".lando.local.yml" ]; then
            # More robust regex to handle various quote styles and spacing
            local drush_uri=$(grep -E "^\s*DRUSH_OPTIONS_URI:" .lando.local.yml | sed 's/.*DRUSH_OPTIONS_URI:\s*["\x27]*\([^"\x27 ]*\)["\x27]*.*/\1/')
            if [ -n "$drush_uri" ] && [[ "$drush_uri" =~ ^https?:// ]]; then
                echo "    ${GREEN}Found domain in .lando.local.yml: ${drush_uri}${NC}" >&2
                echo "${drush_uri%/}"  # Remove trailing slash
                return 0
            fi
        fi
        
        # Method 2: Check .lando.yml for name and construct URL
        local lando_name=""
        if [ -f ".lando.yml" ]; then
            lando_name=$(grep -E "^\s*name:" .lando.yml | sed 's/.*name:\s*\([^#]*\).*/\1/' | tr -d ' "')
        elif [ -f ".lando.local.yml" ]; then
            lando_name=$(grep -E "^\s*name:" .lando.local.yml | sed 's/.*name:\s*\([^#]*\).*/\1/' | tr -d ' "')
        fi
        
        if [ -n "$lando_name" ]; then
            local constructed_url="https://${lando_name}.lndo.site"
            echo "    ${YELLOW}Trying constructed URL from lando name: ${constructed_url}${NC}" >&2
            # Test if the URL is accessible
            if curl -s --max-time 5 --head "$constructed_url" >/dev/null 2>&1; then
                echo "    ${GREEN}✓ Constructed URL is accessible${NC}" >&2
                echo "$constructed_url"
                return 0
            else
                echo "    ${YELLOW}✗ Constructed URL not accessible${NC}" >&2
            fi
        fi
        
        # Method 3: Get URL from lando info JSON
        echo "    ${YELLOW}Checking lando info...${NC}" >&2
        local lando_info=$(lando info --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$lando_info" ]; then
            # Look for edge service URLs first (main site URLs)
            local edge_url=$(echo "$lando_info" | grep -o '"http[^"]*\.lndo\.site[^"]*"' | head -1 | tr -d '"')
            if [ -n "$edge_url" ]; then
                echo "    ${GREEN}Found URL in lando info: ${edge_url}${NC}" >&2
                echo "$edge_url"
                return 0
            fi
            
            # Fallback: look for any HTTPS URL
            local any_https_url=$(echo "$lando_info" | grep -o '"https://[^"]*"' | head -1 | tr -d '"')
            if [ -n "$any_https_url" ]; then
                echo "    ${GREEN}Found HTTPS URL in lando info: ${any_https_url}${NC}" >&2
                echo "$any_https_url"
                return 0
            fi
        fi
        
        echo "    ${RED}Could not detect Lando URL${NC}" >&2
    fi
    
    if [ "$MODE" == "terminus" ] && [ -n "$SITE_NAME" ] && [ -n "$ENV" ] && command -v terminus >/dev/null 2>&1; then
        echo "    ${YELLOW}Detecting Pantheon domain via Terminus...${NC}" >&2
        
        # Method 1: Get actual domain from Pantheon API
        local domain_info=$(terminus domain:list "${SITE_NAME}.${ENV}" --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$domain_info" ]; then
            # Look for primary domain or pantheonsite.io domain
            local primary_domain=$(echo "$domain_info" | grep -o '"domain":"[^"]*"' | grep -E '(pantheonsite\.io|primary.*true)' | head -1 | cut -d'"' -f4)
            if [ -n "$primary_domain" ]; then
                local pantheon_url="https://${primary_domain}"
                echo "    ${GREEN}Found Pantheon domain: ${pantheon_url}${NC}" >&2
                echo "$pantheon_url"
                return 0
            fi
            
            # Fallback: get any domain from the list
            local any_domain=$(echo "$domain_info" | grep -o '"domain":"[^"]*"' | head -1 | cut -d'"' -f4)
            if [ -n "$any_domain" ]; then
                local pantheon_url="https://${any_domain}"
                echo "    ${GREEN}Found Pantheon domain (fallback): ${pantheon_url}${NC}" >&2
                echo "$pantheon_url"
                return 0
            fi
        fi
        
        # Method 2: Try getting environment info for domains
        local env_info=$(terminus env:info "${SITE_NAME}.${ENV}" --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$env_info" ]; then
            local env_domain=$(echo "$env_info" | grep -o '"domain":"[^"]*"' | head -1 | cut -d'"' -f4)
            if [ -n "$env_domain" ]; then
                local pantheon_url="https://${env_domain}"
                echo "    ${GREEN}Found Pantheon env domain: ${pantheon_url}${NC}" >&2
                echo "$pantheon_url"
                return 0
            fi
        fi
        
        # Method 3: Fallback to standard Pantheon URL pattern
        local standard_url="https://${ENV}-${SITE_NAME}.pantheonsite.io"
        echo "    ${YELLOW}Trying standard Pantheon URL: ${standard_url}${NC}" >&2
        # Test if the URL is accessible
        if curl -s --max-time 10 --head "$standard_url" >/dev/null 2>&1; then
            echo "    ${GREEN}✓ Standard Pantheon URL is accessible${NC}" >&2
            echo "$standard_url"
            return 0
        else
            echo "    ${YELLOW}✗ Standard Pantheon URL not accessible${NC}" >&2
        fi
        
        echo "    ${RED}Could not detect Pantheon URL${NC}" >&2
    fi
    
    return 1
}

# Check if file is actually referenced in DOM content
check_file_in_dom() {
    local filename="$1"
    local node_id="$2"
    local base_url="$3"
    
    if [ "$ENABLE_DOM_VERIFICATION" != "true" ]; then
        return 2  # DOM verification disabled
    fi
    
    if [ -z "$base_url" ]; then
        return 1  # No base URL available
    fi
    
    local full_url="${base_url}/node/${node_id}"
    
    # Fetch the page content
    local page_content=$(curl -s --max-time "$CURL_TIMEOUT" \
        --user-agent "$USER_AGENT" \
        --location \
        --fail \
        "$full_url" 2>/dev/null)
    
    local curl_exit_code=$?
    
    if [ $curl_exit_code -ne 0 ] || [ -z "$page_content" ]; then
        return 1  # Error fetching content
    fi
    
    # Create multiple search patterns for the filename
    local base_filename=$(basename "$filename")
    local filename_no_ext="${base_filename%.*}"
    
    # Patterns to search for (case-insensitive)
    local search_patterns=(
        "$base_filename"                           # exact filename
        "$(echo "$base_filename" | tr ' ' '_')"    # spaces to underscores
        "$(echo "$base_filename" | tr ' ' '-')"    # spaces to hyphens
        "$(echo "$base_filename" | sed 's/[^a-zA-Z0-9.]/-/g')"  # special chars to hyphens
        "$filename_no_ext"                         # filename without extension
    )
    
    # URL-encode common characters
    local url_encoded_filename=$(echo "$base_filename" | sed 's/ /%20/g' | sed 's/&/%26/g')
    search_patterns+=("$url_encoded_filename")
    
    # Check each pattern in the page content (case-insensitive)
    for pattern in "${search_patterns[@]}"; do
        if echo "$page_content" | grep -i -q "$pattern"; then
            return 0  # Found in DOM
        fi
    done
    
    return 3  # Not found in DOM
}

# Verify if a file actually exists in block_content fields
verify_file_in_block_content() {
    local block_id="$1"
    local filename="$2"
    
    # Escape filename for SQL LIKE pattern
    local escaped_filename=$(echo "$filename" | sed 's/[_%]/\\&/g')
    
    # Check common text/WYSIWYG fields for filename
    local text_fields=("body" "field_text" "field_callout_text" "field_subheading")
    
    for field in "${text_fields[@]}"; do
        local table="block_content__${field}"
        local column="${field}_value"
        
        # Check if table exists and has the column
        local table_check_query="SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '${table}' AND table_schema = 'pantheon';"
        local table_exists=$(run_sql "$table_check_query")
        
        if [ "$table_exists" != "" ] && [ "$table_exists" -gt 0 ]; then
            # Check for filename in this field
            local content_check_query="SELECT COUNT(*) FROM pantheon.${table} WHERE entity_id = ${block_id} AND ${column} LIKE '%${escaped_filename}%';"
            local content_found=$(run_sql "$content_check_query")
            
            if [ "$content_found" != "" ] && [ "$content_found" -gt 0 ]; then
                echo "found_in_${field}"
                return 0
            fi
        fi
    done
    
    # Check file/media reference fields
    local ref_fields=("field_media" "field_file" "field_document")
    
    for field in "${ref_fields[@]}"; do
        local table="block_content__${field}"
        local column="${field}_target_id"
        
        # Check if table exists
        local table_check_query="SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '${table}' AND table_schema = 'pantheon';"
        local table_exists=$(run_sql "$table_check_query")
        
        if [ "$table_exists" != "" ] && [ "$table_exists" -gt 0 ]; then
            # Check if any referenced media/file entities use our file
            local ref_check_query="SELECT COUNT(*) FROM pantheon.${table} bct JOIN pantheon.file_managed fm ON (bct.${column} IN (SELECT fid FROM pantheon.file_usage WHERE fid = fm.fid)) WHERE bct.entity_id = ${block_id} AND fm.filename LIKE '%${escaped_filename}%';"
            local ref_found=$(run_sql "$ref_check_query")
            
            if [ "$ref_found" != "" ] && [ "$ref_found" -gt 0 ]; then
                echo "found_in_${field}"
                return 0
            fi
        fi
    done
    
    # No content found
    echo "not_found"
    return 1
}

# --- CLI FUNCTIONS ---

show_usage() {
    echo "Usage: $(basename "$0") [OPTIONS] <files_to_check.txt>"
    echo ""
    echo "OPTIONS:"
    echo "  --mode <lando|terminus>     Force execution mode (auto-detected if not specified)"
    echo "  --site <sitename>          Pantheon site name (required for terminus mode)"
    echo "  --env <environment>        Pantheon environment (required for terminus mode)"
    echo "  --help                     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $(basename "$0") files_to_check.txt                                # Auto-detect mode"
    echo "  $(basename "$0") --mode lando files_to_check.txt                  # Force lando mode"
    echo "  $(basename "$0") --mode terminus --site mysite --env dev files.txt # Terminus mode"
}

# Auto-detect mode from environment
detect_environment_mode() {
    if [ -n "$MODE" ]; then
        return 0
    fi
    
    # Try to detect Lando environment first (preferred default)
    if command -v lando >/dev/null 2>&1; then
        if [ -f ".lando.yml" ] || [ -f ".lando.local.yml" ]; then
            if lando info >/dev/null 2>&1; then
                MODE="lando"
                echo "Auto-detected mode: lando"
                return 0
            fi
        fi
    fi
    
    # Try to detect Terminus environment
    if command -v terminus >/dev/null 2>&1; then
        MODE="terminus"
        echo "Auto-detected mode: terminus (requires --site and --env)"
        return 0
    fi
    
    # Default to lando as a sane default
    MODE="lando"
    echo "No environment auto-detected. Defaulting to mode: lando"
    return 0
}

# Validate configuration
validate_config() {
    if [ ! -f "$FILES_TO_CHECK" ]; then
        echo -e "${RED}Error: Input file '$FILES_TO_CHECK' not found.${NC}"
        exit 1
    fi
    
    if [ -z "$MODE" ]; then
        echo -e "${RED}Error: Mode not specified and could not be auto-detected${NC}"
        exit 1
    fi
    
    if [ "$MODE" == "terminus" ]; then
        if [ -z "$SITE_NAME" ] || [ -z "$ENV" ]; then
            echo -e "${RED}Error: Terminus mode requires --site and --env parameters${NC}"
            echo "Example: $(basename "$0") --mode terminus --site mysite --env dev files.txt"
            exit 1
        fi
    fi
}

# --- MAIN SCRIPT ---

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --mode)
            MODE="$2"
            shift 2
            ;;
        --site)
            SITE_NAME="$2"
            shift 2
            ;;
        --env)
            ENV="$2"
            shift 2
            ;;
        --help)
            show_usage
            exit 0
            ;;
        -*)
            echo "Unknown option: $1"
            show_usage
            exit 1
            ;;
        *)
            if [ -z "$FILES_TO_CHECK" ]; then
                FILES_TO_CHECK="$1"
            else
                echo "Too many arguments"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Set default files to check if not specified
if [ -z "$FILES_TO_CHECK" ]; then
    FILES_TO_CHECK="files_to_check.txt"
fi

# Detect environment mode if not specified
detect_environment_mode

# Validate configuration
validate_config

echo -e "${BLUE}--- Starting File Usage Diagnostic Scan ---${NC}"
echo "Mode: $MODE"; [ "$MODE" == "terminus" ] && echo "Site: $SITE_NAME.$ENV"
echo "Input File: $FILES_TO_CHECK"

# Initialize base URL detection (always needed for action messages)
DETECTED_BASE_URL=$(detect_base_url)
if [ -n "$DETECTED_BASE_URL" ]; then
    BASE_URL="$DETECTED_BASE_URL"
else
    BASE_URL=""
fi

# Initialize DOM verification
if [ "$ENABLE_DOM_VERIFICATION" == "true" ]; then
    if [ -n "$BASE_URL" ]; then
        echo "DOM Verification: ENABLED (${BASE_URL})"
    else
        echo "DOM Verification: DISABLED (could not detect base URL)"
        ENABLE_DOM_VERIFICATION=false
    fi
else
    echo "DOM Verification: DISABLED"
fi

echo "------------------------------------------------"

while IFS= read -r filename <&3 || [ -n "$filename" ]; do
    [ -z "$filename" ] && continue
    echo -e "\n🔎 ${YELLOW}Checking file:${NC} ${filename}"
    
    # Detect file format and prepare query variables
    file_format=$(detect_file_format "$filename")
    if [ "$file_format" == "path" ]; then
        # Extract basename for database filename matching
        file_basename=$(basename "$filename")
        # Construct exact URI for precise path matching
        file_uri="public://${filename}"
        echo -e "  ${CYAN}Input format: Path - targeting specific file at ${file_uri}${NC}"
    else
        # Basename only - use as-is for filename matching
        file_basename="$filename"
        file_uri=""
        echo -e "  ${CYAN}Input format: Basename - searching across all directories${NC}"
    fi
    
    # Step 1: Check if file exists in file_managed table with precise path matching
    if [ "$file_format" == "path" ]; then
        # Path input: Match both filename AND exact URI to prevent cross-directory conflicts
        file_managed_query="SELECT fid, REPLACE(uri, 'public://', '') as file_path, status FROM pantheon.file_managed WHERE filename = '${file_basename}' AND uri = '${file_uri}';"
    else
        # Basename input: Match filename across all paths (existing behavior)
        file_managed_query="SELECT fid, REPLACE(uri, 'public://', '') as file_path, status FROM pantheon.file_managed WHERE filename = '${file_basename}';"
    fi
    file_managed_info=$(run_sql "$file_managed_query")
    
    if [ -z "$file_managed_info" ]; then
        # File not in file_managed table - check if it physically exists
        echo -e "  ${GREEN}✅ No database record found.${NC}"
        
        # Check if the physical file actually exists using appropriate path format
        file_exists=false
        if [ "$MODE" == "terminus" ]; then
            # Terminus mode - check remote filesystem
            if [ "$file_format" == "path" ]; then
                # For path input, check exact path
                found_files=$(terminus remote:drush ${SITE_NAME}.${ENV} -- eval "echo file_exists(DRUPAL_ROOT . '/sites/default/files/${filename}') ? '${filename}' : '';")
            else
                # For basename, search in subdirectories
                found_files=$(terminus remote:drush ${SITE_NAME}.${ENV} -- eval "echo shell_exec('find sites/default/files -name \"${filename}\" -type f 2>/dev/null');")
            fi
            if [ -n "$found_files" ]; then
                file_exists=true
            fi
        else
            # Lando mode (default)
            if [ "$file_format" == "path" ]; then
                # For path input, check exact path
                if [ -f "web/sites/default/files/$filename" ]; then
                    file_exists=true
                fi
            else
                # For basename, check both direct and subdirectory matches
                if [ -f "web/sites/default/files/$filename" ]; then
                    file_exists=true
                else
                    # Check for files with the same name in subdirectories
                    found_files=$(find web/sites/default/files -name "$filename" -type f 2>/dev/null)
                    if [ -n "$found_files" ]; then
                        file_exists=true
                    fi
                fi
            fi
        fi
        
        if [ "$file_exists" == "true" ]; then
            echo -e "  ${GREEN}DIAGNOSIS: Truly Orphaned File. Not in file_managed table but file exists on filesystem.${NC}"
            echo -e "  ${GREEN}RECOMMENDATION: Safe to delete directly from filesystem.${NC}"
            
            # Generate environment-specific direct deletion command
            if [ "$MODE" == "lando" ]; then
                if [ "$file_format" == "path" ]; then
                    # For path input, delete specific file
                    echo -e "  ${BLUE}COMMAND: if rm \"web/sites/default/files/${filename}\" 2>/dev/null; then echo \"Successfully deleted: ${filename}\"; else echo \"Error deleting: ${filename}\"; fi # Direct deletion for truly orphaned file${NC}"
                else
                    # For basename, find and delete all matches
                    echo -e "  ${BLUE}COMMAND: find web/sites/default/files -name '${filename}' -type f -print0 | while IFS= read -r -d '' file; do if rm \"\$file\" 2>/dev/null; then echo \"Successfully deleted: \$(basename \"\$file\")\"; else echo \"Error deleting: \$(basename \"\$file\")\"; fi; done; if [ ! -f \"web/sites/default/files/${filename}\" ] && ! find web/sites/default/files -name '${filename}' -type f -print -quit | grep -q .; then echo \"No files found matching: ${filename}\"; fi # Direct deletion for truly orphaned file${NC}"
                fi
            else
                if [ "$file_format" == "path" ]; then
                    # For path input, delete specific file
                    echo -e "  ${BLUE}COMMAND: terminus remote:drush ${SITE_NAME}.${ENV} -- eval \"if(file_exists(DRUPAL_ROOT . '/sites/default/files/${filename}')) { if(unlink(DRUPAL_ROOT . '/sites/default/files/${filename}')) { echo 'Successfully deleted: ${filename}'; } else { echo 'Error deleting: ${filename}'; } } else { echo 'File not found: ${filename}'; }\" # Direct deletion for truly orphaned file${NC}"
                else
                    # For basename, find and delete all matches
                    echo -e "  ${BLUE}COMMAND: terminus remote:drush ${SITE_NAME}.${ENV} -- eval \"\\\$files = glob(DRUPAL_ROOT . '/sites/default/files/**/${filename}'); \\\$deleted = 0; foreach(\\\$files as \\\$file) { if(file_exists(\\\$file)) { if(is_writable(\\\$file)) { if(unlink(\\\$file)) { echo 'Successfully deleted: ' . basename(\\\$file); \\\$deleted++; } else { echo 'Error deleting: ' . basename(\\\$file) . ' - ' . (error_get_last()['message'] ?? 'Unknown error'); } } else { echo 'Permission denied: ' . basename(\\\$file); } } else { echo 'File not found: ' . basename(\\\$file); } } if(\\\$deleted === 0 && empty(\\\$files)) { echo 'No files found matching: ${filename}'; }\" # Direct deletion for truly orphaned file${NC}"
                fi
            fi
        else
            echo -e "  ${CYAN}ℹ️ File not found on filesystem.${NC}"
            echo -e "  ${CYAN}DIAGNOSIS: Already Cleaned. File not in database and not on filesystem.${NC}"
            echo -e "  ${CYAN}RECOMMENDATION: No action needed - file already removed.${NC}"
            echo -e "  ${CYAN}ACTION: File has been previously cleaned up or never existed.${NC}"
        fi
        continue
    fi
    
    # File exists in file_managed - now check for usage records using same path-specific logic
    if [ "$file_format" == "path" ]; then
        # Path input: Match usage records for files with specific path
        file_usage_query="SELECT fu.module, fu.type, fu.id, fu.fid FROM pantheon.file_usage fu WHERE fu.fid IN (SELECT fid FROM pantheon.file_managed WHERE filename = '${file_basename}' AND uri = '${file_uri}');"
    else
        # Basename input: Match usage records for all files with this basename
        file_usage_query="SELECT fu.module, fu.type, fu.id, fu.fid FROM pantheon.file_usage fu WHERE fu.fid IN (SELECT fid FROM pantheon.file_managed WHERE filename = '${file_basename}');"
    fi
    usage_info=$(run_sql "$file_usage_query")

    if [ -z "$usage_info" ]; then 
        # File in file_managed but no usage records - unused but managed
        echo -e "  ${GREEN}✅ No usage records found.${NC}"
        echo -e "  ${GREEN}DIAGNOSIS: Unused File. In file_managed but no usage records.${NC}"
        echo -e "  ${GREEN}RECOMMENDATION: Set status = 0, let Drupal cron handle deletion.${NC}"
        
        # Generate commands to set status = 0 for unused files
        echo "$file_managed_info" | while IFS=$'\t' read -r fid file_path status; do
            echo -e "    ${YELLOW}- FID ${fid}: ${file_path} (current status: ${status})${NC}"
            # Generate environment-specific database command to set status = 0
            if [ "$MODE" == "lando" ]; then
                echo -e "    ${BLUE}COMMAND: lando mysql -e \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid}\" # Set unused file to temporary${NC}"
            else
                echo -e "    ${BLUE}COMMAND: echo \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid}\" | terminus drush \"${SITE_NAME}.${ENV}\" sql:cli # Set unused file to temporary${NC}"
            fi
        done
        continue
    fi

    # Process usage records and collect results
    temp_file="/tmp/usage_analysis_$$"
    reference_count=$(echo "$usage_info" | wc -l)
    reference_number=0
    
    if [ "$reference_count" -gt 1 ]; then
        echo -e "  ${CYAN}📊 Multiple references found (${reference_count} total)${NC}"
    fi
    
    echo "$usage_info" | while IFS=$'\t' read -r module type id fid; do
        ((reference_number++))
        if [ "$reference_count" -gt 1 ]; then
            echo "  ${CYAN}[${reference_number}/${reference_count}]${NC} Found usage by '${type}' (id: ${id})"
        else
            echo "  - Found usage by '${type}' (id: ${id})"
        fi
        current_type=$type; current_id=$id; ultimate_parent_type=""; ultimate_parent_id=""; diagnosis_context=""
        
        for i in {1..10}; do
            if [ "$current_type" == "node" ]; then ultimate_parent_type="node"; ultimate_parent_id=$current_id; break;
            elif [ "$current_type" == "block_content" ]; then ultimate_parent_type="block_content"; ultimate_parent_id=$current_id; break;
            elif [ "$current_type" == "media" ]; then
                echo "    -> Media detected. Finding its parent entity..."
                media_parent_query=""
                if [ -n "$PARAGRAPH_MEDIA_FIELD_NAME" ]; then
                    media_parent_query+="(SELECT CONCAT('paragraph:', p.id) as parent FROM pantheon.paragraphs_item_field_data p JOIN pantheon.paragraph__${PARAGRAPH_MEDIA_FIELD_NAME} pfma ON p.id = pfma.entity_id WHERE pfma.${PARAGRAPH_MEDIA_FIELD_NAME}_target_id = ${current_id} LIMIT 1)"
                fi
                if [ -n "$NODE_MEDIA_FIELD_NAME" ]; then
                    [ -n "$media_parent_query" ] && media_parent_query+=" UNION "
                    media_parent_query+="(SELECT CONCAT('node:', n.nid) as parent FROM pantheon.node_field_data n JOIN pantheon.node__${NODE_MEDIA_FIELD_NAME} nfma ON n.nid = nfma.entity_id WHERE nfma.${NODE_MEDIA_FIELD_NAME}_target_id = ${current_id} LIMIT 1)"
                fi
                
                if [ -z "$media_parent_query" ]; then break; fi
                media_parent_query+=" LIMIT 1;"
                
                media_parent=$(run_sql "$media_parent_query")
                if [ -n "$media_parent" ]; then
                    current_type=$(echo $media_parent | cut -d':' -f1); current_id=$(echo $media_parent | cut -d':' -f2)
                    diagnosis_context+=" (via Media ${id})"; echo "    -> Parent is '${current_type}' (id: ${current_id})"
                    continue
                else break; fi
            elif [ "$current_type" == "paragraph" ]; then
                paragraph_parent_query="SELECT parent_type, parent_id FROM pantheon.paragraphs_item_field_data WHERE id = ${current_id};"
                paragraph_parent=$(run_sql "$paragraph_parent_query")
                if [ -n "$paragraph_parent" ]; then
                    parent_type=$(echo "$paragraph_parent" | cut -f1); parent_id=$(echo "$paragraph_parent" | cut -f2)
                    diagnosis_context+=" (via Paragraph ${current_id})"; echo "    -> Traversing up from Paragraph ${current_id} to parent '${parent_type}' (id: ${parent_id})"
                    current_type=$parent_type; current_id=$parent_id
                    continue
                else break; fi
            else break; fi
        done
        
        verification_query=""; usage_found=""; action_message="";
        if [ "$ultimate_parent_type" == "node" ]; then
            verification_query="SELECT status FROM pantheon.node_field_data WHERE nid = ${ultimate_parent_id};"
        elif [ "$ultimate_parent_type" == "block_content" ]; then
            verification_query="SELECT status FROM pantheon.block_content_field_data WHERE id = ${ultimate_parent_id};"
        else
            echo -e "    ${RED}DIAGNOSIS: Orphaned Chain. Could not find a final Node/Block parent for chain starting with '${type}' (id: ${id}).${NC}"
            echo -e "    ${GREEN}RECOMMENDATION: The entity chain is broken or unhandled. Safe to manually delete the file_usage record.${NC}"
            delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
            # Store for consolidation instead of outputting immediately
            echo "DELETABLE|$filename|$fid|$type|$id|$delete_cmd" >> "$temp_file"
            continue
        fi

        entity_status=$(run_sql "$verification_query")
        if [ -z "$entity_status" ]; then
            echo -e "    ${RED}DIAGNOSIS: Ghost Record${diagnosis_context}. The final parent ('${ultimate_parent_type}' id: ${ultimate_parent_id}) does NOT exist.${NC}"
            delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
        elif [ "$entity_status" == "1" ]; then
            # Check actual usage for block_content entities
            if [ "$ultimate_parent_type" == "block_content" ]; then
                echo -e "    ${YELLOW}Checking where block_content ${ultimate_parent_id} is actually used...${NC}"
                
                # Check Layout Builder usage with precise pattern matching
                layout_usage_query="SELECT entity_id, bundle FROM pantheon.node__layout_builder__layout WHERE layout_builder__layout_section LIKE '%\"block_id\";s:%:\"${ultimate_parent_id}\"%' OR layout_builder__layout_section LIKE '%\"block_revision_id\";s:%:\"${ultimate_parent_id}\"%';"
                layout_usage=$(run_sql "$layout_usage_query")
                
                if [ -n "$layout_usage" ]; then
                    node_id=$(echo "$layout_usage" | cut -f1 | head -1)
                    # Verify the node is published
                    node_status_query="SELECT status FROM pantheon.node_field_data WHERE nid = ${node_id};"
                    node_status=$(run_sql "$node_status_query")
                    if [ "$node_status" == "1" ]; then
                        # Block is used in published Layout Builder, but verify file actually exists in block content
                        echo -e "    ${YELLOW}Block is in Layout Builder on published node ${node_id}. Verifying file actually exists in block content...${NC}"
                        content_verification=$(verify_file_in_block_content "$ultimate_parent_id" "$file_basename")
                        
                        if [ "$content_verification" != "not_found" ]; then
                            echo -e "    ${YELLOW}File found in block content (${content_verification}). Now checking if file appears in rendered DOM...${NC}"
                            
                            # DOM verification using pre-initialized base URL
                            if [ "$ENABLE_DOM_VERIFICATION" == "true" ] && [ -n "$DETECTED_BASE_URL" ]; then
                                echo -e "    ${YELLOW}Checking DOM at ${DETECTED_BASE_URL}/node/${node_id}...${NC}"
                                check_file_in_dom "$file_basename" "$node_id" "$DETECTED_BASE_URL"
                                dom_result=$?
                                
                                case $dom_result in
                                    0)  # Found in DOM - truly active
                                        echo -e "    ${RED}✅ CONFIRMED: File appears on rendered page - TRULY ACTIVE${NC}"
                                        usage_found="layout_builder_verified"
                                        action_message="File found in block content (${content_verification}) AND in rendered DOM. Go to ${BASE_URL}/node/${node_id}/layout to remove block from Layout Builder"
                                        ;;
                                    3)  # Not found in DOM - phantom reference
                                        echo -e "    ${GREEN}👻 PHANTOM REFERENCE: File in database but NOT on rendered page${NC}"
                                        usage_found="phantom_reference"
                                        # No action_message - this will generate delete_cmd for auto-deletion
                                        ;;
                                    *)  # DOM check failed, fall back to conservative approach
                                        echo -e "    ${YELLOW}⚠️ DOM CHECK FAILED: Cannot verify, being conservative${NC}"
                                        usage_found="layout_builder_conservative"
                                        action_message="File found in block content (${content_verification}). DOM check failed - manual review recommended. Go to ${BASE_URL}/node/${node_id}/layout"
                                        ;;
                                esac
                            else
                                usage_found="layout_builder_verified"
                                action_message="File found in block content (${content_verification}). Go to ${BASE_URL}/node/${node_id}/layout to remove block from Layout Builder"
                            fi
                        else
                            echo -e "    ${GREEN}File not found in block content fields. This is a ghost reference - block exists but file was removed.${NC}"
                            usage_found="ghost_reference"
                            action_message="Ghost reference - block exists but file not in content. Safe to delete file_usage record"
                        fi
                    else
                        echo -e "    ${YELLOW}Found in Layout Builder on unpublished node ${node_id}. Checking other usage...${NC}"
                    fi
                fi
                
                # If not found in published Layout Builder, check paragraph usage
                if [ -z "$usage_found" ]; then
                    # Check if block is referenced by paragraphs (most common in YaleSites)
                    para_usage_query="SELECT p.parent_id, p.parent_type FROM pantheon.paragraphs_item_field_data p WHERE p.id IN (SELECT entity_id FROM pantheon.paragraph__field_content WHERE field_content_target_id = ${ultimate_parent_id});"
                    para_usage=$(run_sql "$para_usage_query")
                    
                    if [ -n "$para_usage" ]; then
                        parent_id=$(echo "$para_usage" | cut -f1 | head -1)
                        parent_type=$(echo "$para_usage" | cut -f2 | head -1)
                        if [ "$parent_type" == "node" ]; then
                            usage_found="paragraph_in_node"
                            action_message="Block is in a paragraph on node ${parent_id}. Go to /node/${parent_id}/edit to find and remove it"
                        else
                            # If parent is block_content, check if that block is used in Layout Builder
                            if [ "$parent_type" == "block_content" ]; then
                                parent_layout_query="SELECT entity_id, bundle FROM pantheon.node__layout_builder__layout WHERE layout_builder__layout_section LIKE '%\"block_id\";s:%:\"${parent_id}\"%' OR layout_builder__layout_section LIKE '%\"block_revision_id\";s:%:\"${parent_id}\"%';"
                                parent_layout_usage=$(run_sql "$parent_layout_query")
                                if [ -n "$parent_layout_usage" ]; then
                                    parent_node_id=$(echo "$parent_layout_usage" | cut -f1 | head -1)
                                    # Verify parent node is published
                                    parent_node_status_query="SELECT status FROM pantheon.node_field_data WHERE nid = ${parent_node_id};"
                                    parent_node_status=$(run_sql "$parent_node_status_query")
                                    if [ "$parent_node_status" == "1" ]; then
                                        # Nested block is used in published Layout Builder, verify file exists in content
                                        echo -e "    ${YELLOW}Nested block is in Layout Builder on published node ${parent_node_id}. Verifying file actually exists in block content...${NC}"
                                        nested_content_verification=$(verify_file_in_block_content "$ultimate_parent_id" "$file_basename")
                                        
                                        if [ "$nested_content_verification" != "not_found" ]; then
                                            usage_found="paragraph_nested_in_layout_verified"
                                            action_message="File found in nested block content (${nested_content_verification}). Block is nested in paragraph within block ${parent_id} on node ${parent_node_id}. Go to ${BASE_URL}/node/${parent_node_id}/layout to find and remove it"
                                        else
                                            echo -e "    ${GREEN}File not found in nested block content fields. This is a ghost reference - nested block exists but file was removed.${NC}"
                                            usage_found="ghost_reference"
                                            action_message="Ghost reference - nested block exists but file not in content. Safe to delete file_usage record"
                                        fi
                                    else
                                        usage_found="paragraph_nested"
                                        action_message="Block is in nested paragraphs. Check paragraph structure starting with ${parent_type} ${parent_id}"
                                    fi
                                else
                                    usage_found="paragraph_nested"
                                    action_message="Block is in nested paragraphs. Check paragraph structure starting with ${parent_type} ${parent_id}"
                                fi
                            else
                                usage_found="paragraph_nested"
                                action_message="Block is in nested paragraphs. Check paragraph structure starting with ${parent_type} ${parent_id}"
                            fi
                        fi
                    else
                        usage_found="database_only"
                        action_message="Block exists in database but is not placed anywhere. Safe to delete via SQL"
                    fi
                fi
                
                if [ "$usage_found" == "database_only" ]; then
                    echo -e "    ${GREEN}DIAGNOSIS: Unused Block${diagnosis_context}. Block_content ${ultimate_parent_id} exists but is not placed anywhere.${NC}"
                    echo -e "    ${GREEN}RECOMMENDATION: Safe to delete via SQL command.${NC}"
                    delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
                elif [ "$usage_found" == "ghost_reference" ]; then
                    echo -e "    ${GREEN}DIAGNOSIS: Ghost Reference${diagnosis_context}. Block_content ${ultimate_parent_id} exists but file was removed from content.${NC}"
                    echo -e "    ${GREEN}RECOMMENDATION: Safe to delete stale file_usage record.${NC}"
                    delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
                elif [ "$usage_found" == "phantom_reference" ]; then
                    echo -e "    ${GREEN}DIAGNOSIS: 👻 Phantom Reference${diagnosis_context}. Block_content ${ultimate_parent_id} contains file in database but DOM verification confirms file NOT rendered on page.${NC}"
                    echo -e "    ${GREEN}RECOMMENDATION: Safe to delete phantom file_usage record - file appears in database but not on actual page.${NC}"
                    delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
                elif [ "$usage_found" == "layout_builder_conservative" ]; then
                    echo -e "    ${YELLOW}DIAGNOSIS: ⚠️ Conservative Block${diagnosis_context}. Block_content ${ultimate_parent_id} is actively used but DOM verification failed.${NC}"
                    echo -e "    ${YELLOW}RECOMMENDATION: Manual review recommended due to DOM verification failure.${NC}"
                    echo -e "    ${BLUE}ACTION: ${action_message}${NC}"; continue
                else
                    echo -e "    ${YELLOW}DIAGNOSIS: ✅ Active Block${diagnosis_context}. Block_content ${ultimate_parent_id} is actively used and DOM verified.${NC}"
                    echo -e "    ${YELLOW}RECOMMENDATION: Remove file through UI to prevent breaking content.${NC}"
                    echo -e "    ${BLUE}ACTION: ${action_message}${NC}"; continue
                fi
            else
                # For nodes, use the existing logic
                echo -e "    ${YELLOW}DIAGNOSIS: Live Entity${diagnosis_context}. The final parent ('${ultimate_parent_type}' id: ${ultimate_parent_id}) is published.${NC}"
                echo -e "    ${YELLOW}RECOMMENDATION: Fix this in the UI. Do NOT run a manual query.${NC}"
                echo -e "    ${BLUE}ACTION: Go to /node/${ultimate_parent_id}/edit, find the file, and remove it.${NC}"; continue
            fi
        elif [ "$entity_status" == "0" ]; then
            echo -e "    ${RED}DIAGNOSIS: Soft-Deleted Entity${diagnosis_context}. The final parent ('${ultimate_parent_type}' id: ${ultimate_parent_id}) is unpublished.${NC}"
            delete_cmd="BEGIN; DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}; SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid}); UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND @remaining = 0; COMMIT;"
        fi
        
        if [ -n "$delete_cmd" ]; then
            # Store deletable usage record for consolidation instead of outputting immediately
            echo "DELETABLE|$filename|$fid|$type|$id|$delete_cmd" >> "$temp_file"
        fi
    done
    
    # Add summary for multi-reference files
    if [ "$reference_count" -gt 1 ]; then
        echo -e "  ${CYAN}📋 MULTI-REFERENCE SUMMARY:${NC}"
        echo -e "    This file has ${reference_count} different database references."
        echo -e "    Some references may be automatically cleaned while others require manual action."
        echo -e "    Check both the 'Successfully Deleted' and 'Manual Action Required' sections in cleanup results."
    fi
done 3< "$FILES_TO_CHECK"

# Function to generate consolidated transaction
generate_consolidated_transaction() {
    local filename="$1"
    local fid="$2"
    shift 2
    local delete_statements=("$@")
    
    echo -e "\n🔄 ${YELLOW}Generating consolidated cleanup for:${NC} ${filename}"
    echo -e "    ${GREEN}RECOMMENDATION: Safe to delete all orphaned usage records in single transaction.${NC}"
    
    # Build the consolidated transaction
    local consolidated_cmd="BEGIN; "
    for stmt in "${delete_statements[@]}"; do
        consolidated_cmd+="$stmt "
    done
    consolidated_cmd+="SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = $fid); UPDATE pantheon.file_managed SET status = 0 WHERE fid = $fid AND @remaining = 0; COMMIT;"
    
    [ "$MODE" == "lando" ] && echo -e "    ${BLUE}COMMAND: lando mysql -e \"${consolidated_cmd}\"${NC}" || echo -e "    ${BLUE}COMMAND: echo \"${consolidated_cmd}\" | terminus drush \"${SITE_NAME}.${ENV}\" sql:cli${NC}"
}

# Process collected deletable records and generate consolidated transactions
temp_file="/tmp/usage_analysis_$$"
if [ -f "$temp_file" ]; then
    echo -e "\n${YELLOW}=== GENERATING CONSOLIDATED CLEANUP COMMANDS ===${NC}"
    
    # Group deletable records by filename and fid, then generate consolidated transactions
    current_filename=""
    current_fid=""
    delete_statements=()
    
    # Sort by filename and fid to group related records
    while IFS='|' read -r action filename fid type id full_delete_cmd; do
        if [ "$action" == "DELETABLE" ]; then
            if [ "$filename" != "$current_filename" ] || [ "$fid" != "$current_fid" ]; then
                # Output previous group's consolidated transaction if any
                if [ ${#delete_statements[@]} -gt 0 ]; then
                    generate_consolidated_transaction "$current_filename" "$current_fid" "${delete_statements[@]}"
                    delete_statements=()
                fi
                current_filename="$filename"
                current_fid="$fid"
            fi
            
            # Add this deletion to the current group
            delete_statements+=("DELETE FROM pantheon.file_usage WHERE fid = $fid AND type = '$type' AND id = $id;")
        fi
    done < <(sort "$temp_file")
    
    # Output final group if any
    if [ ${#delete_statements[@]} -gt 0 ]; then
        generate_consolidated_transaction "$current_filename" "$current_fid" "${delete_statements[@]}"
    fi
    
    # Clean up temp file
    rm -f "$temp_file"
fi

echo -e "\n------------------------------------------------\n${GREEN}Scan complete.${NC}"
