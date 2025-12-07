#!/bin/sh
set -e

# Replace PORT in nginx config with Railway's PORT
PORT=${PORT:-8080}
sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/nginx.conf

# ==============================
# Initialize persistent volumes
# ==============================

# Uploads directory
mkdir -p /var/www/html/web/app/uploads
chown -R www-data:www-data /var/www/html/web/app/uploads

# Plugins - copy from build if volume is empty
if [ -d "/var/www/html/web/app/plugins" ] && [ -z "$(ls -A /var/www/html/web/app/plugins 2>/dev/null)" ]; then
    echo "Initializing plugins volume from build..."
    if [ -d "/var/www/html/web/app/plugins-build" ]; then
        cp -r /var/www/html/web/app/plugins-build/* /var/www/html/web/app/plugins/ 2>/dev/null || true
    fi
fi
mkdir -p /var/www/html/web/app/plugins
chown -R www-data:www-data /var/www/html/web/app/plugins

# Themes - copy from build if volume is empty
if [ -d "/var/www/html/web/app/themes" ] && [ -z "$(ls -A /var/www/html/web/app/themes 2>/dev/null)" ]; then
    echo "Initializing themes volume from build..."
    if [ -d "/var/www/html/web/app/themes-build" ]; then
        cp -r /var/www/html/web/app/themes-build/* /var/www/html/web/app/themes/ 2>/dev/null || true
    fi
fi
mkdir -p /var/www/html/web/app/themes
chown -R www-data:www-data /var/www/html/web/app/themes

# mu-plugins - these stay in the image, not in volume
chown -R www-data:www-data /var/www/html/web/app/mu-plugins

echo "Volume initialization complete."

# Start supervisord
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

