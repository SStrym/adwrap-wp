#!/bin/sh
set -e

# Replace PORT in nginx config with Railway's PORT
PORT=${PORT:-8080}
sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/nginx.conf

# ==============================
# Initialize persistent volume
# ==============================

APP_DIR="/var/www/html/web/app"
APP_BUILD="/var/www/html/web/app-build"

# If volume is empty (only has lost+found or is truly empty), initialize from build
if [ -d "$APP_DIR" ]; then
    # Count files excluding lost+found
    FILE_COUNT=$(find "$APP_DIR" -mindepth 1 -maxdepth 1 ! -name 'lost+found' | wc -l)
    
    if [ "$FILE_COUNT" -eq 0 ] && [ -d "$APP_BUILD" ]; then
        echo "Initializing app volume from build..."
        cp -r "$APP_BUILD"/* "$APP_DIR"/ 2>/dev/null || true
        echo "App volume initialized."
    fi
fi

# Ensure directories exist
mkdir -p "$APP_DIR/uploads"
mkdir -p "$APP_DIR/plugins"
mkdir -p "$APP_DIR/themes"
mkdir -p "$APP_DIR/mu-plugins"

# Set permissions
chown -R www-data:www-data "$APP_DIR"

echo "Volume setup complete."

# Start supervisord
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

