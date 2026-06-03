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
    $fields = get_fields($post->ID) ?: [];
    $hero_image = $fields['hero_image'] ?? null;
    $hero_background = $fields['hero_background'] ?? null;
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
    $hero_background_mobile = $fields['hero_background_mobile'] ?? null;
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

    // Get first 2 gallery images
    $gallery = $fields['gallery'] ?? [];
    $gallery_images = [];
    if (!empty($gallery)) {
        foreach (array_slice($gallery, 0, 2) as $img) {
            $gallery_images[] = [
                'id' => $img['ID'],
                'url' => $img['url'],
                'alt' => $img['alt'],
                'width' => $img['width'],
                'height' => $img['height'],
                'sizes' => $img['sizes'] ?? null,
            ];
        }
    }

    return [
        'id' => $post->ID,
        'title' => get_the_title($post->ID),
        'slug' => $post->post_name,
        'client_name' => $fields['client_name'] ?? '',
        'short_description' => $fields['short_description'] ?? '',
        'hero_image' => $image,
        'hero_background' => $background,
        'hero_background_mobile' => $background_mobile,
        'hero_label' => $fields['hero_label'] ?: 'Post-Rebrand',
        'result_percent' => $fields['result_percent'] ?? '',
        'result_label' => $fields['result_label'] ?? '',
        'gallery_preview' => $gallery_images,
    ];
}

/**
 * Format success story for full view
 */
function format_success_story_full($post) {
    $card = format_success_story_card($post);
    $fields = get_fields($post->ID) ?: [];

    // Get stats
    $stats_raw = $fields['stats'] ?? [];
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
    $gallery_raw = $fields['gallery'] ?? [];
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

    // Get adjacent posts (date-adjacent within the success_story CPT).
    // get_adjacent_post() relies on the global $post, which isn't set in a REST
    // context — establish it first. in_same_term=false → no taxonomy needed
    // (the CPT registers none), so neighbours are found by date.
    $GLOBALS['post'] = $post;
    setup_postdata($post);
    $prev_post = get_adjacent_post(false, '', true, '');
    $next_post = get_adjacent_post(false, '', false, '');
    wp_reset_postdata();

    return array_merge($card, [
        'status' => $post->post_status,
        'link' => get_permalink($post->ID),
        'content' => $fields['content'] ?? '',
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
        'yoast_head_json' => function_exists('adwrap_get_post_seo') ? adwrap_get_post_seo(get_post($post->ID)) : null,
    ]);
}

/**
 * Get Yoast SEO head JSON for a post (same format as yoast_head_json)
 */
function adwrap_get_yoast_head_json($post_id) {
    if (!class_exists('Yoast\WP\SEO\Surfaces\Meta_Surface')) {
        return null;
    }

    try {
        $meta = YoastSEO()->meta->for_post($post_id);
        if (!$meta) {
            return null;
        }

        $og_images = [];
        if (!empty($meta->open_graph_images)) {
            foreach ($meta->open_graph_images as $image) {
                $og_images[] = [
                    'width'  => $image['width'] ?? 0,
                    'height' => $image['height'] ?? 0,
                    'url'    => $image['url'] ?? '',
                    'type'   => $image['type'] ?? '',
                ];
            }
        }

        $robots = $meta->robots ?? [];
        $robots_formatted = [];
        if (is_array($robots)) {
            foreach ($robots as $key => $value) {
                if ($value === true || $value === 'index' || $value === 'follow') {
                    $robots_formatted[$key] = $key;
                } elseif ($value === false || $value === 'noindex' || $value === 'nofollow') {
                    $robots_formatted[$key] = 'no' . $key;
                } elseif (is_string($value)) {
                    $robots_formatted[$key] = $value;
                }
            }
        }

        $result = [
            'title'                 => $meta->title ?? '',
            'description'           => $meta->description ?? '',
            'robots'                => $robots_formatted,
            'canonical'             => $meta->canonical ?? '',
            'og_locale'             => $meta->open_graph_locale ?? 'en_US',
            'og_type'               => $meta->open_graph_type ?? 'article',
            'og_title'              => $meta->open_graph_title ?? '',
            'og_description'        => $meta->open_graph_description ?? '',
            'og_url'                => $meta->open_graph_url ?? '',
            'og_site_name'          => $meta->open_graph_site_name ?? '',
            'article_modified_time' => $meta->open_graph_article_modified_time ?? '',
            'article_published_time'=> $meta->open_graph_article_published_time ?? '',
            'og_image'              => $og_images,
            'twitter_card'          => $meta->twitter_card ?? 'summary_large_image',
            'twitter_title'         => $meta->twitter_title ?? '',
            'twitter_description'   => $meta->twitter_description ?? '',
            'twitter_image'         => $meta->twitter_image ?? '',
            'schema'                => $meta->schema ?? null,
        ];

        return $result;
    } catch (Exception $e) {
        return null;
    }
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
            ]);

            $slugs = array_map(fn(WP_Post $post) => ['slug' => $post->post_name], $posts);

            return rest_ensure_response($slugs);
        },
        'permission_callback' => '__return_true',
    ]);
});

