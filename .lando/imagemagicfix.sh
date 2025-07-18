#!/bin/sh
# echo "Looking for ImageMagick php extension"
# if [ -f "/usr/src/php/ext/imagick" ]; then
#   echo "imagick src folder exists";
# else
#   echo "imagick src folder does not exist, creating it";
#   mkdir -p /usr/src/php/ext/imagick
#   curl -fsSL https://github.com/Imagick/imagick/archive/28f27044e435a2b203e32675e942eb8de620ee58.tar.gz | tar xvz -C "/usr/src/php/ext/imagick" --strip 1
#   docker-php-ext-install imagick
#   composer update
# fi

# echo "Fixing ImageMagick for PDF Thumbnails"
# sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' /etc/ImageMagick-6/policy.xml

echo "Fixing ImageMagick for PDF Thumbnails"
tar -xzf /usr/src/php.tar.xz
mkdir -p /usr/src/php/ext/imagick
curl -fsSL https://github.com/Imagick/imagick/archive/28f27044e435a2b203e32675e942eb8de620ee58.tar.gz | tar xvz -C "/usr/src/php/ext/imagick" --strip 1
docker-php-ext-install imagick
sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read | write" pattern="PDF" \/>/g' /etc/ImageMagick-6/policy.xml
composer install
echo "ImageMagick fix complete"