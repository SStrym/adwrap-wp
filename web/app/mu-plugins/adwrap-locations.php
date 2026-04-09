<?php

/**
 * Plugin Name: Adwrap Locations
 * Description: Custom Post Type and REST API for Location (Local SEO) pages
 */

final class AdwrapLocations
{
    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('acf/settings/load_json', [$this, 'add_acf_json_load_path']);
        add_filter('wp_insert_post_data', [$this, 'auto_generate_title'], 10, 2);
        add_action('acf/save_post', [$this, 'update_title_on_acf_save'], 15);
        add_action('acf/save_post', [$this, 'auto_generate_slugs_on_save'], 16);
        add_action('acf/save_post', [$this, 'auto_geocode_on_save'], 17);
    }

    public function add_acf_json_load_path(array $paths): array
    {
        $paths[] = __DIR__ . '/acf-json';
        return $paths;
    }

    public function auto_generate_title(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'location') {
            return $data;
        }

        $title = $this->build_title_from_post($postarr);
        if ($title) {
            $data['post_title'] = $title;
            $data['post_name']  = sanitize_title($title);
        }

        return $data;
    }

    public function update_title_on_acf_save(int $post_id): void
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'location') {
            return;
        }

        $title = $this->build_title_from_fields($post_id);
        if ($title && $title !== $post->post_title) {
            remove_filter('wp_insert_post_data', [$this, 'auto_generate_title'], 10);
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $title,
                'post_name'  => sanitize_title($title),
            ]);
            add_filter('wp_insert_post_data', [$this, 'auto_generate_title'], 10, 2);
        }
    }

    public function auto_generate_slugs_on_save(int $post_id): void
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'location') {
            return;
        }

        $slug_pairs = [
            ['label' => 'service_label', 'slug' => 'service_slug'],
            ['label' => 'state_label',   'slug' => 'state'],
            ['label' => 'city_label',    'slug' => 'city'],
            ['label' => 'suburb_label',  'slug' => 'suburb'],
        ];

        foreach ($slug_pairs as $pair) {
            $label = get_field($pair['label'], $post_id) ?: '';
            $slug  = get_field($pair['slug'], $post_id) ?: '';

            if ($label && !$slug) {
                update_field($pair['slug'], sanitize_title($label), $post_id);
            }
        }

        $state_label = get_field('state_label', $post_id) ?: '';
        $state_abbr  = get_field('state_abbreviation', $post_id) ?: '';
        if ($state_label && !$state_abbr) {
            $abbr = $this->state_abbreviation($state_label);
            if ($abbr) {
                update_field('state_abbreviation', $abbr, $post_id);
            }
        }
    }

    public function auto_geocode_on_save(int $post_id): void
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'location') {
            return;
        }

        $lat = get_field('latitude', $post_id);
        $lng = get_field('longitude', $post_id);
        if ($lat && $lng) {
            return;
        }

        $city   = get_field('city_label', $post_id) ?: '';
        $state  = get_field('state_abbreviation', $post_id) ?: get_field('state_label', $post_id) ?: '';
        $suburb = get_field('suburb_label', $post_id) ?: '';

        $place = $suburb ?: $city;
        if (!$place || !$state) {
            return;
        }

        $coords = $this->geocode("{$place}, {$state}, USA");
        if ($coords) {
            update_field('latitude', $coords['lat'], $post_id);
            update_field('longitude', $coords['lng'], $post_id);
        }
    }

    private function geocode(string $query): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'            => $query,
            'format'       => 'json',
            'limit'        => 1,
            'countrycodes' => 'us',
        ]);

        $response = wp_remote_get($url, [
            'headers' => ['User-Agent' => 'AdWrap-WordPress/1.0'],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body[0]['lat']) || empty($body[0]['lon'])) {
            return null;
        }

        return [
            'lat' => round((float) $body[0]['lat'], 6),
            'lng' => round((float) $body[0]['lon'], 6),
        ];
    }

    private function state_abbreviation(string $state_name): string
    {
        $map = [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
            'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
            'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
            'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
            'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
            'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
            'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
            'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
            'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
            'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
            'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
            'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
            'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
        ];

        return $map[strtolower(trim($state_name))] ?? '';
    }

    private function build_title_from_post(array $postarr): string
    {
        $fields = $postarr['acf'] ?? [];
        if (empty($fields)) {
            return '';
        }

        $service = $this->resolve_field($fields, 'field_location_service_label');
        $city    = $this->resolve_field($fields, 'field_location_city_label');
        $state   = $this->resolve_field($fields, 'field_location_state_abbr');
        $suburb  = $this->resolve_field($fields, 'field_location_suburb_label');

        return $this->format_title($service, $city, $state, $suburb);
    }

    private function build_title_from_fields(int $post_id): string
    {
        $service = get_field('service_label', $post_id) ?: '';
        $city    = get_field('city_label', $post_id) ?: '';
        $state   = get_field('state_abbreviation', $post_id) ?: '';
        $suburb  = get_field('suburb_label', $post_id) ?: '';

        return $this->format_title($service, $city, $state, $suburb);
    }

    private function format_title(string $service, string $city, string $state, string $suburb): string
    {
        if (!$service || !$city || !$state) {
            return '';
        }

        $place = $suburb ?: $city;

        return "{$service} in {$place}, {$state}";
    }

    private function resolve_field(array $acf_data, string $field_key): string
    {
        return isset($acf_data[$field_key]) ? trim((string) $acf_data[$field_key]) : '';
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'                  => 'Locations',
            'singular_name'         => 'Location',
            'menu_name'             => 'Locations',
            'name_admin_bar'        => 'Location',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Location',
            'new_item'              => 'New Location',
            'edit_item'             => 'Edit Location',
            'view_item'             => 'View Location',
            'all_items'             => 'All Locations',
            'search_items'          => 'Search Locations',
            'not_found'             => 'No locations found.',
            'not_found_in_trash'    => 'No locations found in Trash.',
            'featured_image'        => 'Location Image',
            'set_featured_image'    => 'Set location image',
            'remove_featured_image' => 'Remove location image',
            'use_featured_image'    => 'Use as location image',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'exclude_from_search' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 7,
            'menu_icon'          => 'dashicons-location-alt',
            'supports'           => ['title', 'thumbnail', 'revisions'],
            'show_in_rest'       => true,
            'rest_base'          => 'locations',
        ];

        register_post_type('location', $args);
    }

    public function register_routes(): void
    {
        $slug = '[a-z0-9-]+';

        register_rest_route('adwrap/v1', '/locations', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_all_locations'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('adwrap/v1', "/locations/(?P<service>{$slug})/(?P<state>{$slug})/(?P<city>{$slug})/(?P<suburb>{$slug})", [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_location'],
            'permission_callback' => '__return_true',
            'args'                => $this->sanitize_args(['service', 'state', 'city', 'suburb']),
        ]);

        register_rest_route('adwrap/v1', "/locations/(?P<service>{$slug})/(?P<state>{$slug})/(?P<city>{$slug})", [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_location'],
            'permission_callback' => '__return_true',
            'args'                => $this->sanitize_args(['service', 'state', 'city']),
        ]);

        register_rest_route('adwrap/v1', "/locations/(?P<service>{$slug})/(?P<state>{$slug})", [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_locations_by_service_state'],
            'permission_callback' => '__return_true',
            'args'                => $this->sanitize_args(['service', 'state']),
        ]);

        register_rest_route('adwrap/v1', "/locations/(?P<service>{$slug})", [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_locations_by_service'],
            'permission_callback' => '__return_true',
            'args'                => $this->sanitize_args(['service']),
        ]);

        register_rest_route('adwrap/v1', '/location-slugs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_location_slugs'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function sanitize_args(array $keys): array
    {
        $args = [];
        foreach ($keys as $key) {
            $args[$key] = [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ];
        }
        return $args;
    }

    public function get_all_locations(): array
    {
        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        return array_map([$this, 'format_location_card'], $posts);
    }

    public function get_locations_by_service(WP_REST_Request $request): array|WP_Error
    {
        $service = $request->get_param('service');

        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => 'service_slug', 'value' => $service, 'compare' => '='],
            ],
            'orderby' => 'title',
            'order'   => 'ASC',
        ]);

        if (empty($posts)) {
            return new WP_Error('not_found', 'No locations found for this service', ['status' => 404]);
        }

        return array_map([$this, 'format_location_card'], $posts);
    }

    public function get_locations_by_service_state(WP_REST_Request $request): array|WP_Error
    {
        $service = $request->get_param('service');
        $state   = $request->get_param('state');

        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'service_slug', 'value' => $service, 'compare' => '='],
                ['key' => 'state',        'value' => $state,   'compare' => '='],
            ],
            'orderby' => 'title',
            'order'   => 'ASC',
        ]);

        if (empty($posts)) {
            return new WP_Error('not_found', 'No locations found for this service and state', ['status' => 404]);
        }

        return array_map([$this, 'format_location_card'], $posts);
    }

    public function get_location(WP_REST_Request $request): array|WP_Error
    {
        $service = $request->get_param('service');
        $state   = $request->get_param('state');
        $city    = $request->get_param('city');
        $suburb  = $request->get_param('suburb') ?: '';

        $meta_query = [
            'relation' => 'AND',
            ['key' => 'service_slug', 'value' => $service, 'compare' => '='],
            ['key' => 'state',        'value' => $state,   'compare' => '='],
            ['key' => 'city',         'value' => $city,    'compare' => '='],
        ];

        if ($suburb) {
            $meta_query[] = ['key' => 'suburb', 'value' => $suburb, 'compare' => '='];
        } else {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => 'suburb', 'value' => '', 'compare' => '='],
                ['key' => 'suburb', 'compare' => 'NOT EXISTS'],
            ];
        }

        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
        ]);

        if (empty($posts)) {
            return new WP_Error('not_found', 'Location not found', ['status' => 404]);
        }

        return $this->format_location_full($posts[0]);
    }

    public function get_location_slugs(): array
    {
        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ]);

        return array_values(array_filter(array_map(function (WP_Post $post): ?array {
            $fields  = get_fields($post->ID) ?: [];
            $service = $fields['service_slug'] ?? '';
            $state   = $fields['state'] ?? '';
            $city    = $fields['city'] ?? '';

            if (!$service || !$state || !$city) {
                return null;
            }

            $entry = [
                'service' => $service,
                'state'   => $state,
                'city'    => $city,
            ];

            $suburb = $fields['suburb'] ?? '';
            if ($suburb) {
                $entry['suburb'] = $suburb;
            }

            return $entry;
        }, $posts)));
    }

    private function format_location_card(WP_Post $post): array
    {
        $fields = get_fields($post->ID) ?: [];
        $suburb = $fields['suburb'] ?? '';

        return [
            'id'                 => $post->ID,
            'service_slug'       => $fields['service_slug'] ?? '',
            'service_label'      => $fields['service_label'] ?? '',
            'state'              => $fields['state'] ?? '',
            'state_label'        => $fields['state_label'] ?? '',
            'city'               => $fields['city'] ?? '',
            'city_label'         => $fields['city_label'] ?? '',
            'suburb'             => $suburb,
            'suburb_label'       => $suburb ? ($fields['suburb_label'] ?? '') : '',
            'state_abbreviation' => $fields['state_abbreviation'] ?? '',
            'featured_image'     => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
        ];
    }

    private function format_location_full(WP_Post $post): array
    {
        $fields = get_fields($post->ID) ?: [];
        $lat = $fields['latitude'] ?? null;
        $lng = $fields['longitude'] ?? null;
        $coordinates = ($lat && $lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
        $suburb = $fields['suburb'] ?? '';

        return [
            'id'                 => $post->ID,
            'title'              => $post->post_title,
            'service_slug'       => $fields['service_slug'] ?? '',
            'service_label'      => $fields['service_label'] ?? '',
            'state'              => $fields['state'] ?? '',
            'state_label'        => $fields['state_label'] ?? '',
            'city'               => $fields['city'] ?? '',
            'city_label'         => $fields['city_label'] ?? '',
            'suburb'             => $suburb,
            'suburb_label'       => $suburb ? ($fields['suburb_label'] ?? '') : '',
            'state_abbreviation' => $fields['state_abbreviation'] ?? '',
            'coordinates'        => $coordinates,
            'featured_image'     => get_the_post_thumbnail_url($post->ID, 'full') ?: null,
            'location_blocks'    => $fields['location_blocks'] ?? [],
            'yoast_head_json'    => function_exists('adwrap_get_post_seo') ? adwrap_get_post_seo($post) : null,
        ];
    }
}

new AdwrapLocations();
