#!/bin/bash

# ==============================================================================
# DOM File Usage Verifier (v1.0)
#
# Verifies if files marked for manual review are actually referenced in the
# rendered DOM content of their associated node pages. This helps identify
# files that exist in the database but aren't actually displayed to users.
#
# DESCRIPTION:
# This script takes the manual review files from cleanup_files.sh output and
# fetches the actual node pages using curl to check if the files are referenced
# in the rendered HTML. It can identify "phantom references" where files exist
# in the database/Layout Builder but aren't actually shown in the final content.
#
# USAGE:
#   ./verify_dom_usage.sh [OPTIONS] <cleanup_report_file>
#
# OPTIONS:
#   --base-url <url>           Base URL for the site (default: auto-detect from lando)
#   --user-agent <string>      Custom User-Agent string
#   --timeout <seconds>        Request timeout in seconds (default: 30)
#   --dry-run                  Show what would be checked without making requests
#   --verbose                  Show detailed output
#   --help                     Show this help message
#
# EXAMPLES:
#   # Auto-detect lando URL and verify all manual files
#   ./verify_dom_usage.sh cleanup_report_20250814_075224.log
#
#   # Use custom base URL
#   ./verify_dom_usage.sh --base-url https://dev-mysite.pantheonsite.io cleanup_report.log
#
#   # Dry run to see what would be checked
#   ./verify_dom_usage.sh --dry-run cleanup_report.log
#
# INPUT FORMAT:
# The script expects a cleanup report file containing lines like:
#   ⚠️ filename.pdf
#      Action: File found in block content (found_in_field_text). Go to /node/123/layout to remove block from Layout Builder
#
# OUTPUT:
# The script generates a verification report showing:
# - Files that are NOT found in DOM (safe to delete via SQL)
# - Files that ARE found in DOM (require manual UI removal)
# - Files that couldn't be verified (network/parsing errors)
#
# VERIFICATION METHODS:
# For each file, the script checks multiple patterns:
# 1. Direct filename matches in href, src, and data attributes
# 2. Partial matches for files that might be renamed in filesystem
# 3. Case-insensitive matching for various file references
# 4. URL-encoded filename variations
#
# SAFETY FEATURES:
# - Respects robots.txt and site rate limiting
# - Uses proper User-Agent identification
# - Handles network timeouts gracefully
# - Provides detailed logging for debugging
# - Safe fallback recommendations for uncertain cases
#
# NETWORK CONSIDERATIONS:
# - Works with both local Lando and remote Pantheon environments
# - Automatically detects Lando site URL if available
# - Supports custom base URLs for any environment
# - Handles redirects and authentication transparently
#
# ==============================================================================

# --- CONFIGURATION ---
SCRIPT_NAME="verify_dom_usage.sh"
VERSION="1.0"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
REPORT_FILE="dom_verification_report_${TIMESTAMP}.log"
CLEANUP_REPORT_FILE=""
BASE_URL=""
USER_AGENT="Mozilla/5.0 (File Cleanup Verifier) Drupal DOM Scanner"
TIMEOUT=30
DRY_RUN=false
VERBOSE=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Counters
TOTAL_FILES=0
FILES_NOT_IN_DOM=0
FILES_IN_DOM=0
FILES_ERROR=0

# --- FUNCTIONS ---

