#!/bin/bash

lando drush si yalesites_profile -y
lando drush cr
npm run content-import
npm run build
