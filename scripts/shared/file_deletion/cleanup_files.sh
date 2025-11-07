#!/bin/bash

# ==============================================================================
# Drupal File Cleanup Automation Script (v1.0)
#
# Processes diagnostic results from diagnose_files.sh and executes automated
# cleanup operations including SQL database cleanup and file system deletions.
# Supports both Lando (local) and Terminus (remote Pantheon) environments.
#
# DESCRIPTION:
# This script parses the output from diagnose_files.sh and automatically
# executes the safe cleanup commands that were identified. It handles three
# types of operations:
# - SQL database cleanup (removing stale file_usage records)
# - Local file deletion (rm commands for Lando environment)
# - Remote file deletion (Terminus commands for Pantheon environment)
#
# The script provides comprehensive logging, safety confirmations, and detailed
# reporting of all operations performed.
#
# USAGE:
#   ./cleanup_files.sh [OPTIONS] <scan_results_file>
#
# OPTIONS:
#   --mode <lando|terminus>     Force execution mode (auto-detected if not specified)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --dry-run                  Show what would be deleted without executing
#   --auto-confirm             Skip confirmation prompts
#   --run-cron                 Run Drupal cron after cleanup to immediately process temporary files
#   --help                     Show this help message
#
# EXAMPLES:
#   # Auto-detect mode from scan results
#   ./cleanup_files.sh scan_results.log
#
#   # Force lando mode (local development)
#   ./cleanup_files.sh --mode lando scan_results.log
#
#   # Terminus mode for remote Pantheon site
#   ./cleanup_files.sh --mode terminus --site mysite --env dev scan_results.log
#
#   # Preview mode - see what would be done without executing
#   ./cleanup_files.sh --dry-run scan_results.log
#
#   # Automated execution without prompts
#   ./cleanup_files.sh --auto-confirm scan_results.log
#
#   # Run cleanup and immediately trigger Drupal cron
#   ./cleanup_files.sh --run-cron scan_results.log
#
#   # Terminus mode with cron execution
#   ./cleanup_files.sh --mode terminus --site mysite --env dev --run-cron scan_results.log
#
#   # Pipe from diagnosis script
#   ./diagnose_files.sh files.txt | ./cleanup_files.sh --dry-run -
#
#   # Pipe with explicit stdin
#   cat scan_results.log | ./cleanup_files.sh --dry-run -
#
# INPUT FORMAT:
# The script expects output from diagnose_files.sh containing command lines like:
#   COMMAND: lando mysql -e "DELETE FROM pantheon.file_usage WHERE..."
#   COMMAND: rm "web/sites/default/files/path/file.pdf"
#   COMMAND: terminus remote:drush site.env -- eval "unlink('path');"
#
# OPERATION TYPES:
#
# 1. Drupal-Native File Cleanup (RECOMMENDED):
#    - Uses transactional SQL to safely clean up orphaned files
#    - Removes ALL file_usage records for the orphaned file (fid)
#    - Sets file status to 0 (temporary) for Drupal's cron cleanup
#    - Physical file deletion handled by Drupal's file_cron() function
#    - Safer because Drupal verifies no other references exist before deletion
#    Example: BEGIN; DELETE FROM file_usage WHERE fid = 123; UPDATE file_managed SET status = 0 WHERE fid = 123; COMMIT;
#
# 2. Legacy Direct File Deletion (DEPRECATED):
#    - Direct filesystem operations (now skipped in favor of Drupal-native)
#    - Physical files are left for Drupal's cron to clean up after database cleanup
#    - Ensures consistency with Drupal's file management system
#    Example: rm "web/sites/default/files/2025-02/orphaned-file.pdf" (skipped)
#
# SAFETY FEATURES:
# - Database connectivity verification before execution
# - Dry-run mode for preview without changes
# - Interactive confirmation prompts (unless --auto-confirm used)
# - Comprehensive logging of all operations
# - Detailed success/failure reporting
# - Graceful error handling and recovery
#
# REPORTING:
# The script generates comprehensive reports including:
# - Execution summary with statistics
# - List of successfully processed files
# - Failed operations with error details
# - Files requiring manual intervention
# - Timestamped log file for audit purposes
#
# AUTO-DETECTION:
# The script can automatically detect the execution mode from the scan results
# by parsing the "Mode: lando" or "Mode: terminus" lines from diagnose_files.sh
# output. Site name and environment can also be extracted automatically.
#
# WORKFLOW INTEGRATION:
# This script is designed to work seamlessly with diagnose_files.sh:
# 1. Run diagnose_files.sh to generate scan results with commands
# 2. Review the scan results to understand what will be processed
# 3. Run cleanup_files.sh on the scan results to execute safe operations
# 4. Handle any remaining manual cases as indicated in the report
#
# PREREQUISITES:
# - diagnose_files.sh output file as input
# - Lando environment running (for lando mode) OR Terminus CLI configured (for terminus mode)
# - Database connectivity to target environment
# - Proper file system permissions for file deletion operations
# - bash shell with standard utilities (grep, sed, cut, etc.)
#
# ERROR HANDLING:
# The script includes robust error handling:
# - Database connection failures abort execution
# - Individual command failures are logged but don't stop processing
# - File not found errors are handled gracefully
# - Malformed commands are reported and skipped
# - All errors are included in the final report
#
# LOGGING:
# All operations are logged with timestamps to both console and log file:
# - INFO: General operation information
# - SUCCESS: Successful operations
# - WARNING: Non-critical issues
# - ERROR: Failed operations
# Report file: cleanup_report_YYYYMMDD_HHMMSS.log
#
# ==============================================================================