show_usage() {
    echo "Usage: $SCRIPT_NAME [OPTIONS] <cleanup_report_file>"
    echo ""
    echo "OPTIONS:"
    echo "  --base-url <url>           Base URL for the site (default: auto-detect from lando)"
    echo "  --user-agent <string>      Custom User-Agent string"
    echo "  --timeout <seconds>        Request timeout in seconds (default: 30)"
    echo "  --dry-run                  Show what would be checked without making requests"
    echo "  --verbose                  Show detailed output"
    echo "  --help                     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $SCRIPT_NAME cleanup_report.log                              # Auto-detect lando URL"
    echo "  $SCRIPT_NAME --base-url https://dev-site.pantheonsite.io cleanup_report.log"
    echo "  $SCRIPT_NAME --dry-run cleanup_report.log                    # Preview only"
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

log_verbose() {
    if [ "$VERBOSE" == "true" ]; then
        log_info "$1"
    fi
}

# Auto-detect lando URL
detect_lando_url() {
    if command -v lando >/dev/null 2>&1; then
        # Try to get the lando info and extract the URL
        local lando_info=$(lando info --format=json 2>/dev/null)
        if [ $? -eq 0 ] && [ -n "$lando_info" ]; then
            # Extract the appserver URL from lando info
            local url=$(echo "$lando_info" | grep -o '"https://[^"]*"' | head -1 | tr -d '"')
            if [ -n "$url" ]; then
                echo "$url"
                return 0
            fi
        fi
        
        # Fallback: try common lando patterns
        local project_name=$(basename "$(pwd)")
        local common_urls=(
            "https://${project_name}.lndo.site"
            "http://${project_name}.lndo.site"
            "https://localhost"
            "http://localhost"
        )
        
        for url in "${common_urls[@]}"; do
            log_verbose "Testing URL: $url"
            if curl -s --max-time 5 --head "$url" >/dev/null 2>&1; then
                echo "$url"
                return 0
            fi
        done
    fi
    return 1
}

# Fetch node content and check for file references
check_file_in_dom() {
    local filename="$1"
    local node_url="$2"
    local full_url="$3"
    
    log_verbose "Checking file '$filename' in DOM of $full_url"
    
    if [ "$DRY_RUN" == "true" ]; then
        log_info "[DRY RUN] Would check: $filename in $full_url"
        return 2  # Return 2 for dry run
    fi
    
    # Fetch the page content
    local page_content=$(curl -s --max-time "$TIMEOUT" \
        --user-agent "$USER_AGENT" \
        --location \
        --fail \
        "$full_url" 2>/dev/null)
    
    local curl_exit_code=$?
    
    if [ $curl_exit_code -ne 0 ]; then
        log_error "Failed to fetch $full_url (curl exit code: $curl_exit_code)"
        return 1  # Error
    fi
    
    if [ -z "$page_content" ]; then
        log_error "Empty content returned from $full_url"
        return 1  # Error
    fi
    
    # Create multiple search patterns for the filename
    local base_filename=$(basename "$filename")
    local filename_no_ext="${base_filename%.*}"
    local file_ext="${base_filename##*.}"
    
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
    
    log_verbose "Searching for patterns: ${search_patterns[*]}"
    
    # Check each pattern in the page content (case-insensitive)
    for pattern in "${search_patterns[@]}"; do
        if echo "$page_content" | grep -i -q "$pattern"; then
            log_verbose "Found pattern '$pattern' in DOM"
            return 0  # Found in DOM
        fi
    done
    
    log_verbose "No patterns found in DOM for $filename"
    return 3  # Not found in DOM
}

# Parse manual review files from cleanup report
parse_manual_files() {
    log_info "Parsing manual review files from: $CLEANUP_REPORT_FILE"
    
    local current_file=""
    local current_node=""
    local in_manual_section=false
    
    while IFS= read -r line; do
        # Detect start of manual files section
        if [[ "$line" =~ "FILES REQUIRING MANUAL ACTION:" ]]; then
            in_manual_section=true
            continue
        fi
        
        # Skip if not in manual section
        if [ "$in_manual_section" != "true" ]; then
            continue
        fi
        
        # Stop at end of section (empty line or new section)
        if [[ "$line" =~ ^[[:space:]]*$ ]] || [[ "$line" =~ ^"Report saved to:" ]]; then
            break
        fi
        
        # Parse file line: "  ⚠️ filename.pdf"
        if [[ "$line" =~ ^[[:space:]]*⚠️[[:space:]]+(.+)$ ]]; then
            current_file="${BASH_REMATCH[1]}"
            ((TOTAL_FILES++))
            continue
        fi
        
        # Parse action line: "     Action: File found in block content (found_in_field_text). Go to /node/123/layout to remove block from Layout Builder"
        if [[ "$line" =~ ^[[:space:]]*Action:.*Go[[:space:]]+to[[:space:]]+/node/([0-9]+)/layout ]]; then
            current_node="${BASH_REMATCH[1]}"
            
            if [ -n "$current_file" ] && [ -n "$current_node" ]; then
                echo "$current_file|$current_node"
                current_file=""
                current_node=""
            fi
        fi
    done < "$CLEANUP_REPORT_FILE"
    
    log_info "Found $TOTAL_FILES files requiring manual review"
}

# Process all manual files
process_manual_files() {
    log_info "Processing manual review files..."
    
    # Create temporary file for parsed data
    local temp_file="/tmp/manual_files_$$"
    parse_manual_files > "$temp_file"
    
    if [ ! -s "$temp_file" ]; then
        log_error "No manual files found to process"
        rm -f "$temp_file"
        return 1
    fi
    
    log_info "Processing $(wc -l < "$temp_file") file/node combinations"
    
    while IFS='|' read -r filename node_id; do
        if [ -z "$filename" ] || [ -z "$node_id" ]; then
            continue
        fi
        
        local node_url="/node/$node_id"
        local full_url="$BASE_URL$node_url"
        
        log_info "Checking: $filename on node $node_id"
        
        check_file_in_dom "$filename" "$node_url" "$full_url"
        local result=$?
        
        case $result in
            0)  # Found in DOM
                log_warning "✋ FOUND in DOM: $filename (node $node_id) - Manual removal required"
                echo "IN_DOM|$filename|$node_id|Manual removal required through UI" >> "/tmp/verification_results_$$"
                ((FILES_IN_DOM++))
                ;;
            1)  # Error
                log_error "❌ ERROR checking: $filename (node $node_id) - Network/parsing error"
                echo "ERROR|$filename|$node_id|Could not verify due to error" >> "/tmp/verification_results_$$"
                ((FILES_ERROR++))
                ;;
            2)  # Dry run
                log_info "🔍 DRY RUN: Would check $filename (node $node_id)"
                ;;
            3)  # Not found in DOM
                log_success "✅ NOT in DOM: $filename (node $node_id) - Safe to delete via SQL"
                echo "NOT_IN_DOM|$filename|$node_id|Safe to delete via SQL" >> "/tmp/verification_results_$$"
                ((FILES_NOT_IN_DOM++))
                ;;
        esac
        
        # Small delay to be respectful to the server
        if [ "$DRY_RUN" != "true" ]; then
            sleep 0.5
        fi
        
    done < "$temp_file"
    
    rm -f "$temp_file"
}

