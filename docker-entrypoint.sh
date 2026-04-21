#!/bin/bash
set -e

DB_PORT="${MOODLE_DATABASE_PORT_NUMBER:-5432}"
WWWROOT="${MOODLE_WWWROOT:-http://localhost:8080}"

if [ ! -f /var/www/html/config.php ]; then
    echo "First run detected. Waiting for PostgreSQL at ${MOODLE_DATABASE_HOST}:${DB_PORT}..."

    until php -r "new PDO('pgsql:host=${MOODLE_DATABASE_HOST};port=${DB_PORT};dbname=${MOODLE_DATABASE_NAME}','${MOODLE_DATABASE_USER}','${MOODLE_DATABASE_PASSWORD}');" > /dev/null 2>&1; do
        echo "  Database not ready, retrying in 5s..."
        sleep 5
    done

    echo "Database is ready. Running Moodle CLI installer (this may take a few minutes)..."

    php /var/www/html/admin/cli/install.php \
        --lang=en \
        --wwwroot="${WWWROOT}" \
        --dataroot=/var/moodledata \
        --dbtype=pgsql \
        --dbhost="${MOODLE_DATABASE_HOST}" \
        --dbname="${MOODLE_DATABASE_NAME}" \
        --dbuser="${MOODLE_DATABASE_USER}" \
        --dbpass="${MOODLE_DATABASE_PASSWORD}" \
        --dbport="${DB_PORT}" \
        --fullname="${MOODLE_SITE_NAME:-Moodle}" \
        --shortname="${MOODLE_SHORTNAME:-moodle}" \
        --adminuser="${MOODLE_USERNAME:-admin}" \
        --adminpass="${MOODLE_PASSWORD:-Admin1234!}" \
        --adminemail="${MOODLE_EMAIL:-admin@example.com}" \
        --non-interactive \
        --agree-license

    chown www-data:www-data /var/www/html/config.php
    echo "Moodle installation complete!"
fi

exec apache2-foreground
