#!/bin/bash

# ==============================================================================
# Single File Cleanup Utility (v1.0)
#
# Executes a single cleanup action on a file based on diagnosis results.
# Designed to follow Unix philosophy: do one thing and do it well.
#
# DESCRIPTION:
# This utility executes specific cleanup actions on individual files:
# - Direct file deletion (rm/unlink)
# - Database status updates (set status = 0)
# - File usage record cleanup
# - SQL command execution
#
# USAGE:
#   ./cleanup_single_file.sh [OPTIONS] <filename> <action> [action_params]
#
# OPTIONS:
#   --mode <lando|terminus>     Force execution mode (default: lando)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --dry-run                  Show what would be executed without running
#   --output <format>          Output format: human|json|tsv (default: human)
#   --help                     Show this help message
#
# ACTIONS:
#   rm-file                    Delete file directly from filesystem
#   set-status-0 <fid>         Set file_managed status = 0 for given FID
#   delete-usage <fid> <type> <id>  Delete specific file_usage record
#   sql-command "<command>"    Execute custom SQL command
#   file-command "<command>"   Execute custom file system command
#
# EXAMPLES:
#   # Delete orphaned file
#   ./cleanup_single_file.sh report.pdf rm-file
#
#   # Set file status to temporary
#   ./cleanup_single_file.sh document.docx set-status-0 1234
#
#   # Delete specific usage record
#   ./cleanup_single_file.sh image.jpg delete-usage 1234 block_content 5678
#
#   # Custom SQL command
#   ./cleanup_single_file.sh file.pdf sql-command "UPDATE pantheon.file_managed SET status = 0 WHERE fid = 1234;"
#
#   # Dry run mode
#   ./cleanup_single_file.sh --dry-run report.pdf rm-file
#
# EXIT CODES:
#   0: Success
#   1: Execution error
#   2: Database connection error
#   3: Invalid arguments
#
# ==============================================================================

# --- CONFIGURATION ---
MODE=""
SITE_NAME=""
ENV=""
FILENAME=""
ACTION=""
ACTION_PARAMS=()
DRY_RUN=false
OUTPUT_FORMAT="human"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- FUNCTIONS ---

show_usage() {
    cat << EOF
Usage: $0 [OPTIONS] <filename> <action> [action_params]

Single file cleanup utility for Drupal file operations.

OPTIONS:
  --mode <lando|terminus>     Force execution mode (default: lando)
  --site <sitename>          Pantheon site name (required for terminus mode)
  --env <environment>        Pantheon environment (required for terminus mode)
  --dry-run                  Show what would be executed without running
  --output <format>          Output format: human|json|tsv (default: human)
  --help                     Show this help message

ACTIONS:
  rm-file                        Delete file directly from filesystem
  set-status-0 <fid>             Set file_managed status = 0 for given FID
  delete-usage <fid> <type> <id> Delete specific file_usage record
  sql-command "<command>"        Execute custom SQL command
  file-command "<command>"       Execute custom file system command

EXAMPLES:
  $0 report.pdf rm-file
  $0 document.docx set-status-0 1234
  $0 image.jpg delete-usage 1234 block_content 5678
  $0 --dry-run report.pdf rm-file

EXIT CODES:
  0: Success, 1: Execution error, 2: Database error, 3: Invalid arguments
EOF
}

# Execute SQL query based on mode
run_sql() {
    local query="$1"
    local result=""
    local exit_code=0
    
    if [ "$DRY_RUN" == "true" ]; then
        echo "[DRY RUN] Would execute SQL: $query"
        return 0
    fi
    
    if [ "$MODE" == "lando" ]; then
        result=$(lando mysql -e "$query" 2>&1)
        exit_code=$?
    elif [ "$MODE" == "terminus" ]; then
        result=$(echo "$query" | terminus drush "${SITE_NAME}.${ENV}" sql:cli 2>&1)
        exit_code=$?
    else
        echo "Error: Unknown mode: $MODE" >&2
        return 1
    fi
    
    if [ $exit_code -eq 0 ]; then
        echo "SQL executed successfully: $query"
        return 0
    else
        echo "SQL execution failed: $result" >&2
        return 1
    fi
}