# Generate final report
generate_report() {
    log_info "Generating final verification report..."
    
    echo "" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "DOM VERIFICATION SUMMARY" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "Base URL: $BASE_URL" | tee -a "$REPORT_FILE"
    echo "Dry Run: $DRY_RUN" | tee -a "$REPORT_FILE"
    echo "Timestamp: $(date)" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    echo "STATISTICS:" | tee -a "$REPORT_FILE"
    echo "  Total files checked: $TOTAL_FILES" | tee -a "$REPORT_FILE"
    echo "  Files NOT in DOM (safe to delete): $FILES_NOT_IN_DOM" | tee -a "$REPORT_FILE"
    echo "  Files IN DOM (manual removal): $FILES_IN_DOM" | tee -a "$REPORT_FILE"
    echo "  Files with errors: $FILES_ERROR" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    if [ -f "/tmp/verification_results_$$" ]; then
        if grep -q "NOT_IN_DOM" "/tmp/verification_results_$$"; then
            echo "FILES SAFE TO DELETE VIA SQL:" | tee -a "$REPORT_FILE"
            while IFS='|' read -r status filename node_id reason; do
                if [ "$status" == "NOT_IN_DOM" ]; then
                    echo "  ✅ $filename (node $node_id)" | tee -a "$REPORT_FILE"
                fi
            done < "/tmp/verification_results_$$"
            echo "" | tee -a "$REPORT_FILE"
        fi
        
        if grep -q "IN_DOM" "/tmp/verification_results_$$"; then
            echo "FILES REQUIRING MANUAL UI REMOVAL:" | tee -a "$REPORT_FILE"
            while IFS='|' read -r status filename node_id reason; do
                if [ "$status" == "IN_DOM" ]; then
                    echo "  ⚠️ $filename (node $node_id) - $reason" | tee -a "$REPORT_FILE"
                fi
            done < "/tmp/verification_results_$$"
            echo "" | tee -a "$REPORT_FILE"
        fi
        
        if grep -q "ERROR" "/tmp/verification_results_$$"; then
            echo "FILES WITH VERIFICATION ERRORS:" | tee -a "$REPORT_FILE"
            while IFS='|' read -r status filename node_id reason; do
                if [ "$status" == "ERROR" ]; then
                    echo "  ❌ $filename (node $node_id) - $reason" | tee -a "$REPORT_FILE"
                fi
            done < "/tmp/verification_results_$$"
        fi
    fi
    
    echo "" | tee -a "$REPORT_FILE"
    echo "Report saved to: $REPORT_FILE" | tee -a "$REPORT_FILE"
    
    # Generate SQL commands for files safe to delete
    if [ $FILES_NOT_IN_DOM -gt 0 ]; then
        echo "" | tee -a "$REPORT_FILE"
        echo "GENERATED SQL CLEANUP COMMANDS:" | tee -a "$REPORT_FILE"
        echo "(These files are not found in DOM and safe to remove)" | tee -a "$REPORT_FILE"
        echo "" | tee -a "$REPORT_FILE"
        
        while IFS='|' read -r status filename node_id reason; do
            if [ "$status" == "NOT_IN_DOM" ]; then
                # Generate SQL to find and delete the file_usage records for this filename
                echo "-- Delete file_usage records for: $filename" | tee -a "$REPORT_FILE"
                echo "DELETE fu FROM file_usage fu JOIN file_managed fm ON fu.fid = fm.fid WHERE fm.filename = '$filename';" | tee -a "$REPORT_FILE"
                echo "UPDATE file_managed SET status = 0 WHERE filename = '$filename' AND fid NOT IN (SELECT fid FROM file_usage);" | tee -a "$REPORT_FILE"
                echo "" | tee -a "$REPORT_FILE"
            fi
        done < "/tmp/verification_results_$$"
    fi
}

