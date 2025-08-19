#!/bin/bash

# ==============================================================================
# Single File Diagnosis Utility (v1.0)
#
# Analyzes a single file's usage in Drupal's file system and generates
# appropriate cleanup recommendations. Designed to follow Unix philosophy:
# do one thing and do it well.
#
# DESCRIPTION:
# This utility performs comprehensive analysis of a single file to determine:
# - Whether file exists in file_managed table
# - File usage records and their validity
# - Layout Builder usage and DOM verification
# - Appropriate cleanup recommendations
#
# USAGE:
#   ./diagnose_single_file.sh [OPTIONS] <filename>
#
# OPTIONS:
#   --mode <lando|terminus>     Force execution mode (default: lando)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --enable-dom-verification  Enable DOM content verification
#   --base-url <url>           Override base URL for DOM verification
#   --output <format>          Output format: human|json|tsv (default: human)
#   --help                     Show this help message
#
# OUTPUT FORMATS:
#   human: Human-readable diagnostic output (default)
#   json:  Structured JSON for programmatic use
#   tsv:   Tab-separated values for data processing
#
# EXAMPLES:
#   # Basic diagnosis
#   ./diagnose_single_file.sh report.pdf
#
#   # With DOM verification
#   ./diagnose_single_file.sh --enable-dom-verification document.docx
#
#   # JSON output for scripting
#   ./diagnose_single_file.sh --output json file.xlsx
#
#   # Terminus mode
#   ./diagnose_single_file.sh --mode terminus --site mysite --env dev file.pdf
#
# EXIT CODES:
#   0: Success
#   1: File analysis error
#   2: Database connection error
#   3: Invalid arguments
#
# ==============================================================================

# --- CONFIGURATION ---
MODE=""
SITE_NAME=""
ENV=""
FILENAME=""
ENABLE_DOM_VERIFICATION=false
BASE_URL=""
OUTPUT_FORMAT="human"
USER_AGENT="Mozilla/5.0 (Drupal File Diagnosis) Single File Scanner/v1.0"
CURL_TIMEOUT=10
PARAGRAPH_MEDIA_FIELD_NAME="field_media"
NODE_MEDIA_FIELD_NAME="field_media"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- FUNCTIONS ---

show_usage() {
    cat << EOF
Usage: $0 [OPTIONS] <filename>

Single file diagnosis utility for Drupal file cleanup.

OPTIONS:
  --mode <lando|terminus>     Force execution mode (default: lando)
  --site <sitename>          Pantheon site name (required for terminus mode)
  --env <environment>        Pantheon environment (required for terminus mode)
  --enable-dom-verification  Enable DOM content verification
  --base-url <url>           Override base URL for DOM verification
  --output <format>          Output format: human|json|tsv (default: human)
  --help                     Show this help message

OUTPUT FORMATS:
  human  Human-readable diagnostic output (default)
  json   Structured JSON for programmatic use
  tsv    Tab-separated values for data processing

EXAMPLES:
  $0 report.pdf
  $0 --enable-dom-verification document.docx
  $0 --output json file.xlsx
  $0 --mode terminus --site mysite --env dev file.pdf

EXIT CODES:
  0: Success, 1: Analysis error, 2: Database error, 3: Invalid arguments
EOF
}

# Execute SQL query based on mode
run_sql() {
    local query="$1"
    if [ "$MODE" == "lando" ]; then
        lando mysql -e "$query" 2>/dev/null | tail -n +2
    elif [ "$MODE" == "terminus" ]; then
        terminus remote:drush ${SITE_NAME}.${ENV} -- sql:query "$query" 2>/dev/null | tail -n +2
    else
        echo "Error: Unknown mode: $MODE" >&2
        exit 2
    fi
}

