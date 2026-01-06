<?php

/**
 * Plugin Name: ACF Revisions Support
 * Description: Enables revision support for ACF fields on custom post types
 */

final class ACFRevisionsSupport
{
    private array $supported_post_types = ['service', 'portfolio', 'success_story'];

    public function __construct()
    {
        add_action('save_post', [$this, 'save_acf_to_revision'], 20, 3);
        add_action('wp_restore_post_revision', [$this, 'restore_acf_from_revision'], 10, 2);
        add_action('add_meta_boxes', [$this, 'add_revisions_metabox']);
    }

    public function add_revisions_metabox(): void
    {
        foreach ($this->supported_post_types as $post_type) {
            add_meta_box(
                'acf_revisions_metabox',
                'Revisions',
                [$this, 'render_revisions_metabox'],
                $post_type,
                'side',
                'low'
            );
        }
    }

    public function render_revisions_metabox(WP_Post $post): void
    {
        $revisions = wp_get_post_revisions($post->ID, ['posts_per_page' => 10]);
        
        if (empty($revisions)) {
            echo '<p>No revisions yet.</p>';
            return;
        }

        echo '<ul style="margin: 0; padding: 0; list-style: none;">';
        
        foreach ($revisions as $revision) {
            $date = wp_date('M j, Y @ H:i', strtotime($revision->post_modified));
            $author = get_the_author_meta('display_name', $revision->post_author);
            $restore_link = wp_nonce_url(
                admin_url('revision.php?action=restore&revision=' . $revision->ID),
                'restore-post_' . $revision->ID
            );
            $compare_link = admin_url('revision.php?revision=' . $revision->ID);
            
            echo '<li style="padding: 8px 0; border-bottom: 1px solid #eee;">';
            echo '<strong>' . esc_html($date) . '</strong><br>';
            echo '<small>by ' . esc_html($author) . '</small><br>';
            echo '<a href="' . esc_url($compare_link) . '">View</a>';
            echo ' | <a href="' . esc_url($restore_link) . '" onclick="return confirm(\'Restore this revision?\')">Restore</a>';
            echo '</li>';
        }
        
        echo '</ul>';
        
        $all_revisions_link = admin_url('revision.php?revision=' . $post->ID);
        echo '<p style="margin-top: 10px;"><a href="' . esc_url($all_revisions_link) . '">Browse all revisions</a></p>';
    }

    public function save_acf_to_revision(int $post_id, WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (!in_array($post->post_type, $this->supported_post_types, true)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => 1]);
        
        if (empty($revisions)) {
            return;
        }

        $latest_revision = reset($revisions);
        $revision_id = $latest_revision->ID;

        $meta = get_post_meta($post_id);
        
        if (!$meta) {
            return;
        }

        foreach ($meta as $key => $values) {
            if ($this->is_acf_meta_key($key)) {
                update_metadata('post', $revision_id, $key, $values[0]);
            }
        }
    }

    public function restore_acf_from_revision(int $post_id, int $revision_id): void
    {
        $post = get_post($post_id);
        
        if (!$post || !in_array($post->post_type, $this->supported_post_types, true)) {
            return;
        }

        $meta = get_post_meta($revision_id);
        
        if (!$meta) {
            return;
        }

        foreach ($meta as $key => $values) {
            if ($this->is_acf_meta_key($key)) {
                update_post_meta($post_id, $key, maybe_unserialize($values[0]));
            }
        }

        clean_post_cache($post_id);
    }

    private function is_acf_meta_key(string $key): bool
    {
        if (str_starts_with($key, '_edit_') || str_starts_with($key, '_wp_')) {
            return false;
        }

        $acf_fields = [
            'hero', 'card', 'benefits', 'services_list', 'content_sections', 
            'gallery', 'cta', 'before', 'client_name'
        ];

        foreach ($acf_fields as $field) {
            if ($key === $field || $key === '_' . $field || str_starts_with($key, $field . '_') || str_starts_with($key, '_' . $field . '_')) {
                return true;
            }
        }

        return false;
    }
}

add_action('plugins_loaded', function () {
    if (function_exists('get_fields')) {
        new ACFRevisionsSupport();
    }
});
