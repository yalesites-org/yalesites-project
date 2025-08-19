#!/bin/bash

# ==============================================================================
# Spot-Check File Verification Script (v1.1)
#
# Spot-checks specific files or re-verifies files where DOM verification failed
# during the main diagnostic process. Can also be used to verify manual action
# files from cleanup results.
#
# DESCRIPTION:
# This script provides on-demand verification of files to determine if they
# actually appear on rendered pages. It's useful for:
# - Re-checking files where DOM verification failed in diagnostics
# - Spot-checking specific files by filename
# - Double-checking manual action items from cleanup results
# - Verifying files on specific node pages
#
# USAGE:
#   ./verify_manual_actions.sh [OPTIONS] [<input_source>]
#
# OPTIONS:
#   --mode <lando|terminus>     Execution mode (auto-detected if not specified)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --base-url <url>           Override base URL for DOM verification
#   --timeout <seconds>        HTTP timeout for DOM checks (default: 10)
#   --file <filename>          Check specific file by name
#   --node <node_id>           Check specific node page
#   --conservative-only        Only check files marked as "Conservative" (DOM check failed)
#   --dry-run                  Show what would be checked without executing
#   --help                     Show this help message
#
# EXAMPLES:
#   # Check all manual action files from cleanup results
#   ./verify_manual_actions.sh cleanup_results.txt
#
#   # Spot-check a specific file
#   ./verify_manual_actions.sh --file "master_ye-fy24-closing-package.docx" --node 1007
#
#   # Re-check only files where DOM verification failed
#   ./verify_manual_actions.sh --conservative-only cleanup_results.txt
#
#   # Custom base URL
#   ./verify_manual_actions.sh --base-url https://yalesites-platform.lndo.site cleanup_results.txt
#
# OUTPUT:
# The script generates a verification report showing:
# - Files that actually appear on rendered pages (true manual actions)
# - Files that don't appear on pages (phantom references, safe to delete)
# - Files where verification failed (network errors, access issues)
#
# ==============================================================================

# --- CONFIGURATION ---
SCRIPT_NAME="verify_manual_actions.sh"
VERSION="1.1"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
REPORT_FILE="verification_report_${TIMESTAMP}.log"
DRY_RUN=false
MODE=""
SITE_NAME=""
ENV=""
BASE_URL=""
TIMEOUT=10
CLEANUP_RESULTS_FILE=""
USER_AGENT="Mozilla/5.0 (Spot-Check File Verifier) DOM Scanner/v1.1"
SPECIFIC_FILE=""
SPECIFIC_NODE=""
CONSERVATIVE_ONLY=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Counters
TOTAL_MANUAL_FILES=0
VERIFIED_PRESENT=0
VERIFIED_ABSENT=0
VERIFICATION_FAILED=0
PHANTOM_REFERENCES=0

# --- FUNCTIONS ---

show_usage() {
    echo "Usage: $SCRIPT_NAME [OPTIONS] <cleanup_results_file>"
    echo ""
    echo "OPTIONS:"
    echo "  --mode <lando|terminus>     Execution mode (auto-detected if not specified)"
    echo "  --site <sitename>          Pantheon site name (required for terminus mode)"
    echo "  --env <environment>        Pantheon environment (required for terminus mode)"
    echo "  --base-url <url>           Override base URL for DOM verification"
    echo "  --timeout <seconds>        HTTP timeout for DOM checks (default: 10)"
    echo "  --dry-run                  Show what would be checked without executing"
    echo "  --help                     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $SCRIPT_NAME cleanup_results.txt                                    # Auto-detect mode"
    echo "  $SCRIPT_NAME --base-url https://site.lndo.site cleanup_results.txt  # Custom URL"
    echo "  $SCRIPT_NAME --mode terminus --site mysite --env dev cleanup_results.txt  # Terminus mode"
}

log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    echo -e "[$timestamp] [$level] $message" | tee -a "$REPORT_FILE"
}

log_info() {
    log_message "INFO" "$1"
}

log_success() {
    log_message "SUCCESS" "${GREEN}$1${NC}"
}

log_warning() {
    log_message "WARNING" "${YELLOW}$1${NC}"
}

