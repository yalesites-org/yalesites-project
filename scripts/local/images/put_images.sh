#!/usr/bin/env bash

# This will upload images processed to a destination Pantheon server
# 
# The motivation for this script is to convert all images to a smaller format.

uploadImages() {
    local destinationServer="$1"
    local destinationDirectory="$2"
    local sourceDirectory="$3"
    local port=2222

    echo "Uploading images to $destinationServer:$destinationDirectory"
    sftp -o Port="$port" "$destinationServer" <<EOF
cd "$destinationDirectory"
put -r "$sourceDirectory"
bye
EOF

    echo "Done uploading images"
}

# Argument check
if [ "$#" -ne 3 ]; then
    echo "Usage: $0 <destinationServer> <destinationDirectory> <sourceDirectory>"
    exit 1
fi

# Directory check
if [ ! -d "$3" ]; then
    echo "Source directory $3 does not exist"
    exit 2
fi

uploadImages "$1" "$2" "$3"
