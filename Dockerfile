FROM php:8.3-apache

# Install system dependencies required by Moodle
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libxml2-dev \
        libicu-dev \
        libpq-dev \
        libsodium-dev \
        ghostscript \
        unzip \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Moodle
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd intl zip soap opcache pdo_pgsql pgsql sodium exif

# Enable Apache rewrite and allow .htaccess overrides
RUN a2enmod rewrite \
    && echo '<Directory /var/www/html>\n\tAllowOverride All\n\tOptions -Indexes +FollowSymLinks\n</Directory>' \
        > /etc/apache2/conf-available/moodle.conf \
    && a2enconf moodle

# PHP tuning for Moodle
RUN printf "max_input_vars = 5000\nupload_max_filesize = 1G\npost_max_size = 1G\nmemory_limit = 512M\nmax_execution_time = 300\n" \
    > /usr/local/etc/php/conf.d/moodle.ini

# Download Moodle 4.5 from official source
RUN rm -f /var/www/html/index.html \
    && curl -fSL "https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz" \
        -o /tmp/moodle.tgz \
    && tar -xzf /tmp/moodle.tgz -C /var/www/html --strip-components=1 \
    && rm /tmp/moodle.tgz \
    && chown -R www-data:www-data /var/www/html

# Create moodledata directory (user-generated files, must be outside webroot)
RUN mkdir -p /var/moodledata && chown www-data:www-data /var/moodledata

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
