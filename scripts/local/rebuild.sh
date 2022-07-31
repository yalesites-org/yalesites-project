#!/bin/bash

lando composer update
npm run get-db
npm run get-files
npm run confim
lando drush cr
lando drush uli
