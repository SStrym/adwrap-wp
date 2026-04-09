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
            'supports'           => ['title', 'thumbnail', 'excerpt', 'page-attributes', 'revisions'],
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
        $fields = get_fields($post->ID) ?: [];
        $card = $fields['card'] ?? [];
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
        $fields = get_fields($post->ID) ?: [];
        $thumbnail = get_the_post_thumbnail_url($post->ID, 'full');

        return [
            'id'               => $post->ID,
            'title'            => $post->post_title,
            'slug'             => $post->post_name,
            'status'           => $post->post_status,
            'link'             => get_permalink($post->ID),
            'excerpt'          => $post->post_excerpt,
            'featured_image'   => $thumbnail ?: null,
            'hero'             => $fields['hero'] ?? [],
            'card'             => $fields['card'] ?? [],
            'show_benefits'    => (bool) ($fields['show_benefits'] ?? false),
            'benefits'         => $fields['benefits'] ?? [],
            'show_services_list' => (bool) ($fields['show_services_list'] ?? false),
            'services_list'    => $fields['services_list'] ?? [],
            'content_sections' => $fields['content_sections'] ?? [],
            'gallery'          => $fields['gallery'] ?? [],
            'cta'              => $fields['cta'] ?? [],
            'yoast_head_json'  => function_exists('adwrap_get_post_seo') ? adwrap_get_post_seo($post) : null,
        ];
    }

    /**
     * Get Yoast SEO head JSON for a post (same format as yoast_head_json)
     *
     * @return array<string, mixed>|null
     */
    private function get_yoast_head_json(int $post_id): ?array
    {
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
        } catch (\Exception $e) {
            return null;
        }
    }
}

new AdwrapServices();

