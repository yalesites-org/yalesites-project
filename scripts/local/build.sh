#!/bin/bash

lando composer update
npm run confim
lando drush cr
cd web/themes/contrib/atomic
npm install
lando drush uli
