# Drupal File Deletion Scripts

A comprehensive suite of Unix-philosophy shell scripts for diagnosing and cleaning up orphaned files in Drupal installations. The scripts work with both local Lando environments and remote Pantheon hosting via Terminus CLI.

## Architecture

The scripts follow Unix philosophy principles: each tool does one thing well and can be chained together for complex workflows. They provide both bulk operations for large-scale cleanup and individual file utilities for precise control.

### Script Categories

**Diagnostic Scripts (Analysis Phase):**
- `diagnose_files.sh` - Main bulk file analyzer with DOM verification
- `diagnose_single_file.sh` - Single file diagnosis utility  
- `test_domain_detection.sh` - Domain detection testing utility

**Cleanup Scripts (Execution Phase):**
- `cleanup_files.sh` - Automated cleanup executor for bulk operations
- `cleanup_single_file.sh` - Single file cleanup utility
- `run_cron.sh` - Drupal cron execution for temporary file processing

**Verification Scripts (Validation Phase):**
- `verify_dom_usage.sh` - DOM content verification for manual review files
- `verify_manual_actions.sh` - Spot-check verification for specific files

**Demonstration:**
- `unix_workflow_demo.sh` - Example Unix-style single file workflow

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
- `--dry-run`: Preview actions without executing (cleanup scripts)
- `--auto-confirm`: Skip confirmation prompts (cleanup_files.sh)
- `--run-cron`: Execute Drupal cron after cleanup (cleanup_files.sh)
- `--verbose`: Show detailed output (run_cron.sh, verify scripts)
- `--base-url <url>`: Override base URL (verification scripts)
- `--output <format>`: Output format: human|json|tsv (single file scripts)

**Auto-Detection Logic:**
1. Try to detect Lando environment (checks for .lando.yml and running containers)
2. Fall back to Terminus environment (checks for terminus command)  
3. Default to Lando mode as sane fallback
4. Parse cleanup results files for mode information (where applicable)

## Core Workflow

### Complete Bulk Workflow

```bash
# 1. Create file list
echo "report.pdf" > files_to_check.txt
echo "document.docx" >> files_to_check.txt

# 2. Diagnose files (using environment-specific settings)
./diagnose_files.sh files_to_check.txt > scan_results.log

# 3. Preview cleanup actions (auto-detects settings from scan results)
./cleanup_files.sh --dry-run scan_results.log

# 4. Execute cleanup
./cleanup_files.sh scan_results.log

# 5. Process temporary files
./run_cron.sh --verbose
```

### Environment-Specific Workflows

**Lando (Local Development):**
```bash
# Set environment for session
export MODE="lando"

./diagnose_files.sh files_to_check.txt > lando_results.log
./cleanup_files.sh --dry-run lando_results.log
./cleanup_files.sh lando_results.log
```

**Terminus (Pantheon Remote):**
```bash
# Using command-line parameters
./cleanup_files.sh --mode terminus --site ys-your-yale-edu --env filedeltest --dry-run scan_results.log
./cleanup_files.sh --mode terminus --site ys-your-yale-edu --env filedeltest scan_results.log

# Or using environment variables  
export MODE="terminus" SITE_NAME="ys-your-yale-edu" ENV="filedeltest"
./diagnose_files.sh files_to_check.txt > pantheon_results.log
./cleanup_files.sh pantheon_results.log
```

### Unix-Style Single File Workflow

