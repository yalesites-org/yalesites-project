#!/bin/bash

ddev composer update
npm run confim
ddev drush cr
cd web/themes/contrib/atomic
npm install
ddev drush uli
