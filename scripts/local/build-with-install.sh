#!/bin/bash

export YALESITES_IS_LOCAL=1
lando drush si yalesites_profile -y
lando drush cr
npm run content-import
npm run build