```bash
# 1. Diagnose single file
./diagnose_single_file.sh report.pdf

# 2. Clean up based on diagnosis  
./cleanup_single_file.sh --dry-run report.pdf rm-file

# 3. Execute cleanup
./cleanup_single_file.sh report.pdf rm-file

# 4. Run cron if needed
./run_cron.sh
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
Text file with one filename per line:
```
report.pdf
document.xlsx
image.jpg
```

**Output Categories:**
- **Truly Orphaned Files:** Not in database, safe to delete
- **Unused Files:** In database but no usage records
- **Ghost References:** Stale database records to broken entities
- **Phantom References:** In database but not in rendered DOM
- **Active Content:** Used in published pages, requires manual removal

### diagnose_single_file.sh

**Purpose:** Comprehensive analysis of individual files

**Usage:**
```bash
./diagnose_single_file.sh [OPTIONS] <filename>
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (default: lando)
- `--site <sitename>`: Pantheon site name (required for terminus)
- `--env <environment>`: Pantheon environment (required for terminus)  
- `--enable-dom-verification`: Enable DOM content verification
- `--base-url <url>`: Override base URL for DOM verification
- `--output <format>`: Output format: human|json|tsv (default: human)
- `--help`: Show help message

**Examples:**
```bash
# Basic diagnosis
./diagnose_single_file.sh report.pdf

# With DOM verification
./diagnose_single_file.sh --enable-dom-verification document.docx

# JSON output for scripting
./diagnose_single_file.sh --output json file.xlsx

# Terminus mode
./diagnose_single_file.sh --mode terminus --site mysite --env dev file.pdf
```

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

### cleanup_single_file.sh

**Purpose:** Execute specific cleanup actions on individual files

**Usage:**
```bash
./cleanup_single_file.sh [OPTIONS] <filename> <action> [action_params]
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (default: lando)
- `--site <sitename>`: Pantheon site name (required for terminus)
- `--env <environment>`: Pantheon environment (required for terminus)
- `--dry-run`: Show what would be executed without running
- `--output <format>`: Output format: human|json|tsv (default: human)
- `--help`: Show help message

**Actions:**
- `rm-file`: Delete file directly from filesystem
- `set-status-0 <fid>`: Set file_managed status = 0 for given FID
- `delete-usage <fid> <type> <id>`: Delete specific file_usage record
- `sql-command "<command>"`: Execute custom SQL command
- `file-command "<command>"`: Execute custom file system command

**Examples:**
```bash
# Delete orphaned file
./cleanup_single_file.sh report.pdf rm-file

# Set file status to temporary
./cleanup_single_file.sh document.docx set-status-0 1234

# Delete specific usage record
./cleanup_single_file.sh image.jpg delete-usage 1234 block_content 5678

# Dry run mode
./cleanup_single_file.sh --dry-run report.pdf rm-file
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

### verify_dom_usage.sh

**Purpose:** Verify if files marked for manual review actually appear in rendered DOM content

**Usage:**
```bash
./verify_dom_usage.sh [OPTIONS] <cleanup_report_file>
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (default: auto-detect)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--base-url <url>`: Override base URL for DOM verification
- `--user-agent <string>`: Custom User-Agent string
- `--timeout <seconds>`: Request timeout in seconds (default: 30)
- `--dry-run`: Show what would be checked without making requests
- `--verbose`: Show detailed output
- `--help`: Show help message

**Examples:**
```bash
# Auto-detect environment and verify all manual files
./verify_dom_usage.sh cleanup_report_20250814_075224.log

# Force lando mode
./verify_dom_usage.sh --mode lando cleanup_report.log

# Terminus mode with site parameters
./verify_dom_usage.sh --mode terminus --site mysite --env dev cleanup_report.log

# Use custom base URL
./verify_dom_usage.sh --base-url https://dev-mysite.pantheonsite.io cleanup_report.log

# Dry run to see what would be checked
./verify_dom_usage.sh --dry-run cleanup_report.log
```

### verify_manual_actions.sh

**Purpose:** Spot-check files or re-verify files where DOM verification failed

**Usage:**
```bash
./verify_manual_actions.sh [OPTIONS] [<input_source>]
```

**Options:**
- `--mode <lando|terminus>`: Execution mode (auto-detected if not specified)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--base-url <url>`: Override base URL for DOM verification
- `--timeout <seconds>`: HTTP timeout for DOM checks (default: 10)
- `--file <filename>`: Check specific file by name
- `--node <node_id>`: Check specific node page
- `--conservative-only`: Only check files marked as "Conservative" (DOM check failed)
- `--dry-run`: Show what would be checked without executing
- `--help`: Show help message

**Examples:**
```bash
# Check all manual action files
./verify_manual_actions.sh cleanup_results.txt

