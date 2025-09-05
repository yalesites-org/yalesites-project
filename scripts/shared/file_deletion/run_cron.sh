#!/bin/bash

# ==============================================================================
# Drupal Cron Execution Utility (v1.0)
#
# Executes Drupal cron to process temporary files and other scheduled tasks.
# Designed to follow Unix philosophy: do one thing and do it well.
#
# DESCRIPTION:
# This utility executes Drupal's cron system which handles:
# - Temporary file cleanup (status = 0 files)
# - Scheduled task processing
# - Cache clearing and maintenance
# - Module-specific cron tasks
#
# USAGE:
#   ./run_cron.sh [OPTIONS]
#
# OPTIONS:
#   --mode <lando|terminus>     Force execution mode (default: auto-detect)
#   --site <sitename>          Pantheon site name (required for terminus mode)
#   --env <environment>        Pantheon environment (required for terminus mode)
#   --dry-run                  Show what would be executed without running
#   --output <format>          Output format: human|json|tsv (default: human)
#   --timeout <seconds>        Timeout for cron execution (default: 300)
#   --verbose                  Show detailed cron output
#   --help                     Show this help message
#
# EXAMPLES:
#   # Auto-detect environment and run cron
#   ./run_cron.sh
#
#   # Force lando mode
#   ./run_cron.sh --mode lando
#
#   # Terminus mode with verbose output
#   ./run_cron.sh --mode terminus --site mysite --env dev --verbose
#
#   # Dry run to see what would be executed
#   ./run_cron.sh --dry-run
#
#   # JSON output for scripting
#   ./run_cron.sh --output json
#
# EXIT CODES:
#   0: Success
#   1: Cron execution failed
#   2: Environment detection/connection error
#   3: Invalid arguments
#
# ==============================================================================

# --- CONFIGURATION ---
MODE=""
SITE_NAME=""
ENV=""
DRY_RUN=false
OUTPUT_FORMAT="human"
TIMEOUT=300
VERBOSE=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- FUNCTIONS ---

show_usage() {
    cat << EOF
Usage: $0 [OPTIONS]

Drupal cron execution utility for file cleanup and maintenance tasks.

OPTIONS:
  --mode <lando|terminus>     Force execution mode (default: auto-detect)
  --site <sitename>          Pantheon site name (required for terminus mode)
  --env <environment>        Pantheon environment (required for terminus mode)
  --dry-run                  Show what would be executed without running
  --output <format>          Output format: human|json|tsv (default: human)
  --timeout <seconds>        Timeout for cron execution (default: 300)
  --verbose                  Show detailed cron output
  --help                     Show this help message

EXAMPLES:
  $0                                              # Auto-detect and run
  $0 --mode lando                                 # Force lando mode
  $0 --mode terminus --site mysite --env dev     # Terminus mode
  $0 --dry-run --verbose                          # Preview with details
  $0 --output json                                # JSON output

EXIT CODES:
  0: Success, 1: Cron failed, 2: Connection error, 3: Invalid arguments
EOF
}

# Auto-detect execution mode
detect_mode() {
    # Check for Lando environment
    if [ -f ".lando.local.yml" ] && command -v lando >/dev/null 2>&1; then
        if lando info >/dev/null 2>&1; then
            echo "lando"
            return 0
        fi
    fi
    
    # Check for Terminus environment
    if command -v terminus >/dev/null 2>&1; then
        if terminus auth:whoami >/dev/null 2>&1; then
            echo "terminus"
            return 0
        fi
    fi
    
    # Default to lando if nothing detected
    echo "lando"
    return 0
}

# Test environment connectivity
test_connectivity() {
    local mode="$1"
    
    if [ "$mode" == "lando" ]; then
        if ! lando mysql -e "SELECT 1;" >/dev/null 2>&1; then
            echo "Error: Cannot connect to Lando database. Is Lando running?" >&2
            return 1
        fi
    elif [ "$mode" == "terminus" ]; then
        if [ -z "$SITE_NAME" ] || [ -z "$ENV" ]; then
            echo "Error: Terminus mode requires --site and --env parameters" >&2
            return 1
        fi
        if ! terminus remote:drush ${SITE_NAME}.${ENV} -- status >/dev/null 2>&1; then
            echo "Error: Cannot connect to Terminus site. Check site/env parameters." >&2
            return 1
        fi
    else
        echo "Error: Unknown mode: $mode" >&2
        return 1
    fi
    
    return 0
}

