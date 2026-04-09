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
            'supports'           => ['title', 'thumbnail', 'excerpt', 'page-attributes', 'revisions'],
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

        // GET taxonomies (industries)
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

        // Apply filters to get bucket URL
        $url = apply_filters('wp_get_attachment_url', wp_get_attachment_url($attachment_id), $attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        $sizes = [];

        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_name => $size_data) {
                // Use wp_get_attachment_image_url with filters for bucket URL
                $size_url = wp_get_attachment_image_url($attachment_id, $size_name);
                if ($size_url) {
                    $sizes[$size_name] = apply_filters('wp_get_attachment_url', $size_url, $attachment_id);
                    $sizes[$size_name . '-width'] = $size_data['width'];
                    $sizes[$size_name . '-height'] = $size_data['height'];
                }
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
        $industries = wp_get_post_terms($post->ID, 'portfolio_industry', ['fields' => 'all']);

        // Get ACF fields in one call
        $fields = get_fields($post->ID) ?: [];
        $before_image = $fields['before'] ?? null;
        $before_formatted = null;

        if ($before_image) {
            if (is_array($before_image) && isset($before_image['ID'])) {
                $before_formatted = $this->format_image((int) $before_image['ID']);
            } elseif (is_numeric($before_image)) {
                $before_formatted = $this->format_image((int) $before_image);
            }
        }

        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'excerpt'      => $post->post_excerpt,
            'before'       => $before_formatted,
            'image'        => $this->format_image((int) $thumbnail_id),
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
        $industries = wp_get_post_terms($post->ID, 'portfolio_industry', ['fields' => 'all']);

        // Get all ACF fields in one call
        $fields = get_fields($post->ID) ?: [];
        $before_image = $fields['before'] ?? null;
        $before_formatted = null;

        if ($before_image) {
            if (is_array($before_image) && isset($before_image['ID'])) {
                $before_formatted = $this->format_image((int) $before_image['ID']);
            } elseif (is_numeric($before_image)) {
                $before_formatted = $this->format_image((int) $before_image);
            }
        }

        // Get gallery images
        $gallery = $fields['gallery'] ?? [];
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
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => $post->post_status,
            'link'            => get_permalink($post->ID),
            'excerpt'         => $post->post_excerpt,
            'content'         => apply_filters('the_content', $post->post_content),
            'before'          => $before_formatted,
            'image'           => $this->format_image((int) $thumbnail_id),
            'gallery'         => $formatted_gallery,
            'industries'      => $this->format_terms($industries),
            'client_name'     => $fields['client_name'] ?? '',
            'date'            => $post->post_date,
            'yoast_head_json' => function_exists('adwrap_get_post_seo') ? adwrap_get_post_seo($post) : null,
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

new AdwrapPortfolio();

