#!/bin/bash

lando composer update
npm run confim
lando drush cr
lando drush uli
