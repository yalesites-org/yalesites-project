#!/bin/bash

source ./scripts/local/local-dev-tool.sh

ys_local_composer update
npm run confim
ys_local_drush cr
cd web/themes/contrib/atomic
npm install
ys_local_drush uli