log_error() {
    log_message "ERROR" "${RED}$1${NC}"
}

# Auto-detect base URL using same logic as diagnose_files.sh
detect_base_url() {
    if [ -n "$BASE_URL" ]; then
        echo "$BASE_URL"
        return 0
    fi
    
    if [ "$MODE" == "lando" ] && command -v lando >/dev/null 2>&1; then
        log_info "Detecting Lando domain..."
        
        # Method 1: Check .lando.local.yml for DRUSH_OPTIONS_URI
        if [ -f ".lando.local.yml" ]; then
            local drush_uri=$(grep -E "^\s*DRUSH_OPTIONS_URI:" .lando.local.yml | sed 's/.*DRUSH_OPTIONS_URI:\s*["\x27]*\([^"\x27 ]*\)["\x27]*.*/\1/')
            if [ -n "$drush_uri" ] && [[ "$drush_uri" =~ ^https?:// ]]; then
                log_success "Found domain in .lando.local.yml: ${drush_uri}"
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
            log_info "Trying constructed URL: ${constructed_url}"
            if curl -s --max-time 5 --head "$constructed_url" >/dev/null 2>&1; then
                log_success "Constructed URL is accessible"
                echo "$constructed_url"
                return 0
            fi
        fi
    fi
    
    return 1
}

# Check if file appears in DOM content
check_file_in_dom() {
    local filename="$1"
    local node_id="$2"
    local base_url="$3"
    
    if [ -z "$base_url" ]; then
        return 1  # No base URL available
    fi
    
    local full_url="${base_url}/node/${node_id}"
    
    # Fetch the page content
    local page_content=$(curl -s --max-time "$TIMEOUT" \
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
    
    return 2  # Not found in DOM
}

# Extract node ID from action message
extract_node_id() {
    local action_message="$1"
    
    # Look for /node/123 pattern
    if [[ "$action_message" =~ /node/([0-9]+) ]]; then
        echo "${BASH_REMATCH[1]}"
        return 0
    fi
    
    # Look for node 123 pattern
    if [[ "$action_message" =~ node[[:space:]]+([0-9]+) ]]; then
        echo "${BASH_REMATCH[1]}"
        return 0
    fi
    
    return 1
}

# Parse cleanup results and extract manual action files
parse_manual_actions() {
    log_info "Parsing cleanup results: $CLEANUP_RESULTS_FILE"
    
    local in_manual_section=false
    
    while IFS= read -r line; do
        # Detect manual action section
        if [[ "$line" =~ "FILES REQUIRING MANUAL ACTION:" ]]; then
            in_manual_section=true
            continue
        fi
        
        # End of manual section
        if [ "$in_manual_section" == "true" ] && [[ "$line" =~ ^[[:space:]]*$ ]]; then
            in_manual_section=false
            continue
        fi
        
        # Skip non-manual sections
        if [ "$in_manual_section" != "true" ]; then
            continue
        fi
        
        # Parse manual action entry
        if [[ "$line" =~ ^[[:space:]]*⚠️[[:space:]]*(.+)$ ]]; then
            local filename="${BASH_REMATCH[1]}"
            # Extract just the filename without status indicators
            filename=$(echo "$filename" | sed 's/ (DOM [^)]*)$//' | sed 's/ (DOM check failed.*)$//')
            ((TOTAL_MANUAL_FILES++))
            echo "MANUAL_FILE|$filename" >> "/tmp/verify_manual_$$"
        elif [[ "$line" =~ ^[[:space:]]*Action:[[:space:]]*(.+)$ ]]; then
            local action="${BASH_REMATCH[1]}"
            # If conservative-only mode, skip non-conservative actions
            if [ "$CONSERVATIVE_ONLY" == "true" ] && [[ ! "$action" =~ "Conservative" ]] && [[ ! "$action" =~ "DOM verification failed" ]]; then
                # Remove the last MANUAL_FILE entry as we're skipping this action
                sed -i '$ d' "/tmp/verify_manual_$$" 2>/dev/null || true
                ((TOTAL_MANUAL_FILES--))
            else
                echo "ACTION|$action" >> "/tmp/verify_manual_$$"
            fi
        fi
    done < "$CLEANUP_RESULTS_FILE"
    
    log_info "Found $TOTAL_MANUAL_FILES files requiring manual action"
}

# Verify manual action files
verify_manual_files() {
    if [ ! -f "/tmp/verify_manual_$$" ]; then
        log_info "No manual action files to verify"
        return 0
    fi
    
    local current_file=""
    local current_action=""
    
    while IFS='|' read -r type data; do
        case "$type" in
            "MANUAL_FILE")
                current_file="$data"
                ;;
            "ACTION")
                current_action="$data"
                
                if [ -n "$current_file" ] && [ -n "$current_action" ]; then
                    verify_single_file "$current_file" "$current_action"
                    current_file=""
                    current_action=""
                fi
                ;;
        esac
    done < "/tmp/verify_manual_$$"
}

# Verify a single file
verify_single_file() {
    local filename="$1"
    local action="$2"
    
    log_info "Verifying: $filename"
    
    if [ "$DRY_RUN" == "true" ]; then
        log_info "[DRY RUN] Would verify file on page: $action"
        return 0
    fi
    
    # Extract node ID from action
    local node_id=$(extract_node_id "$action")
    if [ -z "$node_id" ]; then
        log_warning "Could not extract node ID from action: $action"
        ((VERIFICATION_FAILED++))
        echo "FAILED|$filename|Could not extract node ID" >> "/tmp/verify_results_$$"
        return 1
    fi
    
    # Get base URL
    local base_url=$(detect_base_url)
    if [ -z "$base_url" ]; then
        log_error "Could not detect base URL for DOM verification"
        ((VERIFICATION_FAILED++))
        echo "FAILED|$filename|No base URL available" >> "/tmp/verify_results_$$"
        return 1
    fi
    
    # Check if file appears in DOM
    check_file_in_dom "$filename" "$node_id" "$base_url"
    local dom_result=$?
    
    case $dom_result in
        0)  # Found in DOM
            log_success "✅ File FOUND on page /node/$node_id - Manual action required"
            ((VERIFIED_PRESENT++))
            echo "PRESENT|$filename|/node/$node_id|$action" >> "/tmp/verify_results_$$"
            ;;
        2)  # Not found in DOM
            log_warning "👻 File NOT FOUND on page /node/$node_id - Phantom reference, safe to delete"
            ((VERIFIED_ABSENT++))
            ((PHANTOM_REFERENCES++))
            echo "PHANTOM|$filename|/node/$node_id|$action" >> "/tmp/verify_results_$$"
            ;;
        *)  # Error fetching content
            log_error "❌ Could not verify file on page /node/$node_id - Network/access error"
            ((VERIFICATION_FAILED++))
            echo "FAILED|$filename|/node/$node_id|Network error" >> "/tmp/verify_results_$$"
            ;;
    esac
}

