# Drupal File Deletion Scripts

A comprehensive suite of Unix-philosophy shell scripts for diagnosing and cleaning up orphaned files in Drupal installations. The scripts work with both local Lando environments and remote Pantheon hosting via Terminus CLI.

## Architecture

### Script Types and Workflow

**Diagnostic Scripts (Analysis Phase):**
- `diagnose_files.sh` - Main bulk file analyzer with DOM verification (v11)

**Cleanup Scripts (Execution Phase):**
- `cleanup_files.sh` - Automated cleanup executor for bulk operations
- `run_cron.sh` - Drupal cron execution for temporary file processing

**NOTE:** This directory contains only the core production scripts. CLAUDE.md documents the complete suite that also includes single-file utilities, domain testing, and verification scripts located elsewhere in the project.

## Prerequisites

### Required Software

**For Lando Mode (Local Development):**
- Lando with Docker (3GB+ memory required)
- Running Lando environment (`lando start`)
- Database connectivity via `lando mysql`

**For Terminus Mode (Pantheon Remote):**
- Terminus CLI installed and configured
- Authenticated Terminus session (`terminus auth:login`)
- Valid Pantheon site access permissions

**Common Requirements:**
- Bash shell (version 4.0+)
- Standard Unix utilities: `grep`, `sed`, `cut`, `curl`, `find`
- Network connectivity for DOM verification
- Proper database permissions for file_managed and file_usage tables

### Installation

1. **Clone or download the scripts** to your Drupal project directory
2. **Make scripts executable:**
   ```bash
   chmod +x *.sh
   ```
3. **Verify environment setup:**
   ```bash
   # For Lando
   lando start
   lando mysql -e "SELECT COUNT(*) FROM pantheon.file_managed;"
   
   # For Terminus
   terminus auth:login
   terminus site:info your-site-name
   ```

### Configuration

All scripts now use a consistent command-line interface with intelligent auto-detection, eliminating the need to modify script files or set environment variables.

#### Method 1: Auto-Detection (Recommended)

Scripts automatically detect your environment with sane defaults:

```bash
# Auto-detects Lando or Terminus, defaults to Lando
./diagnose_files.sh files_to_check.txt

# All scripts use the same auto-detection
./cleanup_files.sh scan_results.log
./run_cron.sh
./test_domain_detection.sh
```

#### Method 2: Explicit Mode Selection

Override auto-detection with command-line parameters:

```bash
# Force Lando mode
./diagnose_files.sh --mode lando files_to_check.txt
./cleanup_files.sh --mode lando scan_results.log

# Force Terminus mode with site parameters
./diagnose_files.sh --mode terminus --site mysite --env dev files_to_check.txt
./cleanup_files.sh --mode terminus --site mysite --env dev scan_results.log
```

#### Method 3: Legacy Environment Variables (Still Supported)

```bash
# Set environment variables for the session (optional)
export MODE="terminus"
export SITE_NAME="ys-your-yale-edu" 
export ENV="filedeltest"

# Scripts will use these if no CLI flags provided
./diagnose_files.sh files_to_check.txt
./cleanup_files.sh scan_results.log
```

#### Available Parameters (All Scripts)

**Common Parameters:**
- `--mode <lando|terminus>`: Force execution mode (auto-detected if not specified)
- `--site <sitename>`: Pantheon site name (required for terminus mode)  
- `--env <environment>`: Pantheon environment (dev/test/live)
- `--help`: Show help message with script-specific options

**Script-Specific Parameters:**
- `--dry-run`: Preview actions without executing (cleanup_files.sh)
- `--auto-confirm`: Skip confirmation prompts (cleanup_files.sh)
- `--run-cron`: Execute Drupal cron after cleanup (cleanup_files.sh)
- `--verbose`: Show detailed output (run_cron.sh)
- `--timeout <seconds>`: Timeout for operations (run_cron.sh)
- `--output <format>`: Output format: human|json|tsv (run_cron.sh)

**Auto-Detection Logic:**
1. Try to detect Lando environment (checks for .lando.yml and running containers)
2. Fall back to Terminus environment (checks for terminus command)  
3. Default to Lando mode as sane fallback
4. Parse cleanup results files for mode information (where applicable)

## Core Workflow

### Complete Workflow

```bash
# 1. Create file list
echo "report.pdf" > files_to_check.txt
echo "document.docx" >> files_to_check.txt

# 2. Diagnose files (auto-detects environment)
./diagnose_files.sh files_to_check.txt > scan_results.log

# 3. Preview cleanup actions 
./cleanup_files.sh --dry-run scan_results.log

# 4. Execute cleanup
./cleanup_files.sh scan_results.log

# 5. Process temporary files
./run_cron.sh --verbose
```

### Environment-Specific Examples

