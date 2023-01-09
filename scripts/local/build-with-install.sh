#!/bin/bash

lando drush si yalesites_profile -y
lando drush cr
lando drush migrate:import --group=ys_starterkit
npm run build

