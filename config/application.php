<?php

/**
 * Your base production configuration goes in this file. Environment-specific
 * overrides go in their respective config/environments/{{WP_ENV}}.php file.
 *
 * A good default policy is to deviate from the production config as little as
 * possible. Try to define as much of your configuration in this file as you
 * can.
 */

use Roots\WPConfig\Config;

use function Env\env;

// USE_ENV_ARRAY + CONVERT_* + STRIP_QUOTES
Env\Env::$options = 31;

/**
 * Directory containing all of the site's files
 *
 * @var string
 */
$root_dir = dirname(__DIR__);

/**
 * Document Root
 *
 * @var non-falsy-string
 */
$webroot_dir = $root_dir . '/web';

/**
 * Use Dotenv to set required environment variables and load .env file in root
 * .env.local will override .env if it exists
 */
if (file_exists($root_dir . '/.env')) {
    $env_files = file_exists($root_dir . '/.env.local')
        ? ['.env', '.env.local']
        : ['.env'];

    $repository = Dotenv\Repository\RepositoryBuilder::createWithNoAdapters()
        ->addAdapter(Dotenv\Repository\Adapter\EnvConstAdapter::class)
        ->addAdapter(Dotenv\Repository\Adapter\PutenvAdapter::class)
        ->immutable()
        ->make();

    $dotenv = Dotenv\Dotenv::create($repository, $root_dir, $env_files, false);
    $dotenv->load();

    $dotenv->required(['WP_HOME', 'WP_SITEURL']);
    if (!env('DATABASE_URL')) {
        $dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD']);
    }
}

/**
 * Set up our global environment constant and load its config first
 * Default: production
 */
define('WP_ENV', env('WP_ENV') ?: 'production');

/**
 * Infer WP_ENVIRONMENT_TYPE based on WP_ENV
 */
if (!env('WP_ENVIRONMENT_TYPE') && in_array(WP_ENV, ['production', 'staging', 'development', 'local'])) {
    Config::define('WP_ENVIRONMENT_TYPE', WP_ENV);
}

Config::define('WP_REDIS_HOST', env('WP_REDIS_HOST') ?: 'cache');
Config::define('WP_REDIS_PORT', env('WP_REDIS_PORT') ?: 6379);
Config::define('WP_REDIS_PASSWORD', env('WP_REDIS_PASSWORD') ?: null);
Config::define('WP_REDIS_DISABLED', env('WP_REDIS_DISABLED') ?: false);

Config::define('WP_REDIS_CONFIG', [
    'token' => env('OBJECT_CACHE_PRO_TOKEN') ?: 'e279430effe043b8c17d3f3c751c4c0846bc70c97f0eaaea766b4079001c',
    'host' => env('WP_REDIS_HOST') ?: 'cache',
    'port' => (int) (env('WP_REDIS_PORT') ?: 6379),
    'password' => env('WP_REDIS_PASSWORD') ?: null,
    'database' => 0,
    'maxttl' => 3600 * 24 * 7, // 7 days
    'timeout' => 1.0,
    'read_timeout' => 1.0,
    'prefetch' => true,
    'split_alloptions' => true,
    'strict' => true,
    'debug' => false,
    'non_prefetchable_groups' => ['as3cf', 'as3cf_item'],
]);


/**
 * URLs
 */
Config::define('WP_HOME', env('WP_HOME'));
Config::define('WP_SITEURL', env('WP_SITEURL'));

/**
 * Custom Content Directory
 */
Config::define('CONTENT_DIR', '/app');
Config::define('WP_CONTENT_DIR', $webroot_dir . Config::get('CONTENT_DIR'));
Config::define('WP_CONTENT_URL', Config::get('WP_HOME') . Config::get('CONTENT_DIR'));

/**
 * DB settings
 */
if (env('DB_SSL')) {
    Config::define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL);
}

