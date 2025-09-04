#!/bin/sh
echo "Looking for ImageMagick php extension"
if [ php -m | grep imagick ]; then
  echo "imagick src folder exists";
else
  echo "==================================================================================================="
  echo "Fixing ImageMagick for PDF Thumbnails"
  git clone https://github.com/Imagick/imagick.git --depth 1 /usr/src/php/ext/imagick && \
  cd /usr/src/php/ext/imagick && \
  git fetch origin master && \
  git switch master && \
  phpize && \
  ./configure && \
  make && \
  make install && \
  docker-php-ext-enable imagick && \
  sed -i 's#<policy domain="coder" rights="none" pattern="PDF" />#<policy domain="coder" rights="read|write" pattern="PDF" />#g' /etc/ImageMagick-6/policy.xml
  echo "ImageMagick fix complete"
  echo "==================================================================================================="
fi

