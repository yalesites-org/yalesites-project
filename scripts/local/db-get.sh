#!/bin/bash

if [ -f ./reference/backup.sql.gz ];
  then mv reference/backup.sql.gz reference/backup-prev.sql.gz;
fi

lando terminus backup:get yalesites-platform.dev --element=db --to=reference/backup.sql.gz
