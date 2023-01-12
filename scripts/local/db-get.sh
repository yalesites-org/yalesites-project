#!/bin/bash

if [ -f reference/backup.sql.gz ];
  then mv reference/backup.sql.gz reference/backup-prev.sql.gz;
fi

lando pull --code=none --database=dev --files=none