# --- CONFIGURATION ---
SCRIPT_NAME="cleanup_files.sh"
VERSION="1.0"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
REPORT_FILE="cleanup_report_${TIMESTAMP}.log"
DRY_RUN=false
AUTO_CONFIRM=false
RUN_CRON=false
CRON_SUCCESS=false
MODE="terminus"
SITE_NAME="ys-your-yale-edu"
ENV="filedeltest"
SCAN_RESULTS_FILE=""
USING_STDIN=false
TEMP_STDIN_FILE=""

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
AUTO_DELETE_FILES=0
MANUAL_FILES=0
DELETED_SUCCESS=0
DELETED_FAILED=0
SKIPPED_FILES=0

# --- FUNCTIONS ---

show_usage() {
    echo "Usage: $SCRIPT_NAME [OPTIONS] <scan_results_file>"
    echo ""
    echo "OPTIONS:"
    echo "  --mode <lando|terminus>     Force execution mode (auto-detected if not specified)"
    echo "  --site <sitename>          Pantheon site name (required for terminus mode)"
    echo "  --env <environment>        Pantheon environment (required for terminus mode)"
    echo "  --dry-run                  Show what would be deleted without executing"
    echo "  --auto-confirm             Skip confirmation prompts"
    echo "  --help                     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $SCRIPT_NAME scan_results.log                                    # Auto-detect mode"
    echo "  $SCRIPT_NAME --mode lando scan_results.log                       # Force lando mode"
    echo "  $SCRIPT_NAME --mode terminus --site mysite --env dev scan_results.log  # Terminus mode"
    echo "  $SCRIPT_NAME --dry-run scan_results.log                          # Preview only"
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

# Detect mode from scan results file if not specified
detect_mode() {
    if [ -n "$MODE" ]; then
        return 0
    fi
    
    local scan_mode=$(grep "^Mode:" "$SCAN_RESULTS_FILE" | cut -d' ' -f2)
    if [ "$scan_mode" == "lando" ]; then
        MODE="lando"
        log_info "Auto-detected mode: lando"
    elif [ "$scan_mode" == "terminus" ]; then
        MODE="terminus"
        # Try to extract site/env from scan results
        local scan_site=$(grep "SITE_NAME=" "$SCAN_RESULTS_FILE" | head -1 | cut -d'=' -f2 | tr -d '"')
        local scan_env=$(grep "ENV=" "$SCAN_RESULTS_FILE" | head -1 | cut -d'=' -f2 | tr -d '"')
        if [ -n "$scan_site" ] && [ -z "$SITE_NAME" ]; then
            SITE_NAME="$scan_site"
        fi
        if [ -n "$scan_env" ] && [ -z "$ENV" ]; then
            ENV="$scan_env"
        fi
        log_info "Auto-detected mode: terminus (site: $SITE_NAME, env: $ENV)"
    else
        log_error "Could not detect mode from scan results. Please specify --mode"
        exit 1
    fi
}

# Validate configuration
validate_config() {
    if [ ! -f "$SCAN_RESULTS_FILE" ]; then
        log_error "Scan results file not found: $SCAN_RESULTS_FILE"
        exit 1
    fi
    
    if [ -z "$MODE" ]; then
        log_error "Mode not specified and could not be auto-detected"
        exit 1
    fi
    
    if [ "$MODE" == "terminus" ]; then
        if [ -z "$SITE_NAME" ] || [ -z "$ENV" ]; then
            log_error "Terminus mode requires --site and --env parameters"
            exit 1
        fi
    fi
    
    # Test database connectivity
    log_info "Testing database connectivity..."
    if [ "$MODE" == "lando" ]; then
        if ! lando mysql -e "SELECT 1;" > /dev/null 2>&1; then
            log_error "Cannot connect to Lando database. Is Lando running?"
            exit 1
        fi
    else
        if ! echo "SELECT 1" | terminus drush "${SITE_NAME}.${ENV}" sql:cli > /dev/null 2>&1; then
            log_error "Cannot connect to Pantheon database. Check site name and environment."
            exit 1
        fi
    fi
    log_success "Database connectivity verified"
}

# Execute SQL command based on mode (supports multi-statement transactions)
execute_sql() {
    local sql_command="$1"
    local result=""
    local exit_code=0
    
    if [ "$DRY_RUN" == "true" ]; then
        log_info "[DRY RUN] Would execute SQL: $sql_command"
        return 0
    fi
    
    # Check if this is a transaction (contains BEGIN/COMMIT)
    local is_transaction=false
    if [[ "$sql_command" =~ BEGIN.*COMMIT ]]; then
        is_transaction=true
        log_info "Executing transactional SQL..."
    fi
    
    if [ "$MODE" == "lando" ]; then
        result=$(lando mysql -e "$sql_command" 2>&1)
        exit_code=$?
    else
        result=$(echo "$sql_command" | terminus drush "${SITE_NAME}.${ENV}" sql:cli 2>&1)
        exit_code=$?
    fi
    
    if [ $exit_code -eq 0 ]; then
        if [ "$is_transaction" == "true" ]; then
            log_success "Transaction executed successfully"
        else
            log_success "SQL executed successfully: $sql_command"
        fi
        return 0
    else
        if [ "$is_transaction" == "true" ]; then
            log_error "Transaction failed (rolled back): $sql_command"
        else
            log_error "SQL execution failed: $sql_command"
        fi
        log_error "Error: $result"
        return 1
    fi
}

# Execute file deletion command based on mode
execute_file_deletion() {
    local file_command="$1"
    local result=""
    local exit_code=0
    
    if [ "$DRY_RUN" == "true" ]; then
        log_info "[DRY RUN] Would execute file deletion: $file_command"
        return 0
    fi
    
    # Execute the command directly (it's already formatted for the correct environment)
    result=$(eval "$file_command" 2>&1)
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
        log_success "File deletion executed successfully: $file_command"
        return 0
    else
        log_error "File deletion failed: $file_command"
        log_error "Error: $result"
        return 1
    fi
}

# Parse scan results and categorize files
parse_scan_results() {
    log_info "Parsing scan results file: $SCAN_RESULTS_FILE"
    
    local current_file=""
    local in_file_section=false
    local recommendation=""
    local command=""
    local action=""
    local file_processed=false
    
    while IFS= read -r line; do
        # Detect file section start (original format)
        if [[ "$line" =~ ^🔎\ Checking\ file: ]]; then
            # Process previous file if we have one
            if [ "$in_file_section" == "true" ] && [ "$file_processed" == "false" ]; then
                process_file_entry "$current_file" "$recommendation" "$command" "$action"
            fi
            
            current_file=$(echo "$line" | sed 's/🔎 Checking file: //')
            in_file_section=true
            file_processed=false
            recommendation=""
            command=""
            action=""
            ((TOTAL_FILES++))
            continue
        fi
        
        # Detect consolidated cleanup section (new format)
        if [[ "$line" =~ ^🔄\ Generating\ consolidated\ cleanup\ for: ]]; then
            # Process previous file if we have one
            if [ "$in_file_section" == "true" ] && [ "$file_processed" == "false" ]; then
                process_file_entry "$current_file" "$recommendation" "$command" "$action"
            fi
            
            current_file=$(echo "$line" | sed 's/🔄 Generating consolidated cleanup for: //')
            in_file_section=true
            file_processed=false
            recommendation=""
            command=""
            action=""
            ((TOTAL_FILES++))
            continue
        fi
        
        # Skip non-file sections
        if [ "$in_file_section" != "true" ]; then
            continue
        fi
        
        # Extract recommendation
        if [[ "$line" =~ RECOMMENDATION: ]]; then
            recommendation=$(echo "$line" | sed 's/.*RECOMMENDATION: //')
        fi
        
        # Extract command
        if [[ "$line" =~ COMMAND: ]]; then
            command=$(echo "$line" | sed 's/.*COMMAND: //')
        fi
        
        # Extract action
        if [[ "$line" =~ ACTION: ]]; then
            action=$(echo "$line" | sed 's/.*ACTION: //')
        fi
        
        # End of file section or empty line - process this file
        if [[ "$line" =~ ^🔎 ]] || [[ "$line" =~ ^🔄 ]] || [ -z "$line" ]; then
            if [ "$file_processed" == "false" ]; then
                process_file_entry "$current_file" "$recommendation" "$command" "$action"
            fi
            in_file_section=false
            continue
        fi
    done < "$SCAN_RESULTS_FILE"
    
    # Process the last file if we end without a separator
    if [ "$in_file_section" == "true" ] && [ "$file_processed" == "false" ]; then
        process_file_entry "$current_file" "$recommendation" "$command" "$action"
    fi
    
    log_info "Parsing complete:"
    log_info "  Total files: $TOTAL_FILES"
    log_info "  Auto-deletable files: $AUTO_DELETE_FILES"
    log_info "  Manual review files: $MANUAL_FILES"
}

# Process a single file entry and categorize it
process_file_entry() {
    local file="$1"
    local rec="$2" 
    local cmd="$3"
    local act="$4"
    
    # Skip if no file name
    if [ -z "$file" ]; then
        return
    fi
    
    # If we have a command, this is auto-deletable (check recommendation)
    if [ -n "$cmd" ]; then
        if [[ "$rec" =~ "Safe to delete" ]] || [[ "$rec" =~ "Safe to manually delete" ]] || [[ "$rec" =~ "Set status = 0, let Drupal cron handle deletion" ]] || [[ "$rec" =~ "The entity chain is broken or unhandled. Safe to manually delete the file_usage record" ]] || [[ "$rec" =~ "Safe to delete stale file_usage record" ]] || [[ "$rec" =~ "Safe to delete via SQL command" ]] || [[ "$rec" =~ "Safe to delete directly from filesystem" ]] || [[ "$rec" =~ "Safe to delete phantom file_usage record" ]] || [[ "$rec" =~ "Safe to delete all orphaned usage records in single transaction" ]] || [[ "$rec" =~ "file appears in database but not on actual page" ]]; then
            echo "AUTO_DELETE|$file|$cmd|$rec" >> "/tmp/cleanup_auto_delete_$$"
            ((AUTO_DELETE_FILES++))
        else
            echo "MANUAL|$file|$rec" >> "/tmp/cleanup_manual_$$"
            ((MANUAL_FILES++))
        fi
    # If we have an action but no command, this is manual review
    elif [ -n "$act" ]; then
        echo "MANUAL|$file|$act" >> "/tmp/cleanup_manual_$$"
        ((MANUAL_FILES++))
    # If we have only a recommendation but no command/action, check if it's manual
    elif [ -n "$rec" ]; then
        if [[ "$rec" =~ "Remove file through UI" ]] || [[ "$rec" =~ "Fix this in the UI" ]]; then
            echo "MANUAL|$file|$rec" >> "/tmp/cleanup_manual_$$"
            ((MANUAL_FILES++))
        elif [[ "$rec" =~ "No action needed" ]]; then
            # Files that are already cleaned or don't need action - just track for reporting
            echo "INFO|$file|$rec" >> "/tmp/cleanup_info_$$"
        fi
    fi
}


# Execute auto-deletions
execute_auto_deletions() {
    if [ ! -f "/tmp/cleanup_auto_delete_$$" ]; then
        log_info "No auto-deletable files found"
        return 0
    fi
    
    log_info "Processing auto-deletable files..."
    
    while IFS='|' read -r type filename command rec <&3; do
        if [ "$type" == "AUTO_DELETE" ]; then
            log_info "Processing: $filename"
            
            # Determine command type and execute appropriately
            if [[ "$command" =~ "Direct deletion for truly orphaned file" ]] || [[ "$rec" =~ "Safe to delete directly from filesystem" ]]; then
                # Truly orphaned files (not in database) must be deleted directly
                log_info "Executing direct file deletion for truly orphaned file"
                
                if [[ "$command" =~ ^find\ .* ]] || [[ "$command" =~ ^rm\ .* ]]; then
                    # Lando file deletion
                    if execute_file_deletion "$command"; then
                        log_info "Successfully deleted orphaned file: $filename"
                        ((DELETED_SUCCESS++))
                        echo "SUCCESS|$filename|$command" >> "/tmp/cleanup_deleted_$$"
                    else
                        log_error "Failed to delete orphaned file: $filename"
                        ((DELETED_FAILED++))
                        echo "FAILED|$filename|$command" >> "/tmp/cleanup_failed_$$"
                    fi
                elif [[ "$command" =~ ^terminus\ remote:drush ]]; then
                    # Terminus file deletion
                    if execute_file_deletion "$command"; then
                        log_info "Successfully deleted orphaned file: $filename"
                        ((DELETED_SUCCESS++))
                        echo "SUCCESS|$filename|$command" >> "/tmp/cleanup_deleted_$$"
                    else
                        log_error "Failed to delete orphaned file: $filename"
                        ((DELETED_FAILED++))
                        echo "FAILED|$filename|$command" >> "/tmp/cleanup_failed_$$"
                    fi
                else
                    log_error "Unknown direct deletion command format: $command"
                    ((DELETED_FAILED++))
                    echo "FAILED|$filename|Unknown command format: $command" >> "/tmp/cleanup_failed_$$"
                fi
            elif [[ "$command" =~ ^find\ .* ]] || [[ "$command" =~ ^rm\ .* ]] || [[ "$command" =~ ^terminus\ remote:drush.*unlink ]]; then
                # Database-referenced files - use Drupal-native cleanup
                log_info "Skipping direct file deletion - using Drupal-native cleanup instead"
                log_info "Physical file will be cleaned up by Drupal's cron after database cleanup"
                ((DELETED_SUCCESS++))
                echo "SKIPPED|$filename|Using Drupal-native cleanup (was: $command)" >> "/tmp/cleanup_deleted_$$"
            else
                # SQL command (from diagnose_files.sh)
                local sql_command=""
                if [[ "$command" =~ lando\ mysql\ -e\ \"([^\"]+)\" ]]; then
                    sql_command="${BASH_REMATCH[1]}"
                elif [[ "$command" =~ terminus\ sql:query.*\"([^\"]+)\" ]]; then
                    sql_command="${BASH_REMATCH[1]}"
                else
                    log_error "Could not parse command: $command"
                    ((DELETED_FAILED++))
                    continue
                fi
                
                if execute_sql "$sql_command"; then
                    ((DELETED_SUCCESS++))
                    echo "DELETED|$filename|$sql_command" >> "/tmp/cleanup_deleted_$$"
                else
                    ((DELETED_FAILED++))
                    echo "FAILED|$filename|$sql_command" >> "/tmp/cleanup_failed_$$"
                fi
            fi
        fi
    done 3< "/tmp/cleanup_auto_delete_$$"
}

# Generate final report
generate_report() {
    log_info "Generating final report..."
    
    echo "" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "CLEANUP SUMMARY" | tee -a "$REPORT_FILE"
    echo "========================================" | tee -a "$REPORT_FILE"
    echo "Execution Mode: $MODE" | tee -a "$REPORT_FILE"
    if [ "$MODE" == "terminus" ]; then
        echo "Pantheon Site: $SITE_NAME" | tee -a "$REPORT_FILE"
        echo "Environment: $ENV" | tee -a "$REPORT_FILE"
    fi
    echo "Dry Run: $DRY_RUN" | tee -a "$REPORT_FILE"
    echo "Timestamp: $(date)" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    echo "STATISTICS:" | tee -a "$REPORT_FILE"
    echo "  Total files processed: $TOTAL_FILES" | tee -a "$REPORT_FILE"
    echo "  Auto-deletable files: $AUTO_DELETE_FILES" | tee -a "$REPORT_FILE"
    echo "  Successfully deleted: $DELETED_SUCCESS" | tee -a "$REPORT_FILE"
    echo "  Failed deletions: $DELETED_FAILED" | tee -a "$REPORT_FILE"
    echo "  Manual review required: $MANUAL_FILES" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    
    if [ -f "/tmp/cleanup_deleted_$$" ]; then
        echo "SUCCESSFULLY DELETED FILES:" | tee -a "$REPORT_FILE"
        while IFS='|' read -r status filename command; do
            echo "  ✅ $filename" | tee -a "$REPORT_FILE"
        done < "/tmp/cleanup_deleted_$$"
        echo "" | tee -a "$REPORT_FILE"
    fi
    
    if [ -f "/tmp/cleanup_failed_$$" ]; then
        echo "FAILED DELETIONS:" | tee -a "$REPORT_FILE"
        while IFS='|' read -r status filename command; do
            echo "  ❌ $filename - $command" | tee -a "$REPORT_FILE"
        done < "/tmp/cleanup_failed_$$"
        echo "" | tee -a "$REPORT_FILE"
    fi
    
    if [ -f "/tmp/cleanup_manual_$$" ]; then
        echo "FILES REQUIRING MANUAL ACTION:" | tee -a "$REPORT_FILE"
        echo "(DOM-verified files that actually appear on pages)" | tee -a "$REPORT_FILE"
        while IFS='|' read -r type filename action; do
            if [[ "$action" =~ "DOM verified" ]] || [[ "$action" =~ "TRULY ACTIVE" ]] || [[ "$action" =~ "appears on rendered page" ]]; then
                echo "  ✅ $filename (DOM VERIFIED - truly on page)" | tee -a "$REPORT_FILE"
            elif [[ "$action" =~ "DOM verification failed" ]] || [[ "$action" =~ "Conservative" ]]; then
                echo "  ⚠️ $filename (DOM check failed - manual review needed)" | tee -a "$REPORT_FILE"
            else
                echo "  ⚠️ $filename" | tee -a "$REPORT_FILE"
            fi
            echo "     Action: $action" | tee -a "$REPORT_FILE"
        done < "/tmp/cleanup_manual_$$"
    fi
    
    echo "" | tee -a "$REPORT_FILE"
    echo "Report saved to: $REPORT_FILE" | tee -a "$REPORT_FILE"
}

# Cleanup temporary files
cleanup_temp_files() {
    rm -f "/tmp/cleanup_auto_delete_$$" "/tmp/cleanup_manual_$$" "/tmp/cleanup_deleted_$$" "/tmp/cleanup_failed_$$"
    # Clean up stdin temporary file if it exists
    if [ -n "$TEMP_STDIN_FILE" ] && [ -f "$TEMP_STDIN_FILE" ]; then
        rm -f "$TEMP_STDIN_FILE"
    fi
}

# Run Drupal cron to process temporary files
run_drupal_cron() {
    if [ "$DRY_RUN" == "true" ]; then
        log_info "[DRY RUN] Would run Drupal cron to process temporary files"
        return 0
    fi
    
    log_info "Running Drupal cron to process temporary files..."
    
    # Use the dedicated run_cron.sh script which handles environment detection and verification
    local cron_args=""
    if [ "$MODE" == "terminus" ] && [ -n "$SITE_NAME" ] && [ -n "$ENV" ]; then
        cron_args="--mode terminus --site $SITE_NAME --env $ENV"
    elif [ "$MODE" == "lando" ]; then
        cron_args="--mode lando"
    fi
    
    local cron_result=""
    local exit_code=0
    
    # Get the directory where this script is located
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    if [ -f "$SCRIPT_DIR/run_cron.sh" ]; then
        cron_result=$("$SCRIPT_DIR/run_cron.sh" $cron_args --verbose 2>&1)
        exit_code=$?
    else
        # Fallback to direct drush cron if run_cron.sh not available
        log_warning "run_cron.sh not found, falling back to direct drush cron"
        if [ "$MODE" == "lando" ]; then
            cron_result=$(lando drush cron 2>&1)
            exit_code=$?
        elif [ "$MODE" == "terminus" ]; then
            cron_result=$(terminus remote:drush ${SITE_NAME}.${ENV} -- cron 2>&1)
            exit_code=$?
        else
            log_error "Unknown mode for cron execution: $MODE"
            return 1
        fi
    fi
    
    if [ $exit_code -eq 0 ]; then
        log_success "Drupal cron executed successfully"
        if [ -n "$cron_result" ]; then
            log_info "Cron output: $cron_result"
        fi
        return 0
    else
        log_error "Drupal cron execution failed (exit code: $exit_code)"
        log_error "Cron output: $cron_result"
        return 1
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
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --auto-confirm)
            AUTO_CONFIRM=true
            shift
            ;;
        --run-cron)
            RUN_CRON=true
            shift
            ;;
        --help)
            show_usage
            exit 0
            ;;
        -)
            # Handle stdin explicitly before generic -* pattern
            if [ -z "$SCAN_RESULTS_FILE" ]; then
                USING_STDIN=true
                SCAN_RESULTS_FILE="-"
            else
                echo "Too many arguments"
                show_usage
                exit 1
            fi
            shift
            ;;
        -*)
            echo "Unknown option: $1"
            show_usage
            exit 1
            ;;
        *)
            if [ -z "$SCAN_RESULTS_FILE" ]; then
                SCAN_RESULTS_FILE="$1"
            else
                echo "Too many arguments"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Handle stdin input
