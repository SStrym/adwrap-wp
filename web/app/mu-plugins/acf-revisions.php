<?php

/**
 * Plugin Name: ACF Revisions Support
 * Description: Full revision support for all ACF fields including repeaters, flexible content, and groups
 * Version: 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ACFRevisionsSupport
{
    /**
     * Post types that support ACF revisions
     * Add 'page' and any custom post types here
     */
    private array $supported_post_types = [
        'page',
        'post',
        'service',
        'portfolio',
        'success_story',
    ];

    private bool $debug = false;

    public function __construct()
    {
        // Enable debug mode in development
        $this->debug = defined('WP_DEBUG') && WP_DEBUG;

        // Core revision hooks
        add_action('_wp_put_post_revision', [$this, 'save_acf_to_revision'], 10, 1);
        add_action('wp_restore_post_revision', [$this, 'restore_acf_from_revision'], 10, 2);
        
        // Force revision creation when ACF fields change
        add_filter('wp_save_post_revision_post_has_changed', [$this, 'check_acf_changes'], 10, 3);
        
        // Add revision fields to revision screen
        add_filter('_wp_post_revision_fields', [$this, 'add_acf_revision_fields'], 10, 2);
        
        // Add metabox for quick revision access
        add_action('add_meta_boxes', [$this, 'add_revisions_metabox']);
        
        // Clear ACF cache after restore
        add_action('wp_restore_post_revision', [$this, 'clear_acf_cache'], 20, 2);
    }

    /**
     * Log debug messages
     */
    private function log(string $message, array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        $log_message = '[ACF Revisions] ' . $message;
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context);
        }

        error_log($log_message);
    }

    /**
     * Check if post type is supported
     */
    private function is_supported_post_type(string $post_type): bool
    {
        return in_array($post_type, $this->supported_post_types, true);
    }

    /**
     * Add revisions metabox to supported post types
     */
    public function add_revisions_metabox(): void
    {
        foreach ($this->supported_post_types as $post_type) {
            add_meta_box(
                'acf_revisions_metabox',
                __('Revisions (with ACF)', 'acf-revisions'),
                [$this, 'render_revisions_metabox'],
                $post_type,
                'side',
                'low'
            );
        }
    }

    /**
     * Render revisions metabox
     */
    public function render_revisions_metabox(WP_Post $post): void
    {
        $revisions = wp_get_post_revisions($post->ID, ['posts_per_page' => 10]);

        if (empty($revisions)) {
            echo '<p>' . __('No revisions yet. ACF fields will be saved with each revision.', 'acf-revisions') . '</p>';
            return;
        }

        echo '<style>
            .acf-revision-item { padding: 8px 0; border-bottom: 1px solid #eee; }
            .acf-revision-item:last-child { border-bottom: none; }
            .acf-revision-meta { color: #666; font-size: 12px; }
            .acf-revision-actions { margin-top: 4px; }
            .acf-revision-actions a { text-decoration: none; }
        </style>';

        echo '<ul style="margin: 0; padding: 0; list-style: none;">';

        foreach ($revisions as $revision) {
            $date = wp_date('M j, Y @ H:i', strtotime($revision->post_modified));
            $author = get_the_author_meta('display_name', $revision->post_author);
            $restore_link = wp_nonce_url(
                admin_url('revision.php?action=restore&revision=' . $revision->ID),
                'restore-post_' . $revision->ID
            );
            $compare_link = admin_url('revision.php?revision=' . $revision->ID);

            // Check if revision has ACF data
            $has_acf = $this->revision_has_acf_data($revision->ID);

            echo '<li class="acf-revision-item">';
            echo '<strong>' . esc_html($date) . '</strong>';
            if ($has_acf) {
                echo ' <span style="color: #00a0d2;">●</span>';
            }
            echo '<br>';
            echo '<span class="acf-revision-meta">by ' . esc_html($author) . '</span>';
            echo '<div class="acf-revision-actions">';
            echo '<a href="' . esc_url($compare_link) . '">' . __('View', 'acf-revisions') . '</a>';
            echo ' | ';
            echo '<a href="' . esc_url($restore_link) . '" onclick="return confirm(\'' . esc_js(__('Restore this revision? All ACF fields will be restored.', 'acf-revisions')) . '\')">' . __('Restore', 'acf-revisions') . '</a>';
            echo '</div>';
            echo '</li>';
        }

        echo '</ul>';

        echo '<p style="margin-top: 10px; font-size: 11px; color: #666;">';
        echo '<span style="color: #00a0d2;">●</span> = ' . __('Contains ACF data', 'acf-revisions');
        echo '</p>';

        $all_revisions_link = admin_url('revision.php?revision=' . $post->ID);
        echo '<p style="margin-top: 10px;"><a href="' . esc_url($all_revisions_link) . '">' . __('Browse all revisions', 'acf-revisions') . '</a></p>';
    }

    /**
     * Check if revision has ACF data saved
     */
    private function revision_has_acf_data(int $revision_id): bool
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE post_id = %d 
            AND meta_key NOT LIKE '\\_wp%%' 
            AND meta_key NOT LIKE '\\_edit%%'
            LIMIT 1",
            $revision_id
        ));

        return (int)$count > 0;
    }

    /**
     * Save all ACF fields to revision
     */
    public function save_acf_to_revision(int $revision_id): void
    {
        $revision = get_post($revision_id);

        if (!$revision || $revision->post_type !== 'revision') {
            return;
        }

        $parent_id = $revision->post_parent;
        $parent = get_post($parent_id);

        if (!$parent || !$this->is_supported_post_type($parent->post_type)) {
            return;
        }

        $this->log('Saving ACF to revision', [
            'revision_id' => $revision_id,
            'parent_id' => $parent_id,
            'post_type' => $parent->post_type,
        ]);

        // Get all post meta from parent
        $all_meta = get_post_meta($parent_id);

        if (empty($all_meta)) {
            $this->log('No meta found for parent post');
            return;
        }

        $saved_keys = [];

        foreach ($all_meta as $meta_key => $meta_values) {
            // Skip WordPress internal meta
            if ($this->is_wp_internal_meta($meta_key)) {
                continue;
            }

            // Copy meta to revision
            foreach ($meta_values as $meta_value) {
                update_metadata('post', $revision_id, $meta_key, maybe_unserialize($meta_value));
                $saved_keys[] = $meta_key;
            }
        }

        $this->log('Saved meta keys to revision', [
            'count' => count($saved_keys),
            'keys' => array_slice($saved_keys, 0, 20), // Log first 20 keys
        ]);
    }

    /**
     * Restore ACF fields from revision
     */
    public function restore_acf_from_revision(int $post_id, int $revision_id): void
    {
        $post = get_post($post_id);

        if (!$post || !$this->is_supported_post_type($post->post_type)) {
            return;
        }

        $this->log('Restoring ACF from revision', [
            'post_id' => $post_id,
            'revision_id' => $revision_id,
            'post_type' => $post->post_type,
        ]);

        // Get all meta from revision
        $revision_meta = get_post_meta($revision_id);

        if (empty($revision_meta)) {
            $this->log('No meta found in revision');
            return;
        }

        // Get current meta keys to know what to delete
        $current_meta = get_post_meta($post_id);
        
        // Delete current ACF meta (to handle removed fields)
        foreach ($current_meta as $meta_key => $meta_values) {
            if (!$this->is_wp_internal_meta($meta_key)) {
                delete_post_meta($post_id, $meta_key);
            }
        }

        $restored_keys = [];

        // Restore meta from revision
        foreach ($revision_meta as $meta_key => $meta_values) {
            if ($this->is_wp_internal_meta($meta_key)) {
                continue;
            }

            foreach ($meta_values as $meta_value) {
                $value = maybe_unserialize($meta_value);
                update_post_meta($post_id, $meta_key, $value);
                $restored_keys[] = $meta_key;
            }
        }

        $this->log('Restored meta keys from revision', [
            'count' => count($restored_keys),
            'keys' => array_slice($restored_keys, 0, 20),
        ]);
    }

    /**
     * Clear ACF cache after restoration
     */
    public function clear_acf_cache(int $post_id, int $revision_id): void
    {
        // Clear WordPress object cache
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');

        // Clear ACF cache if available
        if (function_exists('acf_flush_value_cache')) {
            acf_flush_value_cache($post_id);
        }

        // Clear ACF store cache
        if (class_exists('acf') && method_exists('acf', 'get_store')) {
            $store = acf()->get_store('values');
            if ($store) {
                $store->reset();
            }
        }

        $this->log('Cleared ACF cache', ['post_id' => $post_id]);
    }

    /**
     * Check if ACF fields have changed
     */
    public function check_acf_changes(bool $has_changed, WP_Post $last_revision, WP_Post $post): bool
    {
        if ($has_changed) {
            return true;
        }

        if (!$this->is_supported_post_type($post->post_type)) {
            return $has_changed;
        }

        // Get current post meta
        $current_meta = get_post_meta($post->ID);
        $revision_meta = get_post_meta($last_revision->ID);

        // Compare ACF fields
        foreach ($current_meta as $meta_key => $meta_values) {
            if ($this->is_wp_internal_meta($meta_key)) {
                continue;
            }

            $current_value = maybe_serialize($meta_values[0] ?? '');
            $revision_value = maybe_serialize($revision_meta[$meta_key][0] ?? '');

            if ($current_value !== $revision_value) {
                $this->log('ACF field changed', [
                    'field' => $meta_key,
                    'post_id' => $post->ID,
                ]);
                return true;
            }
        }

        // Check for removed fields
        foreach ($revision_meta as $meta_key => $meta_values) {
            if ($this->is_wp_internal_meta($meta_key)) {
                continue;
            }

            if (!isset($current_meta[$meta_key])) {
                $this->log('ACF field removed', [
                    'field' => $meta_key,
                    'post_id' => $post->ID,
                ]);
                return true;
            }
        }

        return $has_changed;
    }

    /**
     * Add ACF fields to revision comparison screen
     */
    public function add_acf_revision_fields(array $fields, array|WP_Post $post): array
    {
        $post_obj = is_array($post) ? get_post($post['ID'] ?? 0) : $post;

        if (!$post_obj) {
            return $fields;
        }

        // Get the actual post (not revision)
        $actual_post_id = wp_is_post_revision($post_obj->ID) 
            ? wp_get_post_parent_id($post_obj->ID) 
            : $post_obj->ID;

        $actual_post = get_post($actual_post_id);

        if (!$actual_post || !$this->is_supported_post_type($actual_post->post_type)) {
            return $fields;
        }

        // Add ACF fields indicator
        $fields['_acf_fields'] = __('ACF Fields', 'acf-revisions');

        return $fields;
    }

    /**
     * Check if meta key is WordPress internal
     */
    private function is_wp_internal_meta(string $key): bool
    {
        $internal_prefixes = [
            '_wp_',
            '_edit_',
            '_encloseme',
            '_pingme',
            '_thumbnail_id', // Keep featured image separate
        ];

        foreach ($internal_prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

// Initialize plugin after ACF is loaded
add_action('acf/init', function () {
    new ACFRevisionsSupport();
}, 20);

// Fallback initialization
add_action('plugins_loaded', function () {
    if (function_exists('get_fields') && !did_action('acf/init')) {
        new ACFRevisionsSupport();
    }
}, 20);
