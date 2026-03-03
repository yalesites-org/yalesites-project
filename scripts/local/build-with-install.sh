#!/bin/bash

export YALESITES_IS_LOCAL=1
ddev drush si yalesites_profile -y
ddev drush cr
npm run content-import
npm run build