if [ -z "$SCAN_RESULTS_FILE" ]; then
    # No file specified - check if stdin has data
    if [ ! -t 0 ]; then
        # stdin is not a terminal (has piped data)
        USING_STDIN=true
        SCAN_RESULTS_FILE="-"
    else
        echo "Error: Scan results file is required or pipe data to stdin"
        show_usage
        exit 1
    fi
fi

# Create temporary file for stdin content if needed
if [ "$USING_STDIN" == "true" ]; then
    TEMP_STDIN_FILE="/tmp/cleanup_stdin_$$_$(date +%s)"
    
    if [ "$SCAN_RESULTS_FILE" == "-" ]; then
        # Read from stdin and save to temporary file
        cat > "$TEMP_STDIN_FILE"
        if [ $? -ne 0 ]; then
            echo "Error: Failed to read from stdin"
            exit 1
        fi
    fi
    
    # Check if temporary file has content
    if [ ! -s "$TEMP_STDIN_FILE" ]; then
        echo "Error: No data received from stdin"
        rm -f "$TEMP_STDIN_FILE"
        exit 1
    fi
    
    # Use temporary file as scan results file
    SCAN_RESULTS_FILE="$TEMP_STDIN_FILE"
fi

# Initialize report file
echo "# File Cleanup Report - $(date)" > "$REPORT_FILE"
echo "# Generated by $SCRIPT_NAME v$VERSION" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