# Generate verification report
generate_report() {
    log_info "Generating verification report..."
    
    echo "" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "MANUAL ACTION VERIFICATION SUMMARY" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "Execution Mode: $MODE" | tee -a "$REPORT_FILE"
    echo "Base URL: $(detect_base_url 2>/dev/null || echo 'Auto-detected')" | tee -a "$REPORT_FILE"
    echo "Timestamp: $(date)" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    echo "STATISTICS:" | tee -a "$REPORT_FILE"
    echo "  Total manual action files: $TOTAL_MANUAL_FILES" | tee -a "$REPORT_FILE"
    echo "  Files found on pages (true manual): $VERIFIED_PRESENT" | tee -a "$REPORT_FILE"
    echo "  Files NOT found on pages (phantom): $VERIFIED_ABSENT" | tee -a "$REPORT_FILE"
    echo "  Verification failures: $VERIFICATION_FAILED" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    if [ -f "/tmp/verify_results_$$" ]; then
        echo "TRUE MANUAL ACTIONS (files actually on pages):" | tee -a "$REPORT_FILE"
        while IFS='|' read -r status filename location action; do
            if [ "$status" == "PRESENT" ]; then
                echo "  ✅ $filename ($location)" | tee -a "$REPORT_FILE"
            fi
        done < "/tmp/verify_results_$$"
        echo "" | tee -a "$REPORT_FILE"
        
        echo "PHANTOM REFERENCES (safe to delete via SQL):" | tee -a "$REPORT_FILE"
        while IFS='|' read -r status filename location action; do
            if [ "$status" == "PHANTOM" ]; then
                echo "  👻 $filename ($location)" | tee -a "$REPORT_FILE"
                echo "     SQL: DELETE FROM file_usage WHERE fid = (SELECT fid FROM file_managed WHERE filename = '$filename');" | tee -a "$REPORT_FILE"
            fi
        done < "/tmp/verify_results_$$"
        echo "" | tee -a "$REPORT_FILE"
        
        echo "VERIFICATION FAILURES:" | tee -a "$REPORT_FILE"
        while IFS='|' read -r status filename location error; do
            if [ "$status" == "FAILED" ]; then
                echo "  ❌ $filename - $error" | tee -a "$REPORT_FILE"
            fi
        done < "/tmp/verify_results_$$"
    fi
    
    echo "" | tee -a "$REPORT_FILE"
    echo "Report saved to: $REPORT_FILE" | tee -a "$REPORT_FILE"
}

