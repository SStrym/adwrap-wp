<?php
/**
 * AdWrap - expose Yoast SEO title/description as REST-writable post meta.
 *
 * Yoast stores the SEO title / meta description / focus keyword as protected
 * post meta (_yoast_wpseo_*) and does NOT expose them for REST writes. The
 * headless tooling needs to update them via the core wp/v2 endpoints, so we
 * re-register those meta keys with show_in_rest + an edit capability check.
 * Registered for every content type whose SEO we manage from the frontend repo.
 *
 * Runs at init priority 20 (after Yoast's own registration) so our REST args win.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    $post_types = ['page', 'post', 'service', 'portfolio', 'success_story', 'location'];
    $meta_keys  = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw'];

    foreach ($post_types as $post_type) {
        foreach ($meta_keys as $meta_key) {
            register_post_meta($post_type, $meta_key, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }
}, 20);
