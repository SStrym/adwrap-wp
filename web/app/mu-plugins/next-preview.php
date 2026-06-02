<?php
/**
 * Plugin Name: Next.js Preview Mode
 * Description: Routes the WordPress admin "Preview" button to the Next.js Draft Mode endpoint so editors can preview unpublished posts and pages on the headless front-end.
 * Version: 1.0.0
 * Author: Adwrap
 *
 * Reuses the `next_url` value stored by the Next.js Revalidation plugin
 * (option `next_revalidation_settings`) and stores its own preview secret
 * under `next_preview_settings` to avoid mixing concerns with the webhook
 * secret.
 *
 * On the Next.js side, the matching endpoint is /api/preview which expects:
 *   secret     — shared preview secret (must equal WORDPRESS_PREVIEW_SECRET)
 *   post_id    — numeric ID of the post/page being previewed
 *   post_type  — "post" or "page" (other public types are passed through)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Next_Preview {
    private $option_name = 'next_preview_settings';
    private $revalidate_option_name = 'next_revalidation_settings';

    public function __construct() {
        add_filter('preview_post_link', array($this, 'rewrite_preview_link'), 10, 2);
        add_filter('preview_page_link', array($this, 'rewrite_preview_link'), 10, 2);

        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_submenu'), 20);
    }

    /**
     * Replace WP's default preview URL with the Next.js Draft Mode endpoint.
     */
    public function rewrite_preview_link($preview_link, $post) {
        $next_url = $this->get_next_url();
        $secret   = $this->get_preview_secret();

        if (empty($next_url) || empty($secret) || empty($post)) {
            return $preview_link;
        }

        $args = array(
            'secret'    => $secret,
            'post_id'   => $post->ID,
            'post_type' => $post->post_type,
        );

        return add_query_arg($args, trailingslashit($next_url) . 'api/preview');
    }

    private function get_next_url() {
        $revalidate = get_option($this->revalidate_option_name);
        $preview    = get_option($this->option_name);

        // Prefer a preview-specific override; fall back to the revalidate plugin's URL.
        if (!empty($preview['next_url'])) {
            return rtrim($preview['next_url'], '/');
        }
        if (!empty($revalidate['next_url'])) {
            return rtrim($revalidate['next_url'], '/');
        }
        return '';
    }

    private function get_preview_secret() {
        $preview = get_option($this->option_name);
        return !empty($preview['preview_secret']) ? $preview['preview_secret'] : '';
    }

    public function register_settings() {
        register_setting(
            'next_preview_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );

        add_settings_section(
            'next_preview_section',
            'Next.js Preview Settings',
            function () {
                echo '<p>Configure the shared secret used to authorize preview requests sent to the Next.js site. The Next.js URL is reused from the Next.js Revalidation plugin unless overridden below.</p>';
            },
            'next-preview-settings'
        );

        add_settings_field(
            'preview_secret',
            'Preview Secret',
            array($this, 'preview_secret_field'),
            'next-preview-settings',
            'next_preview_section'
        );

        add_settings_field(
            'next_url',
            'Next.js URL Override',
            array($this, 'next_url_field'),
            'next-preview-settings',
            'next_preview_section'
        );
    }

    public function sanitize_settings($input) {
        $out = array();
        if (isset($input['preview_secret'])) {
            $out['preview_secret'] = sanitize_text_field($input['preview_secret']);
        }
        if (isset($input['next_url'])) {
            $out['next_url'] = esc_url_raw(rtrim(trim($input['next_url']), '/'));
        }
        return $out;
    }

    public function preview_secret_field() {
        $value = esc_attr($this->get_preview_secret());
        echo '<input type="text" name="' . esc_attr($this->option_name) . '[preview_secret]" value="' . $value . '" class="regular-text" />';
        echo '<p class="description">Must equal <code>WORDPRESS_PREVIEW_SECRET</code> in the Next.js environment.</p>';
    }

    public function next_url_field() {
        $options = get_option($this->option_name);
        $value   = isset($options['next_url']) ? esc_attr($options['next_url']) : '';
        echo '<input type="url" name="' . esc_attr($this->option_name) . '[next_url]" value="' . $value . '" class="regular-text" placeholder="https://your-next-site.com" />';
        echo '<p class="description">Optional. If empty, the URL from the Next.js Revalidation plugin is used.</p>';
    }

    public function add_settings_submenu() {
        // Hang under the existing "Next.js" menu created by next-revalidate.php
        // if it exists; otherwise create a standalone Settings entry.
        global $admin_page_hooks;
        $parent = isset($admin_page_hooks['next-revalidation-settings'])
            ? 'next-revalidation-settings'
            : 'options-general.php';

        add_submenu_page(
            $parent,
            'Preview',
            'Preview',
            'manage_options',
            'next-preview-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Next.js Preview Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('next_preview_group');
                do_settings_sections('next-preview-settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }
}

new Next_Preview();