# Detect base URL for DOM verification
detect_base_url() {
    if [ -n "$BASE_URL" ]; then
        echo "$BASE_URL"
        return 0
    fi
    
    if [ "$MODE" == "lando" ]; then
        # Try multiple Lando detection methods
        if [ -f ".lando.local.yml" ]; then
            local drush_uri=$(grep "DRUSH_OPTIONS_URI:" .lando.local.yml | sed 's/.*DRUSH_OPTIONS_URI: *["'"'"']*\([^"'"'"']*\)["'"'"']*.*/\1/')
            if [ -n "$drush_uri" ] && curl -s --head "$drush_uri" >/dev/null 2>&1; then
                echo "$drush_uri"
                return 0
            fi
        fi
        
        # Try constructed URL from project name
        local project_name=$(grep "name:" .lando.local.yml 2>/dev/null | sed 's/.*name: *\([^ ]*\).*/\1/' | head -1)
        if [ -n "$project_name" ]; then
            local constructed_url="https://${project_name}.lndo.site"
            if curl -s --head "$constructed_url" >/dev/null 2>&1; then
                echo "$constructed_url"
                return 0
            fi
        fi
    fi
    
    # Return empty if detection fails
    echo ""
}

# Check if file appears in DOM content
check_file_in_dom() {
    local filename="$1"
    local node_url="$2"
    
    if [ -z "$BASE_URL" ]; then
        return 2  # Cannot check
    fi
    
    local response=$(curl -s -L --user-agent "$USER_AGENT" --max-time "$CURL_TIMEOUT" "$node_url" 2>/dev/null)
    local curl_exit=$?
    
    if [ $curl_exit -ne 0 ]; then
        return 2  # Curl failed
    fi
    
    if echo "$response" | grep -q "$filename"; then
        return 0  # Found in DOM
    else
        return 3  # Not found in DOM
    fi
}

# Verify content in block content fields
verify_content_in_block() {
    local block_id="$1"
    local filename="$2"
    
    # Check common text fields in block content
    local text_fields=("field_text" "body" "field_body" "field_content")
    
    for field in "${text_fields[@]}"; do
        local field_query="SELECT value FROM pantheon.block_content__${field} WHERE entity_id = ${block_id} AND value LIKE '%${filename}%' LIMIT 1;"
        local field_result=$(run_sql "$field_query" 2>/dev/null)
        if [ -n "$field_result" ]; then
            echo "found_in_${field}"
            return 0
        fi
    done
    
    echo "not_found"
    return 1
}