# Spot-check specific file
./verify_manual_actions.sh --file "document.docx" --node 1007

# Re-check only conservative files
./verify_manual_actions.sh --conservative-only cleanup_results.txt
```

### test_domain_detection.sh

**Purpose:** Test domain detection capabilities for both Lando and Terminus environments

**Usage:**
```bash
./test_domain_detection.sh [OPTIONS]
```

**Options:**
- `--mode <lando|terminus>`: Force execution mode (default: auto-detect)
- `--site <sitename>`: Pantheon site name (required for terminus mode)
- `--env <environment>`: Pantheon environment (required for terminus mode)
- `--help`: Show help message

**Examples:**
```bash
# Auto-detect mode and test domain detection
./test_domain_detection.sh

# Test lando domain detection
./test_domain_detection.sh --mode lando

# Test terminus domain detection  
./test_domain_detection.sh --mode terminus --site mysite --env dev
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

## DOM Verification System

The scripts include sophisticated DOM verification to distinguish between database references and actual page content:

**Features:**
- Fetches actual rendered pages using curl
- Multiple filename pattern matching (spaces, underscores, URL encoding)
- Handles Layout Builder and nested paragraph structures
- Configurable timeouts and User-Agent strings

**Pattern Matching:**
- Exact filename matches
- Space-to-underscore/hyphen conversions
- URL encoding variations
- Filename without extension matching

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

**Bulk Unused File Cleanup:**
```sql
UPDATE pantheon.file_managed SET status = 0 WHERE fid IN (
  SELECT fid FROM file_managed WHERE filename = 'orphaned.pdf'
);
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
# Test environment detection
./test_domain_detection.sh

# Check Lando environment
lando info
cat .lando.local.yml | grep DRUSH_OPTIONS_URI

# Check Terminus authentication  
terminus auth:whoami
terminus site:list
```

**Domain Detection Failures:**
```bash
# Force mode and test domain detection
./test_domain_detection.sh --mode lando
./test_domain_detection.sh --mode terminus --site mysite --env dev

# Check Lando configuration
cat .lando.local.yml | grep DRUSH_OPTIONS_URI
lando info

# Verify Lando is running
lando start
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

**DOM Verification Timeouts:**
```bash
# Test with custom timeout and base URL
./verify_dom_usage.sh --timeout 60 --base-url https://your-site.lndo.site cleanup_report.log

# Increase timeout and test manually
curl --max-time 30 "https://your-site.lndo.site/node/123"

# Check site accessibility
./test_domain_detection.sh --mode lando
```

**Permission Errors:**
```bash
# Check file permissions
ls -la web/sites/default/files/

# Verify database access
lando mysql -e "SHOW GRANTS;"
```

### Performance Considerations

- DOM verification adds 0.5+ seconds per file
- Large file lists benefit from `--dry-run` testing first
- Conservative mode reduces false positives but increases manual review
- Batch operations more efficient than individual processing

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
```

**Audit cleanup results:**
```bash
# Review successful operations
grep "Successfully" cleanup_report.log

# Check failed operations
grep "Failed\|Error" cleanup_report.log
```

## Output Formats

All scripts support multiple output formats for different use cases:

### Human Format (Default)
- Color-coded console output
- Detailed explanations and recommendations
- Interactive prompts and confirmations

### JSON Format
```bash
./diagnose_single_file.sh --output json file.pdf
```
- Structured data for programmatic processing
- Consistent field names across scripts
- Machine-readable status and error information

### TSV Format  
```bash
./run_cron.sh --output tsv
```
- Tab-separated values for data analysis
- Compatible with spreadsheet applications
- Easy parsing with standard Unix tools

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