Config::define('DB_NAME', env('DB_NAME'));
Config::define('DB_USER', env('DB_USER'));
Config::define('DB_PASSWORD', env('DB_PASSWORD'));
Config::define('DB_HOST', env('DB_HOST') ?: 'localhost');
Config::define('DB_CHARSET', 'utf8mb4');
Config::define('DB_COLLATE', '');
$table_prefix = env('DB_PREFIX') ?: 'wp_';

if (env('DATABASE_URL')) {
    $dsn = (object) parse_url(env('DATABASE_URL'));

    Config::define('DB_NAME', substr($dsn->path, 1));
    Config::define('DB_USER', $dsn->user);
    Config::define('DB_PASSWORD', isset($dsn->pass) ? $dsn->pass : null);
    Config::define('DB_HOST', isset($dsn->port) ? "{$dsn->host}:{$dsn->port}" : $dsn->host);
}

/**
 * Authentication Unique Keys and Salts
 */
Config::define('AUTH_KEY', env('AUTH_KEY'));
Config::define('SECURE_AUTH_KEY', env('SECURE_AUTH_KEY'));
Config::define('LOGGED_IN_KEY', env('LOGGED_IN_KEY'));
Config::define('NONCE_KEY', env('NONCE_KEY'));
Config::define('AUTH_SALT', env('AUTH_SALT'));
Config::define('SECURE_AUTH_SALT', env('SECURE_AUTH_SALT'));
Config::define('LOGGED_IN_SALT', env('LOGGED_IN_SALT'));
Config::define('NONCE_SALT', env('NONCE_SALT'));

/**
 * Custom Settings
 */
Config::define('AUTOMATIC_UPDATER_DISABLED', true);
Config::define('DISABLE_WP_CRON', env('DISABLE_WP_CRON') ?: false);

// Disable the plugin and theme file editor in the admin
Config::define('DISALLOW_FILE_EDIT', true);

// Disable plugin and theme updates and installation from the admin
Config::define('DISALLOW_FILE_MODS', true);

// Limit the number of post revisions
Config::define('WP_POST_REVISIONS', env('WP_POST_REVISIONS') ?? true);

// Disable script concatenation
Config::define('CONCATENATE_SCRIPTS', false);

/**
 * GCP Service Account Key
 * Option 1: Place JSON file in /keys/ directory (local dev)
 * Option 2: Set GCS_KEY_JSON_BASE64 env variable with base64 encoded JSON (production)
 */
$gcs_key_file = null;

// Check for base64 encoded key in env (for Railway/production)
if (env('GCS_KEY_JSON_BASE64')) {
    $gcs_key_content = base64_decode(env('GCS_KEY_JSON_BASE64'));
    $gcs_key_file = '/tmp/gcs-key.json';
    file_put_contents($gcs_key_file, $gcs_key_content);
}
// Fallback to file in /keys/ directory (local development)
elseif (env('GCS_KEY_FILE')) {
    $gcs_key_file = $root_dir . '/keys/' . env('GCS_KEY_FILE');
}
else {
    $default_key = $root_dir . '/keys/focused-house-366710-effb131fea8e.json';
    if (file_exists($default_key)) {
        $gcs_key_file = $default_key;
    }
}

if ($gcs_key_file && file_exists($gcs_key_file)) {
    Config::define('AS3CF_SETTINGS', serialize(array(
        'provider'      => 'gcp',
        'bucket'        => env('GCS_BUCKET') ?: 'adwrap',
        'key-file-path' => $gcs_key_file,
    )));
}

/**
 * Debugging Settings
 */
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
ini_set('display_errors', '0');

/**
 * Allow WordPress to detect HTTPS when used behind a reverse proxy or a load balancer
 * See https://codex.wordpress.org/Function_Reference/is_ssl#Notes
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

$env_config = __DIR__ . '/environments/' . WP_ENV . '.php';

if (file_exists($env_config)) {
    require_once $env_config;
}

Config::apply();

/**
 * Bootstrap WordPress
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $webroot_dir . '/wp/');
}
