<?php

/**
 * Plugin Name: Adwrap Portfolio
 * Description: Custom Post Type, Taxonomies and REST API for Portfolio
 */

final class AdwrapPortfolio
{
    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomies']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the Portfolio custom post type
     */
    public function register_post_type(): void
    {
        $labels = [
            'name'                  => 'Portfolio',
            'singular_name'         => 'Portfolio Item',
            'menu_name'             => 'Portfolio',
            'name_admin_bar'        => 'Portfolio Item',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Portfolio Item',
            'new_item'              => 'New Portfolio Item',
            'edit_item'             => 'Edit Portfolio Item',
            'view_item'             => 'View Portfolio Item',
            'all_items'             => 'All Portfolio Items',
            'search_items'          => 'Search Portfolio',
            'parent_item_colon'     => 'Parent Portfolio:',
            'not_found'             => 'No portfolio items found.',
            'not_found_in_trash'    => 'No portfolio items found in Trash.',
            'featured_image'        => 'Portfolio Image',
            'set_featured_image'    => 'Set portfolio image',
            'remove_featured_image' => 'Remove portfolio image',
            'use_featured_image'    => 'Use as portfolio image',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => ['slug' => 'portfolio', 'with_front' => false],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 6,
            'menu_icon'          => 'dashicons-images-alt2',
            'supports'           => ['title', 'thumbnail', 'excerpt', 'page-attributes'],
            'show_in_rest'       => true,
            'rest_base'          => 'portfolio',
        ];

        register_post_type('portfolio', $args);
    }

    /**
     * Register taxonomies for Portfolio
     */
    public function register_taxonomies(): void
    {
        // Capability taxonomy
        register_taxonomy('portfolio_capability', 'portfolio', [
            'labels' => [
                'name'          => 'Capabilities',
                'singular_name' => 'Capability',
                'search_items'  => 'Search Capabilities',
                'all_items'     => 'All Capabilities',
                'edit_item'     => 'Edit Capability',
                'update_item'   => 'Update Capability',
                'add_new_item'  => 'Add New Capability',
                'new_item_name' => 'New Capability Name',
                'menu_name'     => 'Capabilities',
            ],
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'capability'],
            'show_in_rest'      => true,
        ]);

