#!/usr/bin/env bash

# This script is used to check the possible memory
# allocation ImageMagick would need to convert a base image
# into our currently largest resolution of a file.
#
# Usage: ./calculateMB.sh <image>
#   - image: The image to check the memory usage for
#
# Example: ./calculateMB.sh ~/Pictures/IMG_0001.JPG
#   - This will return the memory usage for IMG_0001.JPG in megabytes

getMB() {
    local resolution
    resolution=$(getResolutionFromImage "$1")
    calculateMemoryUsage "$(retrieveWidth "$resolution")" "$(retrieveHeight "$resolution")"
}

getResolutionFromImage() {
    local image="$1"
    local resolution

    # Use double quotes around "$image" to handle spaces in the filename
    resolution=$(identify -format "%wx%h" "$image")
    
    echo "$resolution"
}

retrieveWidth() {
    echo "${1%x*}"
}

retrieveHeight() {
    echo "${1#*x}"
}

calculateMemoryUsage() {
    local bitsPerPixel=3
    local tweakFactor=1.8
    local width="$1"
    local height="$2"

    local largestWidth=2400
    local largestHeight=1600

    echo "($largestWidth * $largestHeight * $bitsPerPixel + $width * $height * $bitsPerPixel) * $tweakFactor / 1024 / 1024" | bc
}

getMB "$1"
