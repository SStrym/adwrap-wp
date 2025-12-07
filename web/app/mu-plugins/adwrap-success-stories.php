<?php
/**
 * Plugin Name: AdWrap Success Stories
 * Description: Custom Post Type and REST API for Success Stories
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Success Story Custom Post Type
 */
add_action('init', function() {
    register_post_type('success_story', [
        'labels' => [
            'name' => 'Success Stories',
            'singular_name' => 'Success Story',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Success Story',
            'edit_item' => 'Edit Success Story',
            'new_item' => 'New Success Story',
            'view_item' => 'View Success Story',
            'search_items' => 'Search Success Stories',
            'not_found' => 'No success stories found',
            'not_found_in_trash' => 'No success stories found in Trash',
            'menu_name' => 'Success Stories',
        ],
        'public' => true,
        'has_archive' => true,
        'rewrite' => ['slug' => 'success-stories'],
        'supports' => ['title', 'thumbnail', 'excerpt'],
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-awards',
        'menu_position' => 6,
    ]);
});

/**
 * Register REST API endpoints
 */
add_action('rest_api_init', function() {
    // Get all success stories
    register_rest_route('adwrap/v1', '/success-stories', [
        'methods' => 'GET',
        'callback' => 'adwrap_get_success_stories',
        'permission_callback' => '__return_true',
        'args' => [
            'per_page' => [
                'default' => 10,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    // Get single success story by slug
    register_rest_route('adwrap/v1', '/success-stories/(?P<slug>[a-zA-Z0-9-]+)', [
        'methods' => 'GET',
        'callback' => 'adwrap_get_success_story',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Format success story for card view
 */
function format_success_story_card($post) {
    $hero_image = get_field('hero_image', $post->ID);
    $hero_background = get_field('hero_background', $post->ID);
    $thumbnail = get_post_thumbnail_id($post->ID);
    
    // Use hero image or featured image
    $image = null;
    if ($hero_image) {
        $image = [
            'id' => $hero_image['ID'],
            'url' => $hero_image['url'],
            'alt' => $hero_image['alt'],
            'width' => $hero_image['width'],
            'height' => $hero_image['height'],
            'sizes' => $hero_image['sizes'] ?? null,
        ];
    } elseif ($thumbnail) {
        $img_data = wp_get_attachment_image_src($thumbnail, 'large');
        $img_alt = get_post_meta($thumbnail, '_wp_attachment_image_alt', true);
        if ($img_data) {
            $image = [
                'id' => $thumbnail,
                'url' => $img_data[0],
                'alt' => $img_alt,
                'width' => $img_data[1],
                'height' => $img_data[2],
            ];
        }
    }

    // Format background image
    $background = null;
    if ($hero_background) {
        $background = [
            'id' => $hero_background['ID'],
            'url' => $hero_background['url'],
            'alt' => $hero_background['alt'],
            'width' => $hero_background['width'],
            'height' => $hero_background['height'],
            'sizes' => $hero_background['sizes'] ?? null,
        ];
    }

    // Format mobile background image
    $hero_background_mobile = get_field('hero_background_mobile', $post->ID);
    $background_mobile = null;
    if ($hero_background_mobile) {
        $background_mobile = [
            'id' => $hero_background_mobile['ID'],
            'url' => $hero_background_mobile['url'],
            'alt' => $hero_background_mobile['alt'],
            'width' => $hero_background_mobile['width'],
            'height' => $hero_background_mobile['height'],
            'sizes' => $hero_background_mobile['sizes'] ?? null,
        ];
    }

    return [
        'id' => $post->ID,
        'title' => get_the_title($post->ID),
        'slug' => $post->post_name,
        'client_name' => get_field('client_name', $post->ID) ?: '',
        'short_description' => get_field('short_description', $post->ID) ?: '',
        'hero_image' => $image,
        'hero_background' => $background,
        'hero_background_mobile' => $background_mobile,
        'hero_label' => get_field('hero_label', $post->ID) ?: 'Post-Rebrand',
        'result_percent' => get_field('result_percent', $post->ID) ?: '',
        'result_label' => get_field('result_label', $post->ID) ?: '',
    ];
}

/**
 * Format success story for full view
 */
function format_success_story_full($post) {
    $card = format_success_story_card($post);
    
    // Get stats
    $stats_raw = get_field('stats', $post->ID);
    $stats = [];
    if ($stats_raw && is_array($stats_raw)) {
        foreach ($stats_raw as $stat) {
            $stats[] = [
                'value' => $stat['value'] ?? '',
                'label' => $stat['label'] ?? '',
            ];
        }
    }

    // Get gallery
    $gallery_raw = get_field('gallery', $post->ID);
    $gallery = [];
    if ($gallery_raw && is_array($gallery_raw)) {
        foreach ($gallery_raw as $img) {
            $gallery[] = [
                'id' => $img['ID'],
                'url' => $img['url'],
                'alt' => $img['alt'],
                'width' => $img['width'],
                'height' => $img['height'],
                'sizes' => $img['sizes'] ?? null,
            ];
        }
    }

    // Get adjacent posts
    $prev_post = get_adjacent_post(false, '', true, 'category');
    $next_post = get_adjacent_post(false, '', false, 'category');

    return array_merge($card, [
        'content' => get_field('content', $post->ID) ?: '',
        'stats' => $stats,
        'gallery' => $gallery,
        'previous' => $prev_post ? [
            'title' => get_the_title($prev_post->ID),
            'slug' => $prev_post->post_name,
        ] : null,
        'next' => $next_post ? [
            'title' => get_the_title($next_post->ID),
            'slug' => $next_post->post_name,
        ] : null,
    ]);
}

/**
 * Get all success stories
 */
function adwrap_get_success_stories($request) {
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');

    $args = [
        'post_type' => 'success_story',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    $query = new WP_Query($args);
    $stories = [];

    foreach ($query->posts as $post) {
        $stories[] = format_success_story_card($post);
    }

    return rest_ensure_response([
        'data' => $stories,
        'total' => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'current_page' => $page,
    ]);
}

/**
 * Get single success story by slug
 */
function adwrap_get_success_story($request) {
    $slug = $request->get_param('slug');

    $args = [
        'post_type' => 'success_story',
        'post_status' => 'publish',
        'name' => $slug,
        'posts_per_page' => 1,
    ];

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $post = $query->posts[0];
        return rest_ensure_response(format_success_story_full($post));
    }

    return new WP_Error('not_found', 'Success story not found', ['status' => 404]);
}

/**
 * Get all success story slugs for static generation
 */
add_action('rest_api_init', function() {
    register_rest_route('adwrap/v1', '/success-stories-slugs', [
        'methods' => 'GET',
        'callback' => function() {
            $posts = get_posts([
                'post_type' => 'success_story',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);

            $slugs = [];
            foreach ($posts as $post_id) {
                $post = get_post($post_id);
                $slugs[] = ['slug' => $post->post_name];
            }

            return rest_ensure_response($slugs);
        },
        'permission_callback' => '__return_true',
    ]);
});