        // Industry taxonomy
        register_taxonomy('portfolio_industry', 'portfolio', [
            'labels' => [
                'name'          => 'Industries',
                'singular_name' => 'Industry',
                'search_items'  => 'Search Industries',
                'all_items'     => 'All Industries',
                'edit_item'     => 'Edit Industry',
                'update_item'   => 'Update Industry',
                'add_new_item'  => 'Add New Industry',
                'new_item_name' => 'New Industry Name',
                'menu_name'     => 'Industries',
            ],
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'industry'],
            'show_in_rest'      => true,
        ]);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void
    {
        // GET all portfolio items (for listing page)
        register_rest_route('adwrap/v1', '/portfolio', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_portfolio_items'],
            'permission_callback' => '__return_true',
            'args'                => [
                'capability' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'industry' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'search' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                ],
                'per_page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 12,
                ],
            ],
        ]);

        // GET single portfolio item by slug (for detail page)
        register_rest_route('adwrap/v1', '/portfolio/(?P<slug>[a-zA-Z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_portfolio_item'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // GET taxonomies (capabilities and industries)
        register_rest_route('adwrap/v1', '/portfolio-taxonomies', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_taxonomies'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get all portfolio items with filtering and pagination
     *
     * @return WP_REST_Response
     */
    public function get_portfolio_items(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $capability = $request->get_param('capability');
        $industry = $request->get_param('industry');
        $search = $request->get_param('search');

        $args = [
            'post_type'      => 'portfolio',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Add taxonomy filters
        $tax_query = [];

        if (!empty($capability)) {
            $capabilities = array_map('trim', explode(',', $capability));
            $tax_query[] = [
                'taxonomy' => 'portfolio_capability',
                'field'    => 'slug',
                'terms'    => $capabilities,
            ];
        }

        if (!empty($industry)) {
            $industries = array_map('trim', explode(',', $industry));
            $tax_query[] = [
                'taxonomy' => 'portfolio_industry',
                'field'    => 'slug',
                'terms'    => $industries,
            ];
        }

        if (!empty($tax_query)) {
            $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }

        // Add search
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $items = array_map([$this, 'format_portfolio_card'], $query->posts);

        $response = new WP_REST_Response([
            'items'       => $items,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ]);

        $response->header('X-WP-Total', $query->found_posts);
        $response->header('X-WP-TotalPages', $query->max_num_pages);

        return $response;
    }

    /**
     * Get single portfolio item by slug
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_portfolio_item(WP_REST_Request $request): array|WP_Error
    {
        $slug = $request->get_param('slug');

        $posts = get_posts([
            'post_type'      => 'portfolio',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);

        if (empty($posts)) {
            return new WP_Error(
                'not_found',
                'Portfolio item not found',
                ['status' => 404]
            );
        }

        $current = $posts[0];
        $data = $this->format_portfolio_full($current);

        // Get adjacent posts
        $data['prev_item'] = $this->get_adjacent_portfolio($current->ID, 'prev');
        $data['next_item'] = $this->get_adjacent_portfolio($current->ID, 'next');

        return $data;
    }

    /**
     * Get taxonomies for filtering
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function get_taxonomies(): array
    {
        return [
            'capabilities' => $this->format_terms(get_terms([
                'taxonomy'   => 'portfolio_capability',
                'hide_empty' => true,
            ])),
            'industries' => $this->format_terms(get_terms([
                'taxonomy'   => 'portfolio_industry',
                'hide_empty' => true,
            ])),
        ];
    }

    /**
     * Format terms array
     *
     * @param WP_Term[]|WP_Error $terms
     * @return array<int, array<string, mixed>>
     */
    private function format_terms(array|WP_Error $terms): array
    {
        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(function (WP_Term $term): array {
            return [
                'id'    => $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => $term->count,
            ];
        }, $terms);
    }

    /**
     * Get adjacent portfolio item
     *
     * @return array<string, mixed>|null
     */
    private function get_adjacent_portfolio(int $post_id, string $direction): ?array
    {
        global $post;
        $old_post = $post;
        $post = get_post($post_id);

        setup_postdata($post);

        $adjacent = $direction === 'prev' 
            ? get_previous_post() 
            : get_next_post();

        wp_reset_postdata();
        $post = $old_post;

        if (!$adjacent) {
            return null;
        }

        return [
            'id'    => $adjacent->ID,
            'title' => $adjacent->post_title,
            'slug'  => $adjacent->post_name,
        ];
    }

    /**
     * Format image attachment to ACF-like array
     *
     * @return array<string, mixed>|null
     */
    private function format_image(int $attachment_id): ?array
    {
        if (!$attachment_id) {
            return null;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        $sizes = [];

        if (!empty($meta['sizes'])) {
            $upload_dir = wp_upload_dir();
            $base_url = trailingslashit($upload_dir['baseurl']);
            $file_path = dirname($meta['file']);

            foreach ($meta['sizes'] as $size_name => $size_data) {
                $sizes[$size_name] = $base_url . $file_path . '/' . $size_data['file'];
                $sizes[$size_name . '-width'] = $size_data['width'];
                $sizes[$size_name . '-height'] = $size_data['height'];
            }
        }

        return [
            'ID'        => $attachment_id,
            'id'        => $attachment_id,
            'title'     => $attachment->post_title,
            'filename'  => basename(get_attached_file($attachment_id) ?: ''),
            'url'       => $url,
            'alt'       => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'width'     => $meta['width'] ?? 0,
            'height'    => $meta['height'] ?? 0,
            'mime_type' => get_post_mime_type($attachment_id),
            'sizes'     => $sizes,
        ];
    }

    /**
     * Format portfolio for card display (listing page)
     *
     * @return array<string, mixed>
     */
    private function format_portfolio_card(WP_Post $post): array
    {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $capabilities = wp_get_post_terms($post->ID, 'portfolio_capability', ['fields' => 'all']);
        $industries = wp_get_post_terms($post->ID, 'portfolio_industry', ['fields' => 'all']);

        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'excerpt'      => $post->post_excerpt,
            'image'        => $this->format_image((int) $thumbnail_id),
            'capabilities' => $this->format_terms($capabilities),
            'industries'   => $this->format_terms($industries),
            'date'         => $post->post_date,
        ];
    }

    /**
     * Format portfolio for full display (detail page)
     *
     * @return array<string, mixed>
     */
    private function format_portfolio_full(WP_Post $post): array
    {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $capabilities = wp_get_post_terms($post->ID, 'portfolio_capability', ['fields' => 'all']);
        $industries = wp_get_post_terms($post->ID, 'portfolio_industry', ['fields' => 'all']);
        
        // Get gallery images
        $gallery = get_field('gallery', $post->ID) ?: [];
        $formatted_gallery = [];
        
        if (!empty($gallery)) {
            foreach ($gallery as $image) {
                if (is_array($image) && isset($image['ID'])) {
                    $formatted_gallery[] = $image;
                } elseif (is_numeric($image)) {
                    $formatted_gallery[] = $this->format_image((int) $image);
                }
            }
        }

        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'excerpt'      => $post->post_excerpt,
            'content'      => apply_filters('the_content', $post->post_content),
            'image'        => $this->format_image((int) $thumbnail_id),
            'gallery'      => $formatted_gallery,
            'capabilities' => $this->format_terms($capabilities),
            'industries'   => $this->format_terms($industries),
            'client_name'  => get_field('client_name', $post->ID) ?: '',
            'date'         => $post->post_date,
        ];
    }
}

new AdwrapPortfolio();

