<?php

/**
 * Security and Access Control
 * --------------------------
 * Functions for managing access and security settings
 */

class HeadlessWPSecurity {
    public function __construct() {
        // Core WordPress features
        add_theme_support('post-thumbnails');
        
        // Enable navigation menus
        add_theme_support('menus');
        register_nav_menus([
            'primary' => 'Primary Menu',
            'footer' => 'Footer Menu',
        ]);

        // Initialize all security measures
        $this->init_robots_blocking();
        $this->init_access_control();
        $this->init_header_cleanup();
    }

    /**
     * Initialize robots and search engine blocking
     */
    private function init_robots_blocking() {
        // Robots.txt blocking
        add_action('init', [$this, 'handle_robots_txt']);

        // Search engine blocking headers
        add_action('init', [$this, 'add_security_headers']);

        // REST API headers (ВАЖНО: 4 аргумента)
        add_filter('rest_pre_serve_request', [$this, 'add_rest_api_headers'], 10, 4);
    }

    /**
     * Initialize access control features
     */
    private function init_access_control() {
        // Homepage redirect
        add_action('template_redirect', [$this, 'redirect_homepage']);

        // Disable XML-RPC
        add_filter('xmlrpc_enabled', '__return_false');
    }

    /**
     * Initialize header cleanup
     */
    private function init_header_cleanup() {
        // Remove various WordPress headers
        add_action('init', function() {
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
            remove_action('wp_head', 'wp_generator');
        });
    }

    /**
     * Handle robots.txt requests
     */
    public function handle_robots_txt() {
        if (is_robots()) {
            header("Content-Type: text/plain; charset=utf-8");
            echo "User-agent: *\n";
            echo "Disallow: /\n";
            exit();
        }
    }

    /**
     * Add security headers to all responses
     */
    public function add_security_headers() {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }

    /**
     * Add headers to REST API responses
     *
     * @param bool             $served
     * @param WP_REST_Response $result
     * @param WP_REST_Request  $request
     * @param WP_REST_Server   $server
     *
     * @return bool
     */
    public function add_rest_api_headers($served, $result, $request, $server) {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        return $served;
    }

    /**
     * Redirect homepage to admin
     */
    public function redirect_homepage() {
        if (is_home() || is_front_page()) {
            wp_redirect(admin_url());
            exit;
        }
    }
}

// Initialize the security features
new HeadlessWPSecurity();

/**
 * Adwrap Settings Management
 * --------------------------
 * Functions for managing Adwrap site settings (contact information, social media links, etc.)
 */

class AdwrapSettingsManager {
    public function __construct() {
        // Add admin menu page
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Add REST API endpoint
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Add admin menu page for Adwrap settings
     */
    public function add_admin_menu() {
        add_menu_page(
            'Adwrap Settings',
            'Adwrap Settings',
            'manage_options',
            'adwrap-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-settings',
            30
        );
    }

    /**
     * Register settings fields
     */
    public function register_settings() {
        // Phone setting
        register_setting('contact_info_settings', 'contact_phone', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '847-637-0009'
        ]);

        // Email setting
        register_setting('contact_info_settings', 'contact_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => 'info@adwrapgraphics.com'
        ]);

