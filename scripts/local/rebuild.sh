#!/bin/bash

lando composer install
npm run import-local-db
npm run confim
lando drush uli