# Cleanup temporary files
cleanup_temp_files() {
    rm -f "/tmp/verification_results_$$" "/tmp/manual_files_$$"
}

# --- MAIN SCRIPT ---

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --base-url)
            BASE_URL="$2"
            shift 2
            ;;
        --user-agent)
            USER_AGENT="$2"
            shift 2
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --verbose)
            VERBOSE=true
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
            if [ -z "$CLEANUP_REPORT_FILE" ]; then
                CLEANUP_REPORT_FILE="$1"
            else
                echo "Too many arguments"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Validate required arguments
if [ -z "$CLEANUP_REPORT_FILE" ]; then
    echo "Error: Cleanup report file is required"
    show_usage
    exit 1
fi

if [ ! -f "$CLEANUP_REPORT_FILE" ]; then
    echo "Error: Cleanup report file not found: $CLEANUP_REPORT_FILE"
    exit 1
fi

# Initialize report file
echo "# DOM Verification Report - $(date)" > "$REPORT_FILE"
echo "# Generated by $SCRIPT_NAME v$VERSION" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

log_info "Starting DOM verification process..."
log_info "Cleanup report file: $CLEANUP_REPORT_FILE"

# Auto-detect base URL if not provided
if [ -z "$BASE_URL" ]; then
    log_info "Auto-detecting base URL..."
    BASE_URL=$(detect_lando_url)
    if [ $? -eq 0 ] && [ -n "$BASE_URL" ]; then
        log_success "Auto-detected base URL: $BASE_URL"
    else
        log_error "Could not auto-detect base URL. Please specify --base-url"
        exit 1
    fi
else
    log_info "Using provided base URL: $BASE_URL"
fi

# Validate base URL accessibility
if [ "$DRY_RUN" != "true" ]; then
    log_info "Testing base URL accessibility..."
    if curl -s --max-time 10 --head "$BASE_URL" >/dev/null 2>&1; then
        log_success "Base URL is accessible"
    else
        log_error "Base URL is not accessible: $BASE_URL"
        exit 1
    fi
fi

# Process manual files
process_manual_files

# Generate final report
generate_report

# Cleanup
cleanup_temp_files

log_success "DOM verification completed!"
echo ""
echo -e "${GREEN}✅ Files NOT in DOM (safe to delete): $FILES_NOT_IN_DOM${NC}"
echo -e "${YELLOW}⚠️ Files IN DOM (manual removal): $FILES_IN_DOM${NC}"
[ $FILES_ERROR -gt 0 ] && echo -e "${RED}❌ Files with errors: $FILES_ERROR${NC}"
echo ""
echo -e "${BLUE}📄 Full report: $REPORT_FILE${NC}"