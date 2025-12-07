# Deploying WordPress to Railway

## Quick Start

1. **Create a new Railway project**
   - Go to [Railway](https://railway.app)
   - Click "New Project" → "Deploy from GitHub repo"
   - Select the `adwrap-wp` repository (or the monorepo and set root directory)

2. **Add MySQL Database**
   - In your project, click "New" → "Database" → "MySQL"
   - Railway will automatically create `DATABASE_URL` variable

3. **Add Redis (Optional, for object caching)**
   - Click "New" → "Database" → "Redis"
   - Will create `REDIS_URL` variable

4. **Set Environment Variables**
   - Click on your WordPress service → "Variables"
   - Add the following variables:

## Required Environment Variables

```
# WordPress URLs (replace with your Railway URL)
WP_HOME=https://your-app.up.railway.app
WP_SITEURL=https://your-app.up.railway.app/wp

# Environment
WP_ENV=production

# Database (auto-set if using Railway MySQL)
DATABASE_URL=${MySQL.DATABASE_URL}

# WordPress Salts - Generate at https://roots.io/salts.html
AUTH_KEY=your-unique-phrase-here
SECURE_AUTH_KEY=your-unique-phrase-here
LOGGED_IN_KEY=your-unique-phrase-here
NONCE_KEY=your-unique-phrase-here
AUTH_SALT=your-unique-phrase-here
SECURE_AUTH_SALT=your-unique-phrase-here
LOGGED_IN_SALT=your-unique-phrase-here
NONCE_SALT=your-unique-phrase-here
```

## Optional Environment Variables

```
# Redis Cache (if using Railway Redis)
WP_REDIS_HOST=${Redis.REDISHOST}
WP_REDIS_PORT=${Redis.REDISPORT}

# Email via Resend
RESEND_API_KEY=re_xxxxxxxxxxxx

# Contact Form Settings
CONTACT_FORM_RECIPIENT=admin@yoursite.com
CONTACT_FORM_FROM_EMAIL=noreply@yoursite.com
CONTACT_FORM_FROM_NAME=AdWrap Graphics

# API Security
INTERNAL_API_SECRET=generate-a-secure-random-string

# Disable WP Cron (recommended for Railway)
DISABLE_WP_CRON=true

# Google Cloud Storage for media uploads
GCS_KEY_JSON_BASE64=<base64-encoded-service-account-json>
GCS_BUCKET=adwrap
```

## GCP Service Account Setup (for media uploads)

The WordPress site uses Google Cloud Storage for media uploads via WP Offload Media plugin.

### Generate base64 key:

```bash
# On your local machine
cat keys/your-service-account.json | base64 -w 0
```

### Add to Railway:

1. Copy the base64 output
2. Add as `GCS_KEY_JSON_BASE64` environment variable in Railway
3. Optionally set `GCS_BUCKET` if different from default

## Custom Domain

1. Go to your service → "Settings" → "Networking"
2. Click "Generate Domain" or add custom domain
3. Update `WP_HOME` and `WP_SITEURL` environment variables

## Auto-Deploy

Railway automatically deploys when you push to your connected branch (usually `main`).

To change the branch:
1. Go to service "Settings" → "Source"
2. Select the branch to watch

## Database Migration

To import existing database:

1. Get Railway MySQL connection string from Variables tab
2. Use a MySQL client to import:
   ```bash
   mysql -h $HOST -u $USER -p$PASSWORD $DATABASE < dump.sql
   ```

Or use Railway's built-in MySQL client:
1. Click on MySQL service
2. Go to "Data" tab
3. Import SQL file

## File Structure

```
adwrap-wp/
├── Dockerfile.railway     # Production Dockerfile
├── railway.toml           # Railway configuration
├── docker/railway/
│   ├── nginx.conf         # NGINX configuration
│   ├── php-fpm.conf       # PHP-FPM configuration
│   ├── supervisord.conf   # Process manager
│   └── entrypoint.sh      # Startup script
├── web/
│   ├── app/               # WordPress content
│   │   ├── mu-plugins/    # Must-use plugins
│   │   ├── plugins/       # Plugins
│   │   └── themes/        # Themes
│   └── wp/                # WordPress core
└── config/
    └── application.php    # Bedrock config
```

## Troubleshooting

### Build fails
- Check that all files in `docker/railway/` exist
- Verify `composer.json` is valid

### 502 Bad Gateway
- Check PHP-FPM is running via logs
- Verify DATABASE_URL is set correctly

### Can't upload files
- Uploads directory permissions issue
- Consider using external storage (S3, GCS)

### Database connection refused
- Verify DATABASE_URL format: `mysql://user:pass@host:port/database`
- Check if MySQL service is running

## Useful Commands

View logs:
```bash
railway logs
```

SSH into container:
```bash
railway shell
```

Run WP-CLI:
```bash
railway run wp --info
```

