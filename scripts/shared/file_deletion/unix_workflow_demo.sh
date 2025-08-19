# Unix Philosophy Test - Single File Workflow
# 1. Diagnose a single file
./diagnose_single_file.sh --output json test-orphaned-file.pdf

# 2. Clean up based on diagnosis  
./cleanup_single_file.sh --dry-run test-orphaned-file.pdf rm-file

# 3. Run cron if needed
./run_cron.sh --dry-run
