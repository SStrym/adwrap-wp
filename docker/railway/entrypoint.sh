#!/bin/sh
set -e

# Replace PORT in nginx config with Railway's PORT
PORT=${PORT:-8080}
sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/nginx.conf

# Create uploads directory if it doesn't exist
mkdir -p /var/www/html/web/app/uploads
chown -R www-data:www-data /var/www/html/web/app/uploads

# Start supervisord
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