# Main diagnosis function
diagnose_file() {
    local filename="$1"
    local result=()
    
    # Step 1: Check if file exists in file_managed table
    local file_managed_query="SELECT fid, REPLACE(uri, 'public://', '') as file_path, status FROM pantheon.file_managed WHERE filename = '${filename}';"
    local file_managed_info=$(run_sql "$file_managed_query")
    
    if [ -z "$file_managed_info" ]; then
        # File not in file_managed table - truly orphaned
        result+=("diagnosis=truly_orphaned")
        result+=("recommendation=direct_deletion")
        result+=("action=rm_file")
        if [ "$MODE" == "lando" ]; then
            result+=("command=find web/sites/default/files -name '${filename}' -type f -print0 | while IFS= read -r -d '' file; do if rm \"\$file\" 2>/dev/null; then echo \"Successfully deleted: \$(basename \"\$file\")\"; else echo \"Error deleting: \$(basename \"\$file\")\"; fi; done")
        else
            result+=("command=terminus remote:drush ${SITE_NAME}.${ENV} -- eval \"\\$files = glob(DRUPAL_ROOT . '/sites/default/files/**/${filename}'); \\$deleted = 0; foreach(\\$files as \\$file) { if(file_exists(\\$file)) { if(is_writable(\\$file)) { if(unlink(\\$file)) { echo 'Successfully deleted: ' . basename(\\$file); \\$deleted++; } else { echo 'Error deleting: ' . basename(\\$file) . ' - ' . (error_get_last()['message'] ?? 'Unknown error'); } } else { echo 'Permission denied: ' . basename(\\$file); } } else { echo 'File not found: ' . basename(\\$file); } }\"")
        fi
        printf "%s\n" "${result[@]}"
        return 0
    fi
    
    # Parse file_managed info
    local fid=$(echo "$file_managed_info" | cut -f1 | head -1)
    local file_path=$(echo "$file_managed_info" | cut -f2 | head -1)
    local status=$(echo "$file_managed_info" | cut -f3 | head -1)
    
    result+=("fid=$fid")
    result+=("file_path=$file_path")
    result+=("current_status=$status")
    
    # Step 2: Check for usage records
    local file_usage_query="SELECT fu.module, fu.type, fu.id, fu.fid FROM pantheon.file_usage fu WHERE fu.fid = ${fid};"
    local usage_info=$(run_sql "$file_usage_query")
    
    if [ -z "$usage_info" ]; then
        # File in file_managed but no usage records - unused but managed
        result+=("diagnosis=unused_file")
        result+=("recommendation=set_status_temporary")
        result+=("action=set_status_0")
        if [ "$MODE" == "lando" ]; then
            result+=("command=lando mysql -e \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid};\"")
        else
            result+=("command=terminus remote:drush ${SITE_NAME}.${ENV} -- sql:query \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid};\"")
        fi
        printf "%s\n" "${result[@]}"
        return 0
    fi
    
    # File has usage records - analyze them
    result+=("diagnosis=has_usage_records")
    
    # Process each usage record
    local valid_usage=false
    local invalid_usage_commands=()
    
    echo "$usage_info" | while IFS=$'\t' read -r module type id usage_fid; do
        if [ "$type" == "block_content" ]; then
            # Check Layout Builder usage
            local layout_usage_query="SELECT entity_id, bundle FROM pantheon.node__layout_builder__layout WHERE layout_builder__layout_section LIKE '%\"block_id\";s:%:\"${id}\"%' OR layout_builder__layout_section LIKE '%\"block_revision_id\";s:%:\"${id}\"%';"
            local layout_usage=$(run_sql "$layout_usage_query")
            
            if [ -n "$layout_usage" ]; then
                local node_id=$(echo "$layout_usage" | cut -f1 | head -1)
                local node_status_query="SELECT status FROM pantheon.node_field_data WHERE nid = ${node_id};"
                local node_status=$(run_sql "$node_status_query")
                
                if [ "$node_status" == "1" ]; then
                    # Verify file exists in block content
                    local content_verification=$(verify_content_in_block "$id" "$filename")
                    
                    if [ "$content_verification" != "not_found" ]; then
                        if [ "$ENABLE_DOM_VERIFICATION" == "true" ] && [ -n "$BASE_URL" ]; then
                            # Check DOM
                            local dom_url="${BASE_URL}/node/${node_id}"
                            if check_file_in_dom "$filename" "$dom_url"; then
                                valid_usage=true
                                echo "usage_type=active_block_verified"
                                echo "node_id=$node_id"
                                echo "layout_url=${BASE_URL}/node/${node_id}/layout"
                            else
                                echo "usage_type=phantom_reference"
                                invalid_usage_commands+=("DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}")
                            fi
                        else
                            valid_usage=true
                            echo "usage_type=active_block"
                            echo "node_id=$node_id"
                            echo "layout_url=${BASE_URL}/node/${node_id}/layout"
                        fi
                    else
                        echo "usage_type=ghost_reference"
                        invalid_usage_commands+=("DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id}")
                    fi
                fi
            fi
        else
            # Handle other usage types (media, etc.)
            echo "usage_type=other"
            echo "usage_module=$module"
            echo "usage_type_name=$type"
            echo "usage_id=$id"
        fi
    done
    
    # Determine final recommendation
    if [ "$valid_usage" == "true" ]; then
        result+=("recommendation=manual_review")
        result+=("action=remove_via_ui")
    else
        result+=("recommendation=cleanup_invalid_usage")
        result+=("action=delete_usage_records")
        # Add cleanup commands for invalid usage
        for cmd in "${invalid_usage_commands[@]}"; do
            result+=("cleanup_command=$cmd")
        done
        # Add command to set status = 0 after cleanup
        if [ "$MODE" == "lando" ]; then
            result+=("final_command=lando mysql -e \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND 0 = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid});\"")
        else
            result+=("final_command=terminus remote:drush ${SITE_NAME}.${ENV} -- sql:query \"UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid} AND 0 = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = ${fid});\"")
        fi
    fi
    
    printf "%s\n" "${result[@]}"
    return 0
}