# Execute file system command
run_file_command() {
    local command="$1"
    local result=""
    local exit_code=0
    
    if [ "$DRY_RUN" == "true" ]; then
        echo "[DRY RUN] Would execute file command: $command"
        return 0
    fi
    
    result=$(eval "$command" 2>&1)
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
        echo "File command executed successfully: $command"
        if [ -n "$result" ]; then
            echo "Output: $result"
        fi
        return 0
    else
        echo "File command failed: $result" >&2
        return 1
    fi
}

# Delete file directly from filesystem
action_rm_file() {
    local filename="$1"
    
    if [ "$MODE" == "lando" ]; then
        local command="find web/sites/default/files -name '${filename}' -type f -print0 | while IFS= read -r -d '' file; do if rm \"\$file\" 2>/dev/null; then echo \"Successfully deleted: \$(basename \"\$file\")\"; else echo \"Error deleting: \$(basename \"\$file\")\"; fi; done"
        run_file_command "$command"
    elif [ "$MODE" == "terminus" ]; then
        local command="terminus remote:drush ${SITE_NAME}.${ENV} -- eval \"\\\$files = glob(DRUPAL_ROOT . '/sites/default/files/**/${filename}'); \\\$deleted = 0; foreach(\\\$files as \\\$file) { if(file_exists(\\\$file)) { if(is_writable(\\\$file)) { if(unlink(\\\$file)) { echo 'Successfully deleted: ' . basename(\\\$file); \\\$deleted++; } else { echo 'Error deleting: ' . basename(\\\$file) . ' - ' . (error_get_last()['message'] ?? 'Unknown error'); } } else { echo 'Permission denied: ' . basename(\\\$file); } } else { echo 'File not found: ' . basename(\\\$file); } } if(\\\$deleted === 0 && empty(\\\$files)) { echo 'No files found matching: ${filename}'; }\""
        run_file_command "$command"
    else
        echo "Error: Unknown mode for file deletion: $MODE" >&2
        return 1
    fi
}

# Set file_managed status = 0
action_set_status_0() {
    local fid="$1"
    
    if [ -z "$fid" ] || ! [[ "$fid" =~ ^[0-9]+$ ]]; then
        echo "Error: Invalid FID: $fid" >&2
        return 1
    fi
    
    local query="UPDATE pantheon.file_managed SET status = 0 WHERE fid = ${fid};"
    run_sql "$query"
}

# Delete specific file_usage record
action_delete_usage() {
    local fid="$1"
    local type="$2"
    local id="$3"
    
    if [ -z "$fid" ] || [ -z "$type" ] || [ -z "$id" ]; then
        echo "Error: delete-usage requires fid, type, and id parameters" >&2
        return 1
    fi
    
    if ! [[ "$fid" =~ ^[0-9]+$ ]] || ! [[ "$id" =~ ^[0-9]+$ ]]; then
        echo "Error: FID and ID must be numeric" >&2
        return 1
    fi
    
    local query="DELETE FROM pantheon.file_usage WHERE fid = ${fid} AND type = '${type}' AND id = ${id};"
    run_sql "$query"
}

# Execute custom SQL command
action_sql_command() {
    local command="$1"
    
    if [ -z "$command" ]; then
        echo "Error: SQL command cannot be empty" >&2
        return 1
    fi
    
    run_sql "$command"
}

# Execute custom file command
action_file_command() {
    local command="$1"
    
    if [ -z "$command" ]; then
        echo "Error: File command cannot be empty" >&2
        return 1
    fi
    
    run_file_command "$command"
}

