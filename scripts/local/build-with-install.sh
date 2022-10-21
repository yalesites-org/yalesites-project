#!/bin/bash

lando drush si yalesites_profile -y
npm run files:get
npm run build
lando drush uli
