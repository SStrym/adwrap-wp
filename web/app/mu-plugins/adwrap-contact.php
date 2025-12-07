<?php
/**
 * Plugin Name: Adwrap Contact Form
 * Description: Handles contact form submissions via Resend with spam protection and lead management
 * Version: 1.1.0
 * Author: Adwrap
 */

use Resend\Client;
use function Env\env;

class AdwrapContactAPI {
    private ?Client $resend = null;
    private const RATE_LIMIT_WINDOW = 3600; // 1 hour
    private const RATE_LIMIT_MAX = 5; // Max submissions per IP per hour
    
    public function __construct() {
        add_action('init', [$this, 'register_lead_post_type']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_field'], 20);
        add_filter('manage_lead_posts_columns', [$this, 'lead_columns']);
        add_action('manage_lead_posts_custom_column', [$this, 'lead_column_content'], 10, 2);
        add_filter('manage_edit-lead_sortable_columns', [$this, 'lead_sortable_columns']);
    }

    /**
     * Register Lead custom post type
     */
    public function register_lead_post_type(): void {
        $labels = [
            'name'                  => 'Leads',
            'singular_name'         => 'Lead',
            'menu_name'             => 'Leads',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Lead',
            'edit_item'             => 'Edit Lead',
            'view_item'             => 'View Lead',
            'all_items'             => 'All Leads',
            'search_items'          => 'Search Leads',
            'not_found'             => 'No leads found.',
            'not_found_in_trash'    => 'No leads found in Trash.',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-businessman',
            'supports'           => ['title'],
        ];

        register_post_type('lead', $args);

        // Register lead status taxonomy
        register_taxonomy('lead_status', 'lead', [
            'labels' => [
                'name'          => 'Status',
                'singular_name' => 'Status',
            ],
            'public'       => false,
            'show_ui'      => true,
            'hierarchical' => true,
            'show_admin_column' => true,
        ]);

        // Create default statuses
        $statuses = ['New', 'Contacted', 'Qualified', 'Converted', 'Spam'];
        foreach ($statuses as $status) {
            if (!term_exists($status, 'lead_status')) {
                wp_insert_term($status, 'lead_status');
            }
        }
    }

    /**
     * Customize lead columns in admin
     */
    public function lead_columns($columns): array {
        $new_columns = [
            'cb'          => $columns['cb'],
            'title'       => 'Name',
            'email'       => 'Email',
            'phone'       => 'Phone',
            'service'     => 'Service',
            'source'      => 'Source',
            'taxonomy-lead_status' => 'Status',
            'date'        => 'Date',
        ];
        return $new_columns;
    }

    /**
     * Lead column content
     */
    public function lead_column_content($column, $post_id): void {
        switch ($column) {
            case 'email':
                $email = get_post_meta($post_id, '_lead_email', true);
                echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
                break;
            case 'phone':
                $phone = get_post_meta($post_id, '_lead_phone', true);
                echo '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
                break;
            case 'service':
                echo esc_html(get_post_meta($post_id, '_lead_service', true));
                break;
            case 'source':
                echo esc_html(get_post_meta($post_id, '_lead_source', true));
                break;
        }
    }

    /**
     * Sortable columns
     */
    public function lead_sortable_columns($columns): array {
        $columns['email'] = 'email';
        $columns['service'] = 'service';
        return $columns;
    }

    /**
     * Get Resend client instance
     */
    private function getResend(): ?Client {
        if ($this->resend === null) {
            $api_key = env('RESEND_API_KEY');
            if (!empty($api_key)) {
                $this->resend = \Resend::client($api_key);
            }
        }
        return $this->resend;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    /**
     * Check rate limit
     */
    private function check_rate_limit(): bool {
        $ip = $this->get_client_ip();
        $transient_key = 'contact_rate_' . md5($ip);
        $submissions = get_transient($transient_key);
        
        if ($submissions === false) {
            return true;
        }
        
        return $submissions < self::RATE_LIMIT_MAX;
    }

    /**
     * Increment rate limit counter
     */
    private function increment_rate_limit(): void {
        $ip = $this->get_client_ip();
        $transient_key = 'contact_rate_' . md5($ip);
        $submissions = get_transient($transient_key);
        
        if ($submissions === false) {
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW);
        } else {
            set_transient($transient_key, $submissions + 1, self::RATE_LIMIT_WINDOW);
        }
    }

    /**
     * Check honeypot field (should be empty)
     */
    private function check_honeypot(WP_REST_Request $request): bool {
        $honeypot = $request->get_param('website');
        return empty($honeypot);
    }

    /**
     * Basic spam detection
     */
    private function is_spam(array $data): bool {
        $spam_patterns = [
            '/\b(viagra|cialis|casino|poker|lottery|crypto|bitcoin)\b/i',
            '/\b(click here|buy now|limited time|act now)\b/i',
            '/<[^>]*>/', // HTML tags
            '/\[url=/i', // BBCode
        ];

        $text_to_check = implode(' ', [
            $data['first_name'],
            $data['last_name'],
            $data['project_description'],
        ]);

        foreach ($spam_patterns as $pattern) {
            if (preg_match($pattern, $text_to_check)) {
                return true;
            }
        }

        // Check for excessive URLs
        $url_count = preg_match_all('/https?:\/\//i', $text_to_check);
        if ($url_count > 3) {
            return true;
        }

        return false;
    }

    /**
     * Save lead to database
     */
    private function save_lead(array $data, bool $is_spam = false): int {
        $post_data = [
            'post_title'  => $data['first_name'] . ' ' . $data['last_name'],
            'post_type'   => 'lead',
            'post_status' => 'publish',
            'post_content' => $data['project_description'] ?? '',
        ];

        $post_id = wp_insert_post($post_data);

        if ($post_id && !is_wp_error($post_id)) {
            // Save meta fields
            update_post_meta($post_id, '_lead_first_name', $data['first_name']);
            update_post_meta($post_id, '_lead_last_name', $data['last_name']);
            update_post_meta($post_id, '_lead_email', $data['email']);
            update_post_meta($post_id, '_lead_phone', $data['phone']);
            update_post_meta($post_id, '_lead_service', $data['service']);
            update_post_meta($post_id, '_lead_source', $data['source'] ?? 'Not specified');
            update_post_meta($post_id, '_lead_ip', $this->get_client_ip());
            update_post_meta($post_id, '_lead_user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');

            // Set status
            $status_term = $is_spam ? 'Spam' : 'New';
            wp_set_object_terms($post_id, $status_term, 'lead_status');
        }

        return $post_id;
    }

    /**
     * Register contact form settings
     */
    public function register_settings() {
        register_setting('adwrap_contact_settings', 'contact_recipient_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        ]);
        register_setting('adwrap_contact_settings', 'contact_from_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => 'noreply@adwrapgraphics.com'
        ]);
        register_setting('adwrap_contact_settings', 'contact_from_name', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'AdWrap Graphics Website'
        ]);
    }