        // Address setting
        register_setting('contact_info_settings', 'contact_address', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '1337 Industrial Dr, Itasca, IL 60143'
        ]);

        // Footer description
        register_setting('contact_info_settings', 'footer_description', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'Looking for car wrap services near you? Get a competitive quote from AdWrap Graphics today. We guarantee to answer all the inquiries within 24h.'
        ]);

        // Social media settings
        register_setting('contact_info_settings', 'contact_facebook', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://facebook.com'
        ]);

        register_setting('contact_info_settings', 'contact_instagram', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://instagram.com'
        ]);

        register_setting('contact_info_settings', 'contact_pinterest', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://pinterest.com'
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings
        if (isset($_POST['submit']) && check_admin_referer('contact_info_settings')) {
            update_option('contact_phone', sanitize_text_field($_POST['contact_phone']));
            update_option('contact_email', sanitize_email($_POST['contact_email']));
            update_option('contact_address', sanitize_text_field($_POST['contact_address']));
            update_option('footer_description', sanitize_textarea_field($_POST['footer_description']));
            update_option('contact_facebook', esc_url_raw($_POST['contact_facebook']));
            update_option('contact_instagram', esc_url_raw($_POST['contact_instagram']));
            update_option('contact_pinterest', esc_url_raw($_POST['contact_pinterest']));
            
            // Revalidation is handled automatically by the Next.js Revalidation plugin
            // via update_option_* hooks
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }

        // Get current values
        $phone = get_option('contact_phone', '847-637-0009');
        $email = get_option('contact_email', 'info@adwrapgraphics.com');
        $address = get_option('contact_address', '1337 Industrial Dr, Itasca, IL 60143');
        $footer_description = get_option('footer_description', 'Looking for car wrap services near you? Get a competitive quote from AdWrap Graphics today. We guarantee to answer all the inquiries within 24h.');
        $facebook = get_option('contact_facebook', 'https://facebook.com');
        $instagram = get_option('contact_instagram', 'https://instagram.com');
        $pinterest = get_option('contact_pinterest', 'https://pinterest.com');
        ?>
        <div class="wrap">
            <h1>Adwrap Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('contact_info_settings'); ?>
                
                <div id="contact-info-section">
                    <h2>Contact Information</h2>
                    <p class="description">Manage contact information displayed on the website.</p>
                    
                    <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="contact_phone">Phone Number</label>
                        </th>
                        <td>
                            <input 
                                type="text" 
                                id="contact_phone" 
                                name="contact_phone" 
                                value="<?php echo esc_attr($phone); ?>" 
                                class="regular-text"
                                placeholder="847-637-0009"
                            />
                            <p class="description">Contact phone number</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_email">Email Address</label>
                        </th>
                        <td>
                            <input 
                                type="email" 
                                id="contact_email" 
                                name="contact_email" 
                                value="<?php echo esc_attr($email); ?>" 
                                class="regular-text"
                                placeholder="info@adwrapgraphics.com"
                            />
                            <p class="description">Contact email address</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_address">Address</label>
                        </th>
                        <td>
                            <input 
                                type="text" 
                                id="contact_address" 
                                name="contact_address" 
                                value="<?php echo esc_attr($address); ?>" 
                                class="regular-text"
                                placeholder="1337 Industrial Dr, Itasca, IL 60143"
                            />
                            <p class="description">Physical address</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="footer_description">Footer Description</label>
                        </th>
                        <td>
                            <textarea 
                                id="footer_description" 
                                name="footer_description" 
                                class="large-text"
                                rows="3"
                                placeholder="Looking for car wrap services near you?"
                            ><?php echo esc_textarea($footer_description); ?></textarea>
                            <p class="description">Short description displayed in the footer</p>
                        </td>
                    </tr>
                    </table>
                </div>
                
                <div id="social-media-section">
                    <h2>Social Media</h2>
                    <p class="description">Links to your social media profiles.</p>
                    
                    <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="contact_facebook">Facebook URL</label>
                        </th>
                        <td>
                            <input 
                                type="url" 
                                id="contact_facebook" 
                                name="contact_facebook" 
                                value="<?php echo esc_attr($facebook); ?>" 
                                class="regular-text"
                                placeholder="https://facebook.com/yourpage"
                            />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_instagram">Instagram URL</label>
                        </th>
                        <td>
                            <input 
                                type="url" 
                                id="contact_instagram" 
                                name="contact_instagram" 
                                value="<?php echo esc_attr($instagram); ?>" 
                                class="regular-text"
                                placeholder="https://instagram.com/yourprofile"
                            />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_pinterest">Pinterest URL</label>
                        </th>
                        <td>
                            <input 
                                type="url" 
                                id="contact_pinterest" 
                                name="contact_pinterest" 
                                value="<?php echo esc_attr($pinterest); ?>" 
                                class="regular-text"
                                placeholder="https://pinterest.com/yourprofile"
                            />
                        </td>
                    </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register REST API routes for contact information
     */
    public function register_rest_routes() {
        register_rest_route('adwrap/v1', '/contacts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_contacts'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get contact information via REST API
     */
    public function get_contacts() {
        return [
            'phone' => get_option('contact_phone', '847-637-0009'),
            'email' => get_option('contact_email', 'info@adwrapgraphics.com'),
            'address' => get_option('contact_address', '1337 Industrial Dr, Itasca, IL 60143'),
            'facebook' => get_option('contact_facebook', 'https://facebook.com'),
            'instagram' => get_option('contact_instagram', 'https://instagram.com'),
            'pinterest' => get_option('contact_pinterest', 'https://pinterest.com'),
            'footer_description' => get_option('footer_description', 'Looking for car wrap services near you? Get a competitive quote from AdWrap Graphics today. We guarantee to answer all the inquiries within 24h.'),
        ];
    }
}

// Initialize the Adwrap settings manager
new AdwrapSettingsManager();

/**
 * Menu REST API
 * -------------
 * Exposes WordPress navigation menus via REST API
 */
class AdwrapMenuAPI {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('adwrap/v1', '/menus/(?P<location>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_menu'],
            'permission_callback' => '__return_true',
            'args' => [
                'location' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public function get_menu(WP_REST_Request $request): array {
        $location = $request->get_param('location');
        $locations = get_nav_menu_locations();

        if (!isset($locations[$location])) {
            return [];
        }

        $menu_id = $locations[$location];
        $menu_items = wp_get_nav_menu_items($menu_id);

        if (!$menu_items) {
            return [];
        }

        return $this->build_menu_tree($menu_items);
    }

    private function build_menu_tree(array $items): array {
        $menu = [];
        $children = [];

        foreach ($items as $item) {
            $menu_item = [
                'id' => $item->ID,
                'title' => $item->title,
                'url' => $this->make_relative_url($item->url),
                'target' => $item->target ?: '_self',
                'children' => [],
            ];

            if ($item->menu_item_parent == 0) {
                $menu[$item->ID] = $menu_item;
            } else {
                $children[$item->menu_item_parent][] = $menu_item;
            }
        }

        foreach ($children as $parent_id => $child_items) {
            if (isset($menu[$parent_id])) {
                $menu[$parent_id]['children'] = $child_items;
            }
        }

        return array_values($menu);
    }

    private function make_relative_url(string $url): string {
        $site_url = home_url();
        if (strpos($url, $site_url) === 0) {
            $path = str_replace($site_url, '', $url);
            return $path ?: '/';
        }
        return $url;
    }
}

new AdwrapMenuAPI();