# Cleanup temporary files
cleanup_temp_files() {
    rm -f "/tmp/verify_manual_$$" "/tmp/verify_results_$$"
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
        --base-url)
            BASE_URL="$2"
            shift 2
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        --file)
            SPECIFIC_FILE="$2"
            shift 2
            ;;
        --node)
            SPECIFIC_NODE="$2"
            shift 2
            ;;
        --conservative-only)
            CONSERVATIVE_ONLY=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
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
            if [ -z "$CLEANUP_RESULTS_FILE" ]; then
                CLEANUP_RESULTS_FILE="$1"
            else
                echo "Too many arguments"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Validate arguments
if [ -n "$SPECIFIC_FILE" ] && [ -n "$SPECIFIC_NODE" ]; then
    # Spot-check mode - no input file needed
    log_info "Spot-check mode: verifying '$SPECIFIC_FILE' on node $SPECIFIC_NODE"
elif [ -z "$CLEANUP_RESULTS_FILE" ]; then
    echo "Error: Either --file and --node, or cleanup results file is required"
    show_usage
    exit 1
elif [ ! -f "$CLEANUP_RESULTS_FILE" ]; then
    echo "Error: Cleanup results file not found: $CLEANUP_RESULTS_FILE"
    exit 1
fi

# Auto-detect mode if not specified
if [ -z "$MODE" ]; then
    if grep -q "Mode: lando" "$CLEANUP_RESULTS_FILE"; then
        MODE="lando"
        log_info "Auto-detected mode: lando"
    elif grep -q "Mode: terminus" "$CLEANUP_RESULTS_FILE"; then
        MODE="terminus"
        log_info "Auto-detected mode: terminus"
    else
        log_error "Could not detect mode from cleanup results. Please specify --mode"
        exit 1
    fi
fi

# Initialize report file
echo "# Manual Action Verification Report - $(date)" > "$REPORT_FILE"
echo "# Generated by $SCRIPT_NAME v$VERSION" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

log_info "Starting file verification..."

if [ -n "$SPECIFIC_FILE" ] && [ -n "$SPECIFIC_NODE" ]; then
    # Spot-check mode
    log_info "Spot-checking: $SPECIFIC_FILE on node $SPECIFIC_NODE"
    verify_single_file "$SPECIFIC_FILE" "Go to /node/$SPECIFIC_NODE to check"
    ((TOTAL_MANUAL_FILES++))
else
    # File parsing mode
    log_info "Cleanup results file: $CLEANUP_RESULTS_FILE"
    parse_manual_actions
    verify_manual_files
fi

# Generate final report
generate_report

# Cleanup
cleanup_temp_files

log_success "Verification completed!"
echo ""
echo -e "${GREEN}✅ Files verified on pages: $VERIFIED_PRESENT${NC}"
echo -e "${YELLOW}👻 Phantom references found: $PHANTOM_REFERENCES${NC}"
[ $VERIFICATION_FAILED -gt 0 ] && echo -e "${RED}❌ Verification failures: $VERIFICATION_FAILED${NC}"
echo ""
echo -e "${BLUE}📄 Full report: $REPORT_FILE${NC}"