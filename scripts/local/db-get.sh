#!/bin/bash

source ./scripts/local/local-dev-tool.sh

# Lando writes the dump into reference/; rotate the previous one out of the way
# first. DDEV's `ddev pull` writes to .ddev/.downloads/db.sql.gz instead, so
# there is nothing to rotate there.
if [ "$YALESITES_LOCAL_DOCKER_TOOL" = "lando" ]; then
  mkdir -p reference

  if [ -f reference/backup.sql.gz ];
    then mv reference/backup.sql.gz reference/backup-prev.sql.gz;
  fi
fi

ys_local_pull_db
