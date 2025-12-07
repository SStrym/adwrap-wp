#!/bin/sh
set -e

echo "=== Starting entrypoint ==="

# Replace PORT in nginx config with Railway's PORT
PORT=${PORT:-8080}
sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/nginx.conf
echo "Nginx configured for port $PORT"

# ==============================
# Initialize persistent volume
# ==============================

APP_DIR="/var/www/html/web/app"
APP_BUILD="/var/www/html/web/app-build"

echo "Checking app directory: $APP_DIR"
echo "Backup directory: $APP_BUILD"

# Check if plugins folder exists and has content
if [ ! -d "$APP_DIR/plugins" ] || [ -z "$(ls -A $APP_DIR/plugins 2>/dev/null)" ]; then
    echo "Plugins folder empty or missing, initializing from build..."
    
    if [ -d "$APP_BUILD" ]; then
        # Copy all content from build
        cp -r "$APP_BUILD"/* "$APP_DIR"/ 2>/dev/null || true
        echo "Content copied from build to volume"
    else
        echo "WARNING: Build backup not found at $APP_BUILD"
    fi
else
    echo "Plugins folder exists with content, skipping initialization"
fi

# Ensure directories exist
mkdir -p "$APP_DIR/uploads"
mkdir -p "$APP_DIR/plugins"
mkdir -p "$APP_DIR/themes"
mkdir -p "$APP_DIR/mu-plugins"

# Set permissions
chown -R www-data:www-data "$APP_DIR"

echo "=== Volume setup complete ==="
ls -la "$APP_DIR"
echo "=== Plugins: ==="
ls -la "$APP_DIR/plugins" 2>/dev/null || echo "No plugins dir"

# Start supervisord
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

