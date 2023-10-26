#!/usr/bin/env bash

# Check arguments
if [[ $# -ne 3 ]]; then
    echo "Usage: ./doItAll.sh <sftpServer> <localDirectoryToSaveFiles> <remoteImageLocation>"
    exit 1
fi

./get_images.sh "$1" "$2" && 
./convert_images.sh "$2" 3840 2160 &&
./put_images.sh "$1" "$3" "$2"
