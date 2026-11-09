#!/bin/sh
set -e
# First start: populate the (empty) persistent volume with the pristine Vtiger tree.
if [ ! -f /var/www/html/vtigerversion.php ]; then
    echo "[vtiger] initialising /var/www/html from image..."
    cp -a /usr/src/vtigercrm/. /var/www/html/
fi
mkdir -p /var/www/html/logs /var/www/html/cache /var/www/html/storage /var/www/html/user_privileges
chown -R www-data:www-data /var/www/html 2>/dev/null || true
exec docker-php-entrypoint "$@"
