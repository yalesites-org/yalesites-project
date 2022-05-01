#!/bin/bash

set -eo pipefail

# Check CSS files for standards.
npm run lint:styles

#Check JS files for standards.
npm run lint:js

# Lint php code for syntax errors.
composer -n lint

# Check coding standards.
composer -n code-sniff
