#!/bin/bash

source ./scripts/local/local-dev-tool.sh

export YALESITES_IS_LOCAL=1
ys_local_drush si yalesites_profile -y
ys_local_drush cr
npm run content-import
npm run build