# Format output based on requested format
format_output() {
    local format="$1"
    shift
    local data=("$@")
    
    case "$format" in
        "json")
            echo "{"
            local first=true
            for item in "${data[@]}"; do
                local key="${item%%=*}"
                local value="${item#*=}"
                if [ "$first" == "true" ]; then
                    first=false
                else
                    echo ","
                fi
                printf "  \"%s\": \"%s\"" "$key" "$value"
            done
            echo ""
            echo "}"
            ;;
        "tsv")
            for item in "${data[@]}"; do
                echo "$item"
            done
            ;;
        "human"|*)
            echo "🔎 Diagnosing file: $FILENAME"
            for item in "${data[@]}"; do
                local key="${item%%=*}"
                local value="${item#*=}"
                case "$key" in
                    "diagnosis")
                        echo -e "  ${GREEN}DIAGNOSIS:${NC} $value"
                        ;;
                    "recommendation")
                        echo -e "  ${BLUE}RECOMMENDATION:${NC} $value"
                        ;;
                    "command"|"cleanup_command"|"final_command")
                        echo -e "  ${YELLOW}COMMAND:${NC} $value"
                        ;;
                    "layout_url")
                        echo -e "  ${BLUE}ACTION URL:${NC} $value"
                        ;;
                    *)
                        echo -e "  ${key}: $value"
                        ;;
                esac
            done
            ;;
    esac
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
        --enable-dom-verification)
            ENABLE_DOM_VERIFICATION=true
            shift
            ;;
        --base-url)
            BASE_URL="$2"
            shift 2
            ;;
        --output)
            OUTPUT_FORMAT="$2"
            shift 2
            ;;
        --help)
            show_usage
            exit 0
            ;;
        -*)
            echo "Error: Unknown option $1" >&2
            show_usage >&2
            exit 3
            ;;
        *)
            if [ -z "$FILENAME" ]; then
                FILENAME="$1"
            else
                echo "Error: Multiple filenames provided" >&2
                exit 3
            fi
            shift
            ;;
    esac
done

# Auto-detect mode if not specified
if [ -z "$MODE" ]; then
    # Try to detect Lando environment first (preferred default)
    if command -v lando >/dev/null 2>&1; then
        if [ -f ".lando.yml" ] || [ -f ".lando.local.yml" ]; then
            if lando info >/dev/null 2>&1; then
                MODE="lando"
            fi
        fi
    fi
    
    # Try to detect Terminus environment
    if [ -z "$MODE" ] && command -v terminus >/dev/null 2>&1; then
        MODE="terminus"
    fi
    
    # Default to lando as a sane default
    if [ -z "$MODE" ]; then
        MODE="lando"
    fi
fi

# Validate arguments
if [ -z "$FILENAME" ]; then
    echo "Error: Filename is required" >&2
    show_usage >&2
    exit 3
fi

if [ "$MODE" == "terminus" ] && ([ -z "$SITE_NAME" ] || [ -z "$ENV" ]); then
    echo "Error: Terminus mode requires --site and --env parameters" >&2
    exit 3
fi

# Initialize DOM verification if enabled
if [ "$ENABLE_DOM_VERIFICATION" == "true" ]; then
    DETECTED_BASE_URL=$(detect_base_url)
    if [ -n "$DETECTED_BASE_URL" ]; then
        BASE_URL="$DETECTED_BASE_URL"
    elif [ -z "$BASE_URL" ]; then
        echo "Warning: DOM verification requested but could not detect base URL" >&2
        ENABLE_DOM_VERIFICATION=false
    fi
fi

# Test database connectivity
if [ "$MODE" == "lando" ]; then
    if ! lando mysql -e "SELECT 1;" >/dev/null 2>&1; then
        echo "Error: Cannot connect to Lando database. Is Lando running?" >&2
        exit 2
    fi
elif [ "$MODE" == "terminus" ]; then
    if ! terminus remote:drush ${SITE_NAME}.${ENV} -- sql:query "SELECT 1;" >/dev/null 2>&1; then
        echo "Error: Cannot connect to Terminus database. Check site/env parameters." >&2
        exit 2
    fi
fi

# Perform diagnosis
diagnosis_result=$(diagnose_file "$FILENAME")
if [ $? -ne 0 ]; then
    echo "Error: Failed to diagnose file: $FILENAME" >&2
    exit 1
fi

# Convert result to array
IFS=$'\n' read -d '' -r -a result_array <<< "$diagnosis_result"

# Format and output result
format_output "$OUTPUT_FORMAT" "${result_array[@]}"

exit 0