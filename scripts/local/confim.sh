#!/bin/bash

lando drush updatedb -y
lando drush cr
lando drush config-import -y
lando drush cr
