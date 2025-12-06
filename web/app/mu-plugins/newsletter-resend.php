<?php
/**
 * Plugin Name: Newsletter Subscription via Resend
 * Description: Handles newsletter subscriptions using Resend PHP SDK
 * Version: 1.0.0
 * Author: Adwrap
 */

use Resend\Client;
use function Env\env;

class AdwrapNewsletterAPI {
    private ?Client $resend = null;
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init', [$this, 'register_settings']);
        // Priority 20 to load after theme's admin menu (default priority 10)
        add_action('admin_menu', [$this, 'add_settings_field'], 20);
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
     * Register newsletter settings
     */
    public function register_settings() {
        register_setting('adwrap_newsletter_settings', 'resend_audience_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
    }

    /**
     * Add newsletter settings to Adwrap Settings page
     */
    public function add_settings_field() {
        add_submenu_page(
            'adwrap-settings',
            'Newsletter Settings',
            'Newsletter',
            'manage_options',
            'adwrap-newsletter',
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
        if (isset($_POST['submit']) && check_admin_referer('adwrap_newsletter_settings')) {
            update_option('resend_audience_id', sanitize_text_field($_POST['resend_audience_id']));
            echo '<div class="notice notice-success"><p>Newsletter settings saved successfully!</p></div>';
        }

        $audience_id = get_option('resend_audience_id', '');
        $api_key_configured = !empty(env('RESEND_API_KEY'));
        ?>
        <div class="wrap">
            <h1>Newsletter Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('adwrap_newsletter_settings'); ?>
                
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
                            <label for="resend_audience_id">Resend Audience ID</label>
                        </th>
                        <td>
                            <input 
                                type="text" 
                                id="resend_audience_id" 
                                name="resend_audience_id" 
                                value="<?php echo esc_attr($audience_id); ?>" 
                                class="regular-text"
                                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            />
                            <p class="description">
                                Your Resend Audience ID. Find it in your 
                                <a href="https://resend.com/audiences" target="_blank">Resend Dashboard → Audiences</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>API Endpoints</h2>
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
                        <td><code>/wp-json/adwrap/v1/newsletter/subscribe</code></td>
                        <td>POST</td>
                        <td>Subscribe email to newsletter</td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/adwrap/v1/newsletter/unsubscribe</code></td>
                        <td>POST</td>
                        <td>Unsubscribe email from newsletter</td>
                    </tr>
                </tbody>
            </table>
            
            <h3>Request Body</h3>
            <pre style="background: #f0f0f0; padding: 10px; max-width: 600px;">
{
    "email": "user@example.com"
}</pre>
        </div>
        <?php
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('adwrap/v1', '/newsletter/subscribe', [
            'methods' => 'POST',
            'callback' => [$this, 'subscribe'],
            'permission_callback' => '__return_true',
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ],
                'source' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'website',
                ],
                'first_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'last_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('adwrap/v1', '/newsletter/unsubscribe', [
            'methods' => 'POST',
            'callback' => [$this, 'unsubscribe'],
            'permission_callback' => '__return_true',
            'args' => [
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ]);
    }

    /**
     * Subscribe email to newsletter
     */
    public function subscribe(WP_REST_Request $request): WP_REST_Response {
        $email = $request->get_param('email');
        $resend = $this->getResend();
        $audience_id = get_option('resend_audience_id');

        if ($resend === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Newsletter service is not configured.',
            ], 500);
        }

        if (empty($audience_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Newsletter audience is not configured.',
            ], 500);
        }

        try {
            $contactData = [
                'audience_id' => $audience_id,
                'email' => $email,
                'unsubscribed' => false,
            ];

            // Add optional fields for segmentation
            $firstName = $request->get_param('first_name');
            $lastName = $request->get_param('last_name');
            $source = $request->get_param('source') ?? 'website';

            if (!empty($firstName)) {
                $contactData['first_name'] = $firstName;
            }
            if (!empty($lastName)) {
                $contactData['last_name'] = $lastName;
            }
            // Store source in last_name if first_name is empty (for segmentation)
            // Or you can create custom logic
            if (empty($lastName) && !empty($source)) {
                $contactData['last_name'] = '[' . $source . ']';
            }

            $resend->contacts->create($contactData);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Successfully subscribed to newsletter!',
            ], 200);

        } catch (\Resend\Exceptions\ErrorException $e) {
            $errorMessage = $e->getMessage();
            
            // Handle "already subscribed" case
            if (str_contains($errorMessage, 'already exists') || str_contains($errorMessage, 'duplicate')) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'You are already subscribed to our newsletter.',
                ], 200);
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to subscribe. Please try again.',
            ], 500);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'An error occurred. Please try again later.',
            ], 500);
        }
    }

    /**
     * Unsubscribe email from newsletter
     */
    public function unsubscribe(WP_REST_Request $request): WP_REST_Response {
        $email = $request->get_param('email');
        $resend = $this->getResend();
        $audience_id = get_option('resend_audience_id');

        if ($resend === null || empty($audience_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Newsletter service is not configured.',
            ], 500);
        }

        try {
            // Update contact to unsubscribed status
            $resend->contacts->update($email, [
                'audience_id' => $audience_id,
                'unsubscribed' => true,
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Successfully unsubscribed from newsletter.',
            ], 200);

        } catch (\Resend\Exceptions\ErrorException $e) {
            $errorMessage = $e->getMessage();
            
            if (str_contains($errorMessage, 'not found')) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Email not found in newsletter list.',
                ], 404);
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to unsubscribe. Please try again.',
            ], 500);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'An error occurred. Please try again later.',
            ], 500);
        }
    }
}

new AdwrapNewsletterAPI();
