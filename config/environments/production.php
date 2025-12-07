<?php

/**
 * Configuration overrides for WP_ENV === 'production'
 */

use Roots\WPConfig\Config;

use function Env\env;

/**
 * Debugging Settings
 */
Config::define('WP_DEBUG', false);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', env('WP_DEBUG_LOG') ?: false);
Config::define('SCRIPT_DEBUG', false);
ini_set('display_errors', '0');

/**
 * File modifications
 * Allow plugin/theme updates if ALLOW_FILE_MODS env is set
 */
$allow_file_mods = env('ALLOW_FILE_MODS') ?: false;
Config::define('DISALLOW_FILE_EDIT', !$allow_file_mods);
Config::define('DISALLOW_FILE_MODS', !$allow_file_mods);

/**
 * Automatic updates
 * Enable if ALLOW_FILE_MODS is true
 */
if ($allow_file_mods) {
    Config::define('AUTOMATIC_UPDATER_DISABLED', false);
    Config::define('WP_AUTO_UPDATE_CORE', 'minor'); // Only minor updates
}

