#!/bin/bash

set -eo pipefail

# Lint php code for syntax errors
composer -n lint

# Check coding standards
composer -n code-sniff
