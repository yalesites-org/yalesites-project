#!/bin/bash

source ./scripts/local/local-dev-tool.sh

mkdir -p reference

if [ -f reference/backup.sql.gz ];
  then mv reference/backup.sql.gz reference/backup-prev.sql.gz;
fi

ys_local_pull_db
