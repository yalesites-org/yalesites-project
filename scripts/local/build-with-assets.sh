#!/bin/bash

npm run db:import
npm run files:get
npm run build
lando drush uli
