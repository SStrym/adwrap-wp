<?php
/**
 * Plugin Name: Adwrap Scripts & Analytics
 * Description: Manages custom scripts for analytics, tracking, and other head/body injections
 * Version: 1.0.0
 * Author: Adwrap
 */

class AdwrapScriptsManager {
    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page'], 20);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        // Google Tag Manager ID
        register_setting('adwrap_scripts_settings', 'gtm_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);

        // Head Scripts (high priority - before other scripts)
        register_setting('adwrap_scripts_settings', 'head_scripts', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_script'],
            'default' => ''
        ]);

        // Body Start Scripts (right after <body>)
        register_setting('adwrap_scripts_settings', 'body_start_scripts', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_script'],
            'default' => ''
        ]);

        // Body End Scripts (before </body>)
        register_setting('adwrap_scripts_settings', 'body_end_scripts', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_script'],
            'default' => ''
        ]);
    }

    /**
     * Sanitize script content - allow script tags but sanitize dangerous content
     */
    public function sanitize_script(string $value): string {
        // Allow script tags but prevent PHP execution
        return str_replace(['<?php', '<?', '?>'], '', $value);
    }

    /**
     * Add settings submenu page
     */
    public function add_settings_page(): void {
        add_submenu_page(
            'adwrap-settings',
            'Scripts & Analytics',
            'Scripts & Analytics',
            'manage_options',
            'adwrap-scripts',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings
        if (isset($_POST['submit']) && check_admin_referer('adwrap_scripts_settings')) {
            update_option('gtm_id', sanitize_text_field($_POST['gtm_id'] ?? ''));
            update_option('head_scripts', $this->sanitize_script($_POST['head_scripts'] ?? ''));
            update_option('body_start_scripts', $this->sanitize_script($_POST['body_start_scripts'] ?? ''));
            update_option('body_end_scripts', $this->sanitize_script($_POST['body_end_scripts'] ?? ''));
            echo '<div class="notice notice-success"><p>Scripts settings saved successfully!</p></div>';
        }

        $gtm_id = get_option('gtm_id', '');
        $head_scripts = get_option('head_scripts', '');
        $body_start_scripts = get_option('body_start_scripts', '');
        $body_end_scripts = get_option('body_end_scripts', '');
        ?>
        <div class="wrap">
            <h1>Scripts & Analytics</h1>
            <p class="description">Manage tracking scripts, analytics code, and custom scripts for the website.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('adwrap_scripts_settings'); ?>
                
                <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">Google Tag Manager</h2>
                    <p class="description">Enter your GTM container ID. This will be loaded using Next.js optimized GTM component.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gtm_id">GTM Container ID</label>
                            </th>
                            <td>
                                <input 
                                    type="text" 
                                    id="gtm_id" 
                                    name="gtm_id" 
                                    value="<?php echo esc_attr($gtm_id); ?>" 
                                    class="regular-text"
                                    placeholder="GTM-XXXXXXX"
                                />
                                <p class="description">Example: GTM-5SNGK9D7</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">Custom Scripts</h2>
                    <p class="description">Add custom scripts for analytics, tracking pixels, chat widgets, etc. Include full &lt;script&gt; tags.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="head_scripts">Head Scripts</label>
                            </th>
                            <td>
                                <textarea 
                                    id="head_scripts" 
                                    name="head_scripts" 
                                    class="large-text code"
                                    rows="8"
                                    placeholder="<!-- Scripts to load in <head> -->"
                                ><?php echo esc_textarea($head_scripts); ?></textarea>
                                <p class="description">Scripts added here will be injected into the &lt;head&gt; section. Good for analytics, meta pixels, etc.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="body_start_scripts">Body Start Scripts</label>
                            </th>
                            <td>
                                <textarea 
                                    id="body_start_scripts" 
                                    name="body_start_scripts" 
                                    class="large-text code"
                                    rows="8"
                                    placeholder="<!-- Scripts to load at the start of <body> -->"
                                ><?php echo esc_textarea($body_start_scripts); ?></textarea>
                                <p class="description">Scripts added here will be injected right after the opening &lt;body&gt; tag. Good for GTM noscript fallbacks.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="body_end_scripts">Body End Scripts</label>
                            </th>
                            <td>
                                <textarea 
                                    id="body_end_scripts" 
                                    name="body_end_scripts" 
                                    class="large-text code"
                                    rows="8"
                                    placeholder="<!-- Scripts to load before </body> -->"
                                ><?php echo esc_textarea($body_end_scripts); ?></textarea>
                                <p class="description">Scripts added here will be injected before the closing &lt;/body&gt; tag. Good for chat widgets, deferred scripts.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button('Save Scripts'); ?>
            </form>
            
            <hr>
            
            <h2>API Endpoint</h2>
            <p>Scripts are available via REST API for the Next.js frontend:</p>
            <table class="widefat" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/wp-json/adwrap/v1/scripts</code></td>
                        <td>GET</td>
                        <td>Get all script settings</td>
                    </tr>
                </tbody>
            </table>
            
            <h3>Response Example</h3>
            <pre style="background: #f0f0f0; padding: 10px; max-width: 600px; overflow-x: auto;">
{
    "gtm_id": "GTM-XXXXXXX",
    "head_scripts": "&lt;script&gt;...&lt;/script&gt;",
    "body_start_scripts": "&lt;noscript&gt;...&lt;/noscript&gt;",
    "body_end_scripts": "&lt;script&gt;...&lt;/script&gt;"
}</pre>
        </div>
        <?php
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('adwrap/v1', '/scripts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_scripts'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get scripts via REST API
     */
    public function get_scripts(): array {
        return [
            'gtm_id' => get_option('gtm_id', ''),
            'head_scripts' => get_option('head_scripts', ''),
            'body_start_scripts' => get_option('body_start_scripts', ''),
            'body_end_scripts' => get_option('body_end_scripts', ''),
        ];
    }
}

new AdwrapScriptsManager();

