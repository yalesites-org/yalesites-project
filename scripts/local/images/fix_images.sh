#!/usr/bin/env bash

# This script will attempt to convert any images that are greater than the
# desired width and height into the desired width and height.
#
# Requirements:
#  - ImageMagick
#  - bc
#
# Usage: ./fix_images.sh <path_to_where_images_are> <desired_max_width> <desired_max_height>
#  - path_to_where_images_are: The path to where the images are
#  - desired_max_width: The desired maximum width of the images
#  - desired_max_height: The desired maximum height of the images
#
#  Example: ./fix_images.sh ~/Pictures 3840 2160
#  - This will convert any images in ~/Pictures that are greater than 3840x2160
#   to 3840x2160 or close to it.
#  - This will also print out the memory usage for each image (before and after)
#  - This will also print out the command that will be used to convert the image
#    (in case you want to copy and paste it to run it yourself)

getImages() {
    local dirsToExclude=("library-definitions" "media-icons" "private" "styles" "js" "oembed_thumbnails" "paragraphs_type_icon" "css")
    local dirToLook="$1"

    # Get rid of excluded directories--will make find easier
    for dir in "${dirsToExclude[@]}"; do
        rm -rf "$dirToLook/$dir"
    done

    find "$dirToLook" -type f \( -name "*.jpg" -o -name "*.jpeg" -o -name "*.JPEG" -o -name "*.JPG" \)
}

convertImages() {
    local desiredWidth=$2
    local desiredHeight=$3
    local height
    local width
    local resolution
    local directory="$1"

    local red='\033[0;31m'
    local green='\033[0;32m'
    local yellow='\033[0;33m'
    local noColor='\033[0m'

    # Use an array to store file paths and prevent splitting
    local files=()
    IFS=$'\n' # Set the Internal Field Separator to newline

    # Use the getImages function to populate the array
    readarray -t files <<< "$(getImages "$directory")"

    for file in "${files[@]}"; do
        resolution=$(getResolutionFromImage "$file")
        width=$(retrieveWidth "$resolution")
        height=$(retrieveHeight "$resolution")

        if [[ $height -gt $desiredHeight ]]; then
            echo -e "$red [Will Convert] $file has a resolution of $resolution: w: $width, h: $height (Target: $desiredWidth x $desiredHeight, Current memory usage: $(calculateMemoryUsage "$width" "$height") MB) $noColor"
            echo convert -resize 3840x2160\> -quality 100 "$file" "$file"
            echo -e "$yellow" convert "$file" -resize 3840x2160\> -quality 100 "$file" "$noColor"
            convert "$file" -resize 3840x2160\> -quality 100 "$file"

            # Retrieve the new resolution, width, and height to recalculate the new memory usage
            resolution=$(getResolutionFromImage "$file")
            width=$(retrieveWidth "$resolution")
            height=$(retrieveHeight "$resolution")
            echo -e "$green [Converted] $file has been converted to $(getResolutionFromImage "$file") : Current memory usage: $(calculateMemoryUsage "$width" "$height") MB $noColor"
        else
            echo -e "$green [Will Not Convert] $file has a resolution of $resolution: w: $width, h: $height, Current memory usage: $(calculateMemoryUsage "$width" "$height") MB) $noColor"
        fi
    done
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

    # This is currently our largest generated image resolution
    # Should we ever update this, we will need to update this script
    local largestWidth=2400
    local largestHeight=1600

    echo "($largestWidth * $largestHeight * $bitsPerPixel + $width * $height * $bitsPerPixel) * $tweakFactor / 1024 / 1024" | bc
}

# Ensure that they pass the right parameters
if [ $# -ne 3 ]; then
    echo "Usage: ./fix_images.sh <path_to_where_images_are> <desired_max_width> <desired_max_height>"
    exit 1
fi

# Ensure directory exists
if [ ! -d "$1" ]; then
    echo "Directory $1 does not exist"
    exit 2
fi

convertImages "$1" "$2" "$3"
