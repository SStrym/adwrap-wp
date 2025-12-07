<?php

/**
 * Plugin Name: Adwrap Services
 * Description: Custom Post Type and REST API for Services
 */

final class AdwrapServices
{
    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the Service custom post type
     */
    public function register_post_type(): void
    {
        $labels = [
            'name'                  => 'Services',
            'singular_name'         => 'Service',
            'menu_name'             => 'Services',
            'name_admin_bar'        => 'Service',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Service',
            'new_item'              => 'New Service',
            'edit_item'             => 'Edit Service',
            'view_item'             => 'View Service',
            'all_items'             => 'All Services',
            'search_items'          => 'Search Services',
            'parent_item_colon'     => 'Parent Services:',
            'not_found'             => 'No services found.',
            'not_found_in_trash'    => 'No services found in Trash.',
            'featured_image'        => 'Service Image',
            'set_featured_image'    => 'Set service image',
            'remove_featured_image' => 'Remove service image',
            'use_featured_image'    => 'Use as service image',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => ['slug' => 'services', 'with_front' => false],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-admin-appearance',
            'supports'           => ['title', 'thumbnail', 'excerpt', 'page-attributes'],
            'show_in_rest'       => true,
            'rest_base'          => 'services',
        ];

        register_post_type('service', $args);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void
    {
        // GET all services (for listing page)
        register_rest_route('adwrap/v1', '/services', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_services'],
            'permission_callback' => '__return_true',
        ]);

        // GET single service by slug (for detail page)
        register_rest_route('adwrap/v1', '/services/(?P<slug>[a-zA-Z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_service'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);
    }

    /**
     * Get all services for listing
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_services(): array
    {
        $posts = get_posts([
            'post_type'      => 'service',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        return array_map([$this, 'format_service_card'], $posts);
    }

    /**
     * Get single service by slug
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_service(WP_REST_Request $request): array|WP_Error
    {
        $slug = $request->get_param('slug');

        $posts = get_posts([
            'post_type'      => 'service',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);

        if (empty($posts)) {
            return new WP_Error(
                'not_found',
                'Service not found',
                ['status' => 404]
            );
        }

        return $this->format_service_full($posts[0]);
    }

    /**
     * Format service for card display (listing page)
     *
     * @return array<string, mixed>
     */
    private function format_service_card(WP_Post $post): array
    {
        $card = get_field('card', $post->ID) ?: [];
        $thumbnail = get_the_post_thumbnail_url($post->ID, 'large');

        return [
            'id'               => $post->ID,
            'title'            => $post->post_title,
            'slug'             => $post->post_name,
            'excerpt'          => $post->post_excerpt,
            'featured_image'   => $thumbnail ?: null,
            'card_image'       => $card['card_image'] ?? null,
            'card_description' => $card['card_description'] ?? '',
            'menu_order'       => $post->menu_order,
        ];
    }

    /**
     * Format service for full display (detail page)
     *
     * @return array<string, mixed>
     */
    private function format_service_full(WP_Post $post): array
    {
        $thumbnail = get_the_post_thumbnail_url($post->ID, 'full');

        return [
            'id'               => $post->ID,
            'title'            => $post->post_title,
            'slug'             => $post->post_name,
            'excerpt'          => $post->post_excerpt,
            'featured_image'   => $thumbnail ?: null,
            'hero'             => get_field('hero', $post->ID) ?: [],
            'card'             => get_field('card', $post->ID) ?: [],
            'benefits'         => get_field('benefits', $post->ID) ?: [],
            'content_sections' => get_field('content_sections', $post->ID) ?: [],
            'gallery'          => get_field('gallery', $post->ID) ?: [],
            'cta'              => get_field('cta', $post->ID) ?: [],
        ];
    }
}

new AdwrapServices();