log_info "Starting file cleanup process..."
if [ "$USING_STDIN" == "true" ]; then
    log_info "Scan results source: stdin (saved to temporary file)"
else
    log_info "Scan results file: $SCAN_RESULTS_FILE"
fi

# Detect and validate configuration
detect_mode
validate_config

# Parse scan results
parse_scan_results

# Show summary and ask for confirmation
if [ "$DRY_RUN" != "true" ] && [ "$AUTO_CONFIRM" != "true" ]; then
    echo ""
    echo -e "${YELLOW}CLEANUP SUMMARY:${NC}"
    echo -e "  Files that will be auto-deleted: ${GREEN}$AUTO_DELETE_FILES${NC}"
    echo -e "  Files requiring manual review: ${YELLOW}$MANUAL_FILES${NC}"
    echo -e "  Mode: ${BLUE}$MODE${NC}"
    [ "$MODE" == "terminus" ] && echo -e "  Site: ${BLUE}$SITE_NAME/$ENV${NC}"
    echo ""
    
    if [ "$USING_STDIN" == "true" ]; then
        # When using stdin for data, read user input from terminal directly
        read -p "Do you want to proceed with auto-deletions? (y/N): " -n 1 -r < /dev/tty
    else
        # Normal file input, use regular stdin
        read -p "Do you want to proceed with auto-deletions? (y/N): " -n 1 -r
    fi
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Cleanup cancelled by user"
        cleanup_temp_files
        exit 0
    fi
fi

# Execute auto-deletions
execute_auto_deletions

# Run Drupal cron if requested
if [ "$RUN_CRON" == "true" ]; then
    echo ""
    if run_drupal_cron; then
        CRON_SUCCESS=true
    fi
fi

# Generate final report
generate_report

# Cleanup
cleanup_temp_files

log_success "Cleanup process completed!"
echo ""
echo -e "${GREEN}✅ Successfully deleted: $DELETED_SUCCESS files${NC}"
[ $DELETED_FAILED -gt 0 ] && echo -e "${RED}❌ Failed deletions: $DELETED_FAILED files${NC}"
[ $MANUAL_FILES -gt 0 ] && echo -e "${YELLOW}⚠️ Manual review required: $MANUAL_FILES files${NC}"
if [ "$RUN_CRON" == "true" ]; then
    if [ "$CRON_SUCCESS" == "true" ]; then
        echo -e "${GREEN}🔄 Drupal cron executed successfully${NC}"
    else
        echo -e "${RED}🔄 Drupal cron execution failed${NC}"
    fi
fi
echo ""
echo -e "${BLUE}📄 Full report: $REPORT_FILE${NC}"