# Execute Drupal cron
execute_cron() {
    local mode="$1"
    local result_data=()
    local cron_output=""
    local exit_code=0
    local start_time=$(date +%s)
    
    result_data+=("mode=$mode")
    result_data+=("dry_run=$DRY_RUN")
    result_data+=("start_time=$(date -r $start_time)")
    
    if [ "$DRY_RUN" == "true" ]; then
        result_data+=("status=dry_run")
        if [ "$mode" == "lando" ]; then
            result_data+=("command=lando drush cron")
        elif [ "$mode" == "terminus" ]; then
            result_data+=("command=terminus remote:drush ${SITE_NAME}.${ENV} -- cron")
        fi
        result_data+=("message=Dry run - cron not executed")
        printf "%s\n" "${result_data[@]}"
        return 0
    fi
    
    # Execute cron based on mode
    if [ "$mode" == "lando" ]; then
        result_data+=("command=lando drush cron")
        if [ "$VERBOSE" == "true" ]; then
            cron_output=$(timeout $TIMEOUT lando drush cron -v 2>&1)
        else
            cron_output=$(timeout $TIMEOUT lando drush cron 2>&1)
        fi
        exit_code=$?
    elif [ "$mode" == "terminus" ]; then
        result_data+=("command=terminus remote:drush ${SITE_NAME}.${ENV} -- cron")
        result_data+=("site=$SITE_NAME")
        result_data+=("environment=$ENV")
        if [ "$VERBOSE" == "true" ]; then
            cron_output=$(timeout $TIMEOUT terminus remote:drush ${SITE_NAME}.${ENV} -- cron -v 2>&1)
        else
            cron_output=$(timeout $TIMEOUT terminus remote:drush ${SITE_NAME}.${ENV} -- cron 2>&1)
        fi
        exit_code=$?
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    result_data+=("end_time=$(date -r $end_time)")
    result_data+=("duration=${duration}s")
    
    # Handle timeout
    if [ $exit_code -eq 124 ]; then
        result_data+=("status=timeout")
        result_data+=("message=Cron execution timed out after ${TIMEOUT} seconds")
        result_data+=("exit_code=$exit_code")
        printf "%s\n" "${result_data[@]}"
        return 1
    fi
    
    # Handle success/failure
    if [ $exit_code -eq 0 ]; then
        result_data+=("status=success")
        result_data+=("message=Cron executed successfully")
    else
        result_data+=("status=error")
        result_data+=("message=Cron execution failed")
    fi
    
    result_data+=("exit_code=$exit_code")
    
    # Add output if verbose or if there was an error
    if [ "$VERBOSE" == "true" ] || [ $exit_code -ne 0 ]; then
        # Clean up the output and add as multiple lines
        if [ -n "$cron_output" ]; then
            # Replace problematic characters and split into lines
            echo "$cron_output" | while IFS= read -r line; do
                if [ -n "$line" ]; then
                    result_data+=("output_line=$line")
                fi
            done
        fi
    fi
    
    printf "%s\n" "${result_data[@]}"
    return $exit_code
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
            local output_lines=()
            
            for item in "${data[@]}"; do
                local key="${item%%=*}"
                local value="${item#*=}"
                
                if [ "$key" == "output_line" ]; then
                    output_lines+=("$value")
                    continue
                fi
                
                if [ "$first" == "true" ]; then
                    first=false
                else
                    echo ","
                fi
                printf "  \"%s\": \"%s\"" "$key" "$value"
            done
            
            # Add output lines as array if any exist
            if [ ${#output_lines[@]} -gt 0 ]; then
                echo ","
                echo "  \"output\": ["
                local output_first=true
                for line in "${output_lines[@]}"; do
                    if [ "$output_first" == "true" ]; then
                        output_first=false
                    else
                        echo ","
                    fi
                    printf "    \"%s\"" "$line"
                done
                echo ""
                echo "  ]"
            fi
            
            echo ""
            echo "}"
            ;;
        "tsv")
            for item in "${data[@]}"; do
                echo "$item"
            done
            ;;
        "human"|*)
            echo "🔄 Executing Drupal cron..."
            local status=""
            local output_lines=()
            
            for item in "${data[@]}"; do
                local key="${item%%=*}"
                local value="${item#*=}"
                
                case "$key" in
                    "status")
                        status="$value"
                        if [ "$value" == "success" ]; then
                            echo -e "  ${GREEN}STATUS:${NC} $value"
                        elif [ "$value" == "dry_run" ]; then
                            echo -e "  ${YELLOW}STATUS:${NC} $value"
                        else
                            echo -e "  ${RED}STATUS:${NC} $value"
                        fi
                        ;;
                    "message")
                        echo -e "  ${BLUE}RESULT:${NC} $value"
                        ;;
                    "command")
                        echo -e "  ${YELLOW}COMMAND:${NC} $value"
                        ;;
                    "duration")
                        echo -e "  ${BLUE}DURATION:${NC} $value"
                        ;;
                    "output_line")
                        output_lines+=("$value")
                        ;;
                    "mode"|"site"|"environment"|"start_time"|"end_time")
                        echo -e "  ${key}: $value"
                        ;;
                esac
            done
            
            # Show output lines if verbose or error
            if [ ${#output_lines[@]} -gt 0 ] && ([ "$VERBOSE" == "true" ] || [ "$status" != "success" ]); then
                echo -e "  ${BLUE}OUTPUT:${NC}"
                for line in "${output_lines[@]}"; do
                    echo "    $line"
                done
            fi
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
        --timeout)
            TIMEOUT="$2"
            shift 2
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
            echo "Error: Unknown option $1" >&2
            show_usage >&2
            exit 3
            ;;
        *)
            echo "Error: Unexpected argument $1" >&2
            show_usage >&2
            exit 3
            ;;
    esac
done

# Auto-detect mode if not specified
if [ -z "$MODE" ]; then
    MODE=$(detect_mode)
fi

# Validate timeout
if ! [[ "$TIMEOUT" =~ ^[0-9]+$ ]] || [ "$TIMEOUT" -lt 1 ]; then
    echo "Error: Timeout must be a positive integer" >&2
    exit 3
fi

# Test connectivity
if ! test_connectivity "$MODE"; then
    exit 2
fi

# Execute cron
cron_result=$(execute_cron "$MODE")
cron_exit_code=$?

# Convert result to array
IFS=$'\n' read -d '' -r -a result_array <<< "$cron_result"

# Format and output result
format_output "$OUTPUT_FORMAT" "${result_array[@]}"

exit $cron_exit_code