# Main cleanup function
execute_cleanup() {
    local filename="$1"
    local action="$2"
    shift 2
    local params=("$@")
    
    local result=()
    result+=("filename=$filename")
    result+=("action=$action")
    result+=("mode=$MODE")
    result+=("dry_run=$DRY_RUN")
    
    case "$action" in
        "rm-file")
            if action_rm_file "$filename"; then
                result+=("status=success")
                result+=("message=File deleted from filesystem")
            else
                result+=("status=error")
                result+=("message=Failed to delete file")
                return 1
            fi
            ;;
        "set-status-0")
            if [ ${#params[@]} -ne 1 ]; then
                result+=("status=error")
                result+=("message=set-status-0 requires exactly one parameter (fid)")
                return 1
            fi
            if action_set_status_0 "${params[0]}"; then
                result+=("status=success")
                result+=("message=File status set to temporary")
                result+=("fid=${params[0]}")
            else
                result+=("status=error")
                result+=("message=Failed to update file status")
                return 1
            fi
            ;;
        "delete-usage")
            if [ ${#params[@]} -ne 3 ]; then
                result+=("status=error")
                result+=("message=delete-usage requires exactly three parameters (fid, type, id)")
                return 1
            fi
            if action_delete_usage "${params[0]}" "${params[1]}" "${params[2]}"; then
                result+=("status=success")
                result+=("message=File usage record deleted")
                result+=("fid=${params[0]}")
                result+=("usage_type=${params[1]}")
                result+=("usage_id=${params[2]}")
            else
                result+=("status=error")
                result+=("message=Failed to delete usage record")
                return 1
            fi
            ;;
        "sql-command")
            if [ ${#params[@]} -ne 1 ]; then
                result+=("status=error")
                result+=("message=sql-command requires exactly one parameter (SQL command)")
                return 1
            fi
            if action_sql_command "${params[0]}"; then
                result+=("status=success")
                result+=("message=SQL command executed")
                result+=("sql_command=${params[0]}")
            else
                result+=("status=error")
                result+=("message=Failed to execute SQL command")
                return 1
            fi
            ;;
        "file-command")
            if [ ${#params[@]} -ne 1 ]; then
                result+=("status=error")
                result+=("message=file-command requires exactly one parameter (file command)")
                return 1
            fi
            if action_file_command "${params[0]}"; then
                result+=("status=success")
                result+=("message=File command executed")
                result+=("file_command=${params[0]}")
            else
                result+=("status=error")
                result+=("message=Failed to execute file command")
                return 1
            fi
            ;;
        *)
            result+=("status=error")
            result+=("message=Unknown action: $action")
            return 1
            ;;
    esac
    
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
            echo "🧹 Cleaning up file: $FILENAME"
            for item in "${data[@]}"; do
                local key="${item%%=*}"
                local value="${item#*=}"
                case "$key" in
                    "status")
                        if [ "$value" == "success" ]; then
                            echo -e "  ${GREEN}STATUS:${NC} $value"
                        else
                            echo -e "  ${RED}STATUS:${NC} $value"
                        fi
                        ;;
                    "message")
                        echo -e "  ${BLUE}RESULT:${NC} $value"
                        ;;
                    "action")
                        echo -e "  ${YELLOW}ACTION:${NC} $value"
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
        --dry-run)
            DRY_RUN=true
            shift
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
            elif [ -z "$ACTION" ]; then
                ACTION="$1"
            else
                ACTION_PARAMS+=("$1")
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

if [ -z "$ACTION" ]; then
    echo "Error: Action is required" >&2
    show_usage >&2
    exit 3
fi

if [ "$MODE" == "terminus" ] && ([ -z "$SITE_NAME" ] || [ -z "$ENV" ]); then
    echo "Error: Terminus mode requires --site and --env parameters" >&2
    exit 3
fi

# Test database connectivity for database actions
case "$ACTION" in
    "set-status-0"|"delete-usage"|"sql-command")
        if [ "$MODE" == "lando" ]; then
            if ! lando mysql -e "SELECT 1;" >/dev/null 2>&1; then
                echo "Error: Cannot connect to Lando database. Is Lando running?" >&2
                exit 2
            fi
        elif [ "$MODE" == "terminus" ]; then
            if ! echo "SELECT 1" | terminus drush "${SITE_NAME}.${ENV}" sql:cli >/dev/null 2>&1; then
                echo "Error: Cannot connect to Terminus database. Check site/env parameters." >&2
                exit 2
            fi
        fi
        ;;
esac

# Execute cleanup action
cleanup_result=$(execute_cleanup "$FILENAME" "$ACTION" "${ACTION_PARAMS[@]}")
cleanup_exit_code=$?

if [ $cleanup_exit_code -ne 0 ]; then
    echo "Error: Failed to execute cleanup action" >&2
    exit 1
fi

# Convert result to array
IFS=$'\n' read -d '' -r -a result_array <<< "$cleanup_result"

# Format and output result
format_output "$OUTPUT_FORMAT" "${result_array[@]}"

exit $cleanup_exit_code