**Lando (Local Development):**
```bash
# Auto-detects lando mode
./diagnose_files.sh files_to_check.txt > scan_results.log
./cleanup_files.sh --dry-run scan_results.log
./cleanup_files.sh scan_results.log
./run_cron.sh --verbose
```

**Terminus (Pantheon Remote):**
```bash
# Force terminus mode with site parameters
./diagnose_files.sh --mode terminus --site mysite --env dev files_to_check.txt > scan_results.log
./cleanup_files.sh --mode terminus --site mysite --env dev --dry-run scan_results.log
./cleanup_files.sh --mode terminus --site mysite --env dev scan_results.log
./run_cron.sh --mode terminus --site mysite --env dev --verbose
```

## Script Reference

### diagnose_files.sh

**Purpose:** Bulk file analysis with DOM verification for orphaned file detection

**Usage:**
```bash
./diagnose_files.sh [OPTIONS] <files_to_check.txt>
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (auto-detected if not specified)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--help`: Show help message

**Examples:**
```bash
# Auto-detect mode
./diagnose_files.sh files_to_check.txt

# Force lando mode
./diagnose_files.sh --mode lando files_to_check.txt

# Terminus mode
./diagnose_files.sh --mode terminus --site mysite --env dev files_to_check.txt
```

**Input Format:**
Text file with one filename per line (supports both basenames and full paths):
```
report.pdf                                  # Basename - searches all directories
2025-02/document.xlsx                       # Path - targets specific location  
subdirectory/image.jpg                      # Relative path from files directory
```

**Output Categories:**
- **Truly Orphaned Files:** Not in database, exist on filesystem → Direct deletion
- **Unused Files:** In database but no usage records → Set status=0 for cron cleanup
- **Ghost References:** Usage records point to deleted/non-existent entities → SQL cleanup
- **Phantom References:** In database and Layout Builder but not in rendered DOM → SQL cleanup
- **Active Content:** Actually used in published pages → Manual UI removal required

### cleanup_files.sh

**Purpose:** Automated execution of cleanup operations from diagnostic results

**Usage:**
```bash
./cleanup_files.sh [OPTIONS] <scan_results_file>
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (auto-detected if not specified)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--dry-run`: Show what would be deleted without executing
- `--auto-confirm`: Skip confirmation prompts
- `--run-cron`: Run Drupal cron after cleanup
- `--help`: Show help message

**Examples:**
```bash
# Auto-detect mode from scan results
./cleanup_files.sh scan_results.log

# Preview mode
./cleanup_files.sh --dry-run scan_results.log

# Automated execution
./cleanup_files.sh --auto-confirm scan_results.log

# With cron execution
./cleanup_files.sh --run-cron scan_results.log
```

### run_cron.sh

**Purpose:** Execute Drupal cron to process temporary files and scheduled tasks

**Usage:**
```bash
./run_cron.sh [OPTIONS]
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (default: auto-detect)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--dry-run`: Show what would be executed without running
- `--output <format>`: Output format: human|json|tsv (default: human)
- `--timeout <seconds>`: Timeout for cron execution (default: 300)
- `--verbose`: Show detailed cron output
- `--help`: Show help message

**Examples:**
```bash
# Auto-detect environment and run cron
./run_cron.sh

# Force lando mode
./run_cron.sh --mode lando

# Terminus mode with verbose output
./run_cron.sh --mode terminus --site mysite --env dev --verbose

# JSON output for scripting
./run_cron.sh --output json
```

## Environment Support

### Lando Mode (Local Development)

**Auto-Detection Criteria:**
- Presence of `.lando.yml` or `.lando.local.yml` file
- `lando` command available and responsive
- Active Lando containers (`lando info` succeeds)

**Usage:**
```bash
# Auto-detected when criteria met
./diagnose_files.sh files_to_check.txt

# Force lando mode explicitly
./cleanup_files.sh --mode lando scan_results.log
```

**Requirements:**
- Active Lando environment (`lando start`)
- `.lando.local.yml` with proper DRUSH_OPTIONS_URI (recommended)
- Database connectivity via `lando mysql`

**Domain Detection Methods:**
1. `.lando.local.yml` DRUSH_OPTIONS_URI setting
2. Constructed from Lando project name
3. `lando info` JSON parsing

### Terminus Mode (Pantheon Remote)

**Auto-Detection Criteria:**
- `terminus` command available
- No active Lando environment detected

**Usage:**
```bash
# Auto-detected when terminus available (requires --site and --env)
./diagnose_files.sh --site mysite --env dev files_to_check.txt

# Force terminus mode explicitly  
./cleanup_files.sh --mode terminus --site mysite --env dev scan_results.log
```

**Requirements:**
- Authenticated Terminus CLI session (`terminus auth:login`)
- Valid site and environment access
- Network connectivity to Pantheon
- Site name and environment specified for most operations

**Domain Detection Methods:**
1. `terminus domain:list` API call
2. `terminus env:info` environment data
3. Standard pantheonsite.io URL pattern (`https://ENV-SITE.pantheonsite.io`)

## File Diagnosis Categories

### Truly Orphaned Files
- **Status:** Not in database, exist on filesystem  
- **Action:** Direct filesystem deletion
- **Safety:** Very safe - no database references

### Unused Files  
- **Status:** In database but no usage records
- **Action:** Set status=0, let Drupal cron handle cleanup
- **Safety:** Safe - Drupal-native cleanup process

### Ghost References
- **Status:** Usage records point to deleted/non-existent entities
- **Action:** SQL cleanup of stale records
- **Safety:** Safe - references point to nothing

### Phantom References
- **Status:** In database and Layout Builder but not in rendered DOM  
- **Action:** SQL cleanup after DOM verification
- **Safety:** Generally safe - not actually displayed

### Active Content
- **Status:** Actually used in published pages
- **Action:** Manual UI removal required
- **Safety:** Manual only - automated deletion would break content

## File Analysis Features

The `diagnose_files.sh` script (v11) includes advanced analysis capabilities:

**Multi-Environment Support:**
- **Lando Mode:** Local development with automatic .lando.local.yml detection
- **Terminus Mode:** Remote Pantheon environments with API integration
- **Auto-Detection:** Scripts automatically detect environment when possible

**DOM Verification System:**
- Fetches actual rendered pages using curl
- Verifies files actually appear in DOM content vs. database references
- Identifies "phantom references" (database entries without rendered usage)
- Handles URL encoding, filename variations, and case-insensitive matching

**Database Analysis:**
- Comprehensive file_managed and file_usage table analysis
- Layout Builder entity relationship detection with PHP serialized data parsing
- Cross-references with actual block content to verify file existence
- Handles nested paragraphs and complex entity relationships

**Smart Classification:**
- Distinguishes between truly orphaned files and stale references
- Identifies files safe for automatic cleanup vs. manual review
- Supports both basename and path-specific file targeting
- Conservative fallbacks for uncertain cases

**Safety Features:**
- **Dry-run modes** for all destructive operations
- **Transactional SQL** with BEGIN/COMMIT for database safety
- **Drupal-native cleanup** using status=0 and cron processing
- **Conservative fallbacks** for uncertain cases

## Database Operations

### Safety Features
- Transactional SQL with BEGIN/COMMIT
- Smart verification before status updates
- Only marks files for deletion when removing last reference
- Drupal-native cleanup preferred over direct deletion

### SQL Command Patterns

**Individual File Cleanup:**
```sql
BEGIN; 
DELETE FROM pantheon.file_usage WHERE fid = X AND type = 'block_content' AND id = Y; 
SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = X); 
UPDATE pantheon.file_managed SET status = 0 WHERE fid = X AND @remaining = 0; 
COMMIT;
```

**Consolidated Cleanup Transaction:**
```sql
BEGIN; 
DELETE FROM pantheon.file_usage WHERE fid = X AND type = 'block_content' AND id = Y;
DELETE FROM pantheon.file_usage WHERE fid = X AND type = 'block_content' AND id = Z;
SET @remaining = (SELECT COUNT(*) FROM pantheon.file_usage WHERE fid = X); 
UPDATE pantheon.file_managed SET status = 0 WHERE fid = X AND @remaining = 0; 
COMMIT;
```

## Layout Builder Detection

The scripts include sophisticated Layout Builder analysis:

- Parses PHP serialized data in `layout_builder__layout_section` fields
- Identifies block_content entities used in published Layout Builder pages
- Cross-references with actual block content to verify file existence
- Handles nested paragraphs and complex entity relationships
- Verifies actual DOM usage vs. database references

## Error Handling

### Exit Codes
- **0:** Success
- **1:** Execution/analysis error  
- **2:** Database/connection error
- **3:** Invalid arguments

### Safety Features
- Database connectivity verification before destructive operations
- Dry-run modes for all scripts
- Interactive confirmation prompts
- Comprehensive logging and error reporting
- Graceful error recovery and cleanup

## Troubleshooting

### Common Issues

**Auto-Detection Issues:**
```bash
# Test environment auto-detection
./diagnose_files.sh --help  # Check available options
./cleanup_files.sh --help   # Verify CLI flags

# Check Lando environment
lando info
cat .lando.local.yml | grep DRUSH_OPTIONS_URI

# Check Terminus authentication  
terminus auth:whoami
terminus site:list
```

