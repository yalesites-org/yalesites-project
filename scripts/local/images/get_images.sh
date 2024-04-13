#!/usr/bin/env bash

# This will attempt to retrieve all files under the /files
# directory on a remote Pantheon server.
# 
# The motivation for this script is to convert all images to a smaller format.

getFiles() {
    local port=2222

    sftp -o Port="$port" "$1" <<EOF
    get -R /files/. "$2"
bye
EOF

    if [ $? -eq 0 ]; then
        echo "Success"echo "Download completed successfully.  Files are located at $2"
    else
        echo "Something went wrong"
    fi
}

# Ensure they passed arguments
if [ $# -lt 2 ]; then
    echo "Usage: $0 <server> <destination>"
    exit 1
fi

# Test if destination exists
if [ ! -d "$2" ]; then
    echo "Destination $2 does not exist; attempting to make directory"

    if ! mkdir -p "$2"; then
        echo "Could not create directory $2"
        exit 2
    fi
fi

getFiles "$1" "$2"