    /**
     * Add contact settings to Adwrap Settings page
     */
    public function add_settings_field() {
        add_submenu_page(
            'adwrap-settings',
            'Contact Form Settings',
            'Contact Form',
            'manage_options',
            'adwrap-contact',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings
        if (isset($_POST['submit']) && check_admin_referer('adwrap_contact_settings')) {
            update_option('contact_recipient_email', sanitize_email($_POST['contact_recipient_email']));
            update_option('contact_from_email', sanitize_email($_POST['contact_from_email']));
            update_option('contact_from_name', sanitize_text_field($_POST['contact_from_name']));
            echo '<div class="notice notice-success"><p>Contact form settings saved successfully!</p></div>';
        }

        $recipient_email = get_option('contact_recipient_email', '');
        $from_email = get_option('contact_from_email', 'noreply@adwrapgraphics.com');
        $from_name = get_option('contact_from_name', 'AdWrap Graphics Website');
        $api_key_configured = !empty(env('RESEND_API_KEY'));
        
        // Get lead stats
        $total_leads = wp_count_posts('lead')->publish ?? 0;
        $new_leads = get_posts([
            'post_type' => 'lead',
            'posts_per_page' => -1,
            'tax_query' => [[
                'taxonomy' => 'lead_status',
                'field' => 'name',
                'terms' => 'New',
            ]],
            'fields' => 'ids',
        ]);
        $new_leads_count = count($new_leads);
        ?>
        <div class="wrap">
            <h1>Contact Form Settings</h1>
            
            <!-- Lead Stats -->
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="background: #f0f0f1; padding: 15px 25px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 32px; font-weight: bold; color: #00ABB3;"><?php echo esc_html($total_leads); ?></div>
                    <div style="color: #666;">Total Leads</div>
                </div>
                <div style="background: #f0f0f1; padding: 15px 25px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 32px; font-weight: bold; color: #d63638;"><?php echo esc_html($new_leads_count); ?></div>
                    <div style="color: #666;">New Leads</div>
                </div>
                <div style="padding: 15px 25px;">
                    <a href="<?php echo admin_url('edit.php?post_type=lead'); ?>" class="button button-primary" style="height: 100%; display: flex; align-items: center;">
                        View All Leads →
                    </a>
                </div>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('adwrap_contact_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Resend API Key</label>
                        </th>
                        <td>
                            <?php if ($api_key_configured): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                <span style="color: green;">Configured in environment</span>
                            <?php else: ?>
                                <span class="dashicons dashicons-warning" style="color: red;"></span>
                                <span style="color: red;">Not configured. Add RESEND_API_KEY to your .env file</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_recipient_email">Recipient Email</label>
                        </th>
                        <td>
                            <input 
                                type="email" 
                                id="contact_recipient_email" 
                                name="contact_recipient_email" 
                                value="<?php echo esc_attr($recipient_email); ?>" 
                                class="regular-text"
                                placeholder="info@adwrapgraphics.com"
                            />
                            <p class="description">
                                Email address where contact form submissions will be sent.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_from_email">From Email</label>
                        </th>
                        <td>
                            <input 
                                type="email" 
                                id="contact_from_email" 
                                name="contact_from_email" 
                                value="<?php echo esc_attr($from_email); ?>" 
                                class="regular-text"
                                placeholder="noreply@adwrapgraphics.com"
                            />
                            <p class="description">
                                Email address used as the sender (must be verified in Resend).
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="contact_from_name">From Name</label>
                        </th>
                        <td>
                            <input 
                                type="text" 
                                id="contact_from_name" 
                                name="contact_from_name" 
                                value="<?php echo esc_attr($from_name); ?>" 
                                class="regular-text"
                                placeholder="AdWrap Graphics Website"
                            />
                            <p class="description">
                                Name displayed as the sender in emails.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Security Features</h2>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><strong>Rate Limiting:</strong> Max <?php echo self::RATE_LIMIT_MAX; ?> submissions per IP per hour</li>
                <li><strong>Honeypot Field:</strong> Hidden field to catch bots</li>
                <li><strong>Spam Detection:</strong> Pattern matching for common spam keywords</li>
                <li><strong>IP Logging:</strong> Client IP stored with each lead</li>
            </ul>
            
            <hr>
            
            <h2>API Endpoint</h2>
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
                        <td><code>/wp-json/adwrap/v1/contact</code></td>
                        <td>POST</td>
                        <td>Submit contact form</td>
                    </tr>
                </tbody>
            </table>
            
            <h3>Request Body</h3>
            <pre style="background: #f0f0f0; padding: 10px; max-width: 600px;">
{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "service": "Commercial Wraps",
    "project_description": "Looking for fleet wrapping...",
    "source": "Google Search",
    "website": "" // Honeypot - must be empty
}</pre>
        </div>
        <?php
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('adwrap/v1', '/contact', [
            'methods' => 'POST',
            'callback' => [$this, 'submit_contact'],
            'permission_callback' => '__return_true',
            'args' => [
                'first_name' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'last_name' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ],
                'phone' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'service' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'project_description' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'source' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'website' => [ // Honeypot field
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Verify internal API secret
     */
    private function verify_internal_secret(WP_REST_Request $request): bool {
        $secret = env('INTERNAL_API_SECRET');
        if (empty($secret)) {
            // If secret not configured, allow requests (for backwards compatibility)
            return true;
        }
        
        $provided_secret = $request->get_header('X-Internal-Secret');
        return $provided_secret === $secret;
    }

    /**
     * Handle contact form submission
     */
    public function submit_contact(WP_REST_Request $request): WP_REST_Response {
        // Verify internal API secret (if configured)
        if (!$this->verify_internal_secret($request)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized request.',
            ], 403);
        }

        // Check honeypot
        if (!$this->check_honeypot($request)) {
            // Silently fail for bots
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you! Your message has been sent successfully.',
            ], 200);
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Too many submissions. Please try again later.',
            ], 429);
        }

        // Get form data
        $data = [
            'first_name' => $request->get_param('first_name'),
            'last_name' => $request->get_param('last_name'),
            'email' => $request->get_param('email'),
            'phone' => $request->get_param('phone'),
            'service' => $request->get_param('service'),
            'project_description' => $request->get_param('project_description') ?? '',
            'source' => $request->get_param('source') ?? 'Not specified',
        ];

        // Check for spam
        $is_spam = $this->is_spam($data);

        // Save lead (even if spam, for review)
        $lead_id = $this->save_lead($data, $is_spam);

        // If spam, silently succeed but don't send emails
        if ($is_spam) {
            $this->increment_rate_limit();
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you! Your message has been sent successfully.',
            ], 200);
        }

        // Increment rate limit
        $this->increment_rate_limit();

        // Send emails
        $resend = $this->getResend();
        $recipient_email = get_option('contact_recipient_email');
        $from_email = get_option('contact_from_email', 'noreply@adwrapgraphics.com');
        $from_name = get_option('contact_from_name', 'AdWrap Graphics Website');

        if ($resend === null || empty($recipient_email)) {
            // Lead is saved, but email not configured
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you! Your message has been received. We\'ll get back to you within 24 hours.',
            ], 200);
        }

        try {
            // Build and send notification email
            $html_content = $this->build_email_html($data, $lead_id);

            $resend->emails->send([
                'from' => "$from_name <$from_email>",
                'to' => [$recipient_email],
                'reply_to' => $data['email'],
                'subject' => "New Lead - {$data['first_name']} {$data['last_name']} - {$data['service']}",
                'html' => $html_content,
            ]);

            // Send confirmation email to customer
            $this->send_confirmation_email($resend, [
                'first_name' => $data['first_name'],
                'email' => $data['email'],
                'from_email' => $from_email,
                'from_name' => $from_name,
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you! Your message has been sent successfully. We\'ll get back to you within 24 hours.',
            ], 200);

        } catch (\Exception $e) {
            error_log('Contact form error: ' . $e->getMessage());
            
            // Lead is saved, email just failed
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Thank you! Your message has been received. We\'ll get back to you within 24 hours.',
            ], 200);
        }
    }

    /**
     * Build HTML email content
     */
    private function build_email_html(array $data, int $lead_id = 0): string {
        $admin_link = $lead_id ? admin_url("post.php?post={$lead_id}&action=edit") : '';
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #00ABB3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                .field { margin-bottom: 15px; }
                .label { font-weight: bold; color: #212331; }
                .value { margin-top: 5px; padding: 10px; background: white; border-radius: 4px; }
                .footer { margin-top: 20px; text-align: center; color: #666; font-size: 12px; }
                .admin-link { margin-top: 20px; text-align: center; }
                .admin-link a { background: #00ABB3; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1 style="margin: 0;">New Lead Received</h1>
                </div>
                <div class="content">
                    <div class="field">
                        <div class="label">Name</div>
                        <div class="value">' . esc_html($data['first_name']) . ' ' . esc_html($data['last_name']) . '</div>
                    </div>
                    <div class="field">
                        <div class="label">Email</div>
                        <div class="value"><a href="mailto:' . esc_attr($data['email']) . '">' . esc_html($data['email']) . '</a></div>
                    </div>
                    <div class="field">
                        <div class="label">Phone</div>
                        <div class="value"><a href="tel:' . esc_attr($data['phone']) . '">' . esc_html($data['phone']) . '</a></div>
                    </div>
                    <div class="field">
                        <div class="label">Service Interested In</div>
                        <div class="value">' . esc_html($data['service']) . '</div>
                    </div>
                    <div class="field">
                        <div class="label">Project Description</div>
                        <div class="value">' . nl2br(esc_html($data['project_description'] ?: 'Not provided')) . '</div>
                    </div>
                    <div class="field">
                        <div class="label">How They Heard About Us</div>
                        <div class="value">' . esc_html($data['source']) . '</div>
                    </div>
                    ' . ($admin_link ? '<div class="admin-link"><a href="' . esc_url($admin_link) . '">View Lead in Admin</a></div>' : '') . '
                </div>
                <div class="footer">
                    <p>This email was sent from the AdWrap Graphics website contact form.</p>
                </div>
            </div>
        </body>
        </html>';

        return $html;
    }

    /**
     * Send confirmation email to customer
     */
    private function send_confirmation_email(Client $resend, array $data): void {
        try {
            $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #00ABB3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                    .footer { margin-top: 20px; text-align: center; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1 style="margin: 0;">Thank You for Contacting Us!</h1>
                    </div>
                    <div class="content">
                        <p>Hi ' . esc_html($data['first_name']) . ',</p>
                        <p>Thank you for reaching out to AdWrap Graphics! We have received your message and will get back to you within 24 hours.</p>
                        <p>Best regards,<br>The AdWrap Graphics Team</p>
                    </div>
                    <div class="footer">
                        <p>AdWrap Graphics | 1337 Industrial Dr, Itasca, IL 60143</p>
                        <p><a href="https://adwrapgraphics.com">adwrapgraphics.com</a></p>
                    </div>
                </div>
            </body>
            </html>';

            $resend->emails->send([
                'from' => $data['from_name'] . ' <' . $data['from_email'] . '>',
                'to' => [$data['email']],
                'subject' => 'Thank you for contacting AdWrap Graphics!',
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            error_log('Confirmation email error: ' . $e->getMessage());
        }
    }
}

new AdwrapContactAPI();
