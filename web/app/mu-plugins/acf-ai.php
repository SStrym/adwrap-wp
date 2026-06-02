<?php

/**
 * Plugin Name: Enable ACF Abilities API (AI)
 * Description: Opts into ACF's Abilities API so field groups, post types and
 *              taxonomies can be listed/created/updated via the WordPress
 *              Abilities API (/wp-abilities/v1) and MCP. Requires ACF 6.8+ and WP 6.9+.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('acf/settings/enable_acf_ai', '__return_true');