**Domain Detection Failures:**
```bash
# Test mode detection with actual scripts
./diagnose_files.sh --mode lando files_to_check.txt
./diagnose_files.sh --mode terminus --site mysite --env dev files_to_check.txt

# Check Lando configuration - DOM verification uses these methods:
# 1) .lando.local.yml DRUSH_OPTIONS_URI setting
# 2) Constructed from Lando project name (.lando.yml)
# 3) lando info JSON parsing for edge service URLs
cat .lando.local.yml | grep DRUSH_OPTIONS_URI
lando info

# Verify Lando is running
lando start
```

**DOM Verification Issues:**
```bash
# Check if base URL is accessible
curl --max-time 10 "https://your-site.lndo.site"
curl --max-time 10 "https://dev-yoursite.pantheonsite.io"

# Test with DOM verification disabled
# (Edit diagnose_files.sh and set ENABLE_DOM_VERIFICATION=false)
```

**Database Connection Issues:**
```bash
# Test with explicit mode
./run_cron.sh --mode lando --dry-run
./run_cron.sh --mode terminus --site mysite --env dev --dry-run

# Manual connection testing
lando mysql -e "SELECT 1;"  # Lando mode
terminus sql:query site.env "SELECT 1;" --raw  # Terminus mode
```

**Cleanup Issues:**
```bash
# Test cleanup with dry-run first
./cleanup_files.sh --dry-run scan_results.log

# Check for specific error patterns in output
./cleanup_files.sh scan_results.log 2>&1 | grep -i error

# Test basic connectivity
curl --max-time 10 "https://your-site.lndo.site"
```

**Permission Errors:**
```bash
# Check file permissions
ls -la web/sites/default/files/

# Verify database access
lando mysql -e "SHOW GRANTS;"
```

### Performance Considerations

- DOM verification adds significant time (0.5s per file minimum)
- Large file lists benefit from `--dry-run` testing first
- Conservative mode reduces false positives but increases manual review
- Batch operations are more efficient than individual file processing
- Consolidated transactions reduce database load for multi-reference files

## Best Practices

### Workflow Recommendations

1. **Always start with diagnosis:**
   ```bash
   ./diagnose_files.sh files.txt > results.log
   ```

2. **Review results before cleanup:**
   ```bash
   grep "COMMAND\|ACTION" results.log | head -20
   ```

3. **Use dry-run mode first:**
   ```bash
   ./cleanup_files.sh --dry-run results.log
   ```

4. **Process temporary files:**
   ```bash
   ./run_cron.sh --verbose
   ```

### Safety Guidelines

- Always backup database before bulk operations
- Test on development environments first
- Use `--dry-run` extensively during development
- Review manual action items carefully
- Monitor disk space during cleanup operations

### Monitoring

**Track cleanup progress:**
```bash
# Count temporary files
lando mysql -e "SELECT COUNT(*) FROM pantheon.file_managed WHERE status = 0;"

# Monitor cron execution
./run_cron.sh --verbose --output json

# Check for phantom references detected by DOM verification
grep "PHANTOM REFERENCE\|👻" scan_results.log

# Review DOM verification results
grep "DOM verification\|TRULY ACTIVE" scan_results.log
```

**Audit cleanup results:**
```bash
# Review successful operations
grep "Successfully" cleanup_report.log

# Check failed operations
grep "Failed\|Error" cleanup_report.log

# Check consolidated transactions
grep "🔄.*consolidated cleanup" scan_results.log
```

## Output Formats

The scripts provide detailed, readable output for analysis and debugging:

### Human-Readable Format (Default)
- Color-coded console output
- Detailed explanations and recommendations
- Interactive prompts and confirmations
- Comprehensive reports with statistics

### Alternative Formats
The `run_cron.sh` script supports additional output formats:
```bash
./run_cron.sh --output json    # Machine-readable JSON
./run_cron.sh --output tsv     # Tab-separated values
```

## Integration Examples

### Pipeline Processing
```bash
# Full automated pipeline
./diagnose_files.sh files.txt | \
  ./cleanup_files.sh --auto-confirm - && \
  ./run_cron.sh --verbose
```

### Scripting Integration
```bash
#!/bin/bash
# Automated cleanup with reporting

SCAN_RESULTS="scan_$(date +%Y%m%d_%H%M%S).log"
./diagnose_files.sh files.txt > "$SCAN_RESULTS"

if ./cleanup_files.sh --dry-run "$SCAN_RESULTS" | grep -q "No operations"; then
  echo "No cleanup needed"
else
  ./cleanup_files.sh --auto-confirm "$SCAN_RESULTS"
  ./run_cron.sh --output json > cron_results.json
fi
```

### Monitoring Integration
```bash
# Cron job for regular cleanup
0 2 * * 0 cd /path/to/scripts && ./run_cron.sh --output json >> /var/log/drupal_cleanup.log
```

This comprehensive suite provides robust, safe, and efficient file cleanup capabilities for Drupal installations across different hosting environments while maintaining data integrity and providing detailed audit trails.