<?php
/**
 * AdWrap SEO - Universal SEO Data Provider
 * 
 * Automatically detects and extracts SEO data from any installed SEO plugin
 * (Yoast SEO, RankMath, All in One SEO, SEOPress, The SEO Framework, etc.)
 * Works as a bridge between WordPress SEO plugins and headless frontend.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register SEO REST API endpoints
 */
add_action('rest_api_init', function () {
    // Get SEO data for any content type by slug
    register_rest_route('adwrap/v1', '/seo/(?P<type>[a-zA-Z0-9_-]+)/(?P<identifier>[a-zA-Z0-9_-]+)', [
        'methods' => 'GET',
        'callback' => 'adwrap_get_seo_data',
        'permission_callback' => '__return_true',
        'args' => [
            'type' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return in_array($param, ['post', 'page', 'service', 'portfolio', 'success_story', 'category', 'tag']);
                }
            ],
            'identifier' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);

    // Get global SEO settings
    register_rest_route('adwrap/v1', '/seo/settings', [
        'methods' => 'GET',
        'callback' => 'adwrap_get_seo_settings',
        'permission_callback' => '__return_true',
    ]);

    // Get sitemap data for all content types
    register_rest_route('adwrap/v1', '/sitemap', [
        'methods' => 'GET',
        'callback' => 'adwrap_get_sitemap_data',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Detect which SEO plugin is active
 */
function adwrap_detect_seo_plugin() {
    // Yoast SEO
    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
        return 'yoast';
    }
    
    // RankMath
    if (class_exists('RankMath') || defined('RANK_MATH_VERSION')) {
        return 'rankmath';
    }
    
    // All in One SEO
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
        return 'aioseo';
    }
    
    // SEOPress
    if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
        return 'seopress';
    }
    
    // The SEO Framework
    if (defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('the_seo_framework')) {
        return 'seoframework';
    }
    
    // Slim SEO
    if (defined('SLIM_SEO_VER') || class_exists('SlimSEO\\Plugin')) {
        return 'slimseo';
    }
    
    return 'none';
}

/**
 * Get SEO data for a specific content item
 */
function adwrap_get_seo_data(WP_REST_Request $request) {
    $type = $request->get_param('type');
    $identifier = $request->get_param('identifier');

    // Handle taxonomy terms
    if (in_array($type, ['category', 'tag'])) {
        $taxonomy = $type === 'category' ? 'category' : 'post_tag';
        $term = get_term_by('slug', $identifier, $taxonomy);
        
        if (!$term) {
            return new WP_Error('not_found', 'Term not found', ['status' => 404]);
        }

        return adwrap_get_term_seo($term);
    }

    // Handle posts/pages/CPTs
    $post_type = $type === 'post' ? 'post' : ($type === 'page' ? 'page' : $type);
    
    $args = [
        'name' => $identifier,
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => 1,
    ];

    $posts = get_posts($args);
    
    if (empty($posts)) {
        return new WP_Error('not_found', 'Content not found', ['status' => 404]);
    }

    return adwrap_get_post_seo($posts[0]);
}

/**
 * Get SEO data for a post/page/CPT
 */
function adwrap_get_post_seo($post) {
    $plugin = adwrap_detect_seo_plugin();
    $site_name = get_bloginfo('name');
    
    // Default SEO data structure
    $seo = [
        'title' => '',
        'description' => '',
        'canonical' => get_permalink($post->ID),
        'robots' => [
            'index' => true,
            'follow' => true,
        ],
        'og_title' => '',
        'og_description' => '',
        'og_image' => '',
        'og_image_width' => 0,
        'og_image_height' => 0,
        'og_type' => 'article',
        'twitter_title' => '',
        'twitter_description' => '',
        'twitter_image' => '',
        'twitter_card' => 'summary_large_image',
        'keywords' => '',
        'modified_time' => get_the_modified_time('c', $post),
        'published_time' => get_the_time('c', $post),
        'plugin' => $plugin,
    ];

    switch ($plugin) {
        case 'yoast':
            $seo = adwrap_get_yoast_seo($post, $seo);
            break;
        case 'rankmath':
            $seo = adwrap_get_rankmath_seo($post, $seo);
            break;
        case 'aioseo':
            $seo = adwrap_get_aioseo_seo($post, $seo);
            break;
        case 'seopress':
            $seo = adwrap_get_seopress_seo($post, $seo);
            break;
        case 'seoframework':
            $seo = adwrap_get_seoframework_seo($post, $seo);
            break;
        case 'slimseo':
            $seo = adwrap_get_slimseo_seo($post, $seo);
            break;
    }

    // Apply defaults for any missing data
    return adwrap_apply_defaults($post, $seo, $site_name);
}

/**
 * Yoast SEO data extraction
 */
function adwrap_get_yoast_seo($post, $seo) {
    // Title
    $title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
    if ($title && function_exists('wpseo_replace_vars')) {
        $seo['title'] = wpseo_replace_vars($title, $post);
    }

    // Description
    $desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
    if ($desc && function_exists('wpseo_replace_vars')) {
        $seo['description'] = wpseo_replace_vars($desc, $post);
    }

    // Canonical
    $canonical = get_post_meta($post->ID, '_yoast_wpseo_canonical', true);
    if ($canonical) {
        $seo['canonical'] = $canonical;
    }

    // Robots
    $noindex = get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true);
    $nofollow = get_post_meta($post->ID, '_yoast_wpseo_meta-robots-nofollow', true);
    $seo['robots']['index'] = $noindex !== '1';
    $seo['robots']['follow'] = $nofollow !== '1';

    // OpenGraph
    $og_title = get_post_meta($post->ID, '_yoast_wpseo_opengraph-title', true);
    if ($og_title && function_exists('wpseo_replace_vars')) {
        $seo['og_title'] = wpseo_replace_vars($og_title, $post);
    }

    $og_desc = get_post_meta($post->ID, '_yoast_wpseo_opengraph-description', true);
    if ($og_desc && function_exists('wpseo_replace_vars')) {
        $seo['og_description'] = wpseo_replace_vars($og_desc, $post);
    }

    $og_image_id = get_post_meta($post->ID, '_yoast_wpseo_opengraph-image-id', true);
    if ($og_image_id) {
        $image = wp_get_attachment_image_src($og_image_id, 'full');
        if ($image) {
            $seo['og_image'] = $image[0];
            $seo['og_image_width'] = $image[1];
            $seo['og_image_height'] = $image[2];
        }
    }

    // Twitter
    $twitter_title = get_post_meta($post->ID, '_yoast_wpseo_twitter-title', true);
    if ($twitter_title && function_exists('wpseo_replace_vars')) {
        $seo['twitter_title'] = wpseo_replace_vars($twitter_title, $post);
    }

    $twitter_desc = get_post_meta($post->ID, '_yoast_wpseo_twitter-description', true);
    if ($twitter_desc && function_exists('wpseo_replace_vars')) {
        $seo['twitter_description'] = wpseo_replace_vars($twitter_desc, $post);
    }

    $twitter_image_id = get_post_meta($post->ID, '_yoast_wpseo_twitter-image-id', true);
    if ($twitter_image_id) {
        $image = wp_get_attachment_image_src($twitter_image_id, 'full');
        if ($image) {
            $seo['twitter_image'] = $image[0];
        }
    }

    // Focus keyword
    $focus_kw = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
    if ($focus_kw) {
        $seo['keywords'] = $focus_kw;
    }

    return $seo;
}

/**
 * RankMath SEO data extraction
 */
function adwrap_get_rankmath_seo($post, $seo) {
    // Title
    $title = get_post_meta($post->ID, 'rank_math_title', true);
    if ($title) {
        $seo['title'] = adwrap_rankmath_replace_vars($title, $post);
    }

    // Description
    $desc = get_post_meta($post->ID, 'rank_math_description', true);
    if ($desc) {
        $seo['description'] = adwrap_rankmath_replace_vars($desc, $post);
    }

    // Canonical
    $canonical = get_post_meta($post->ID, 'rank_math_canonical_url', true);
    if ($canonical) {
        $seo['canonical'] = $canonical;
    }

    // Robots
    $robots = get_post_meta($post->ID, 'rank_math_robots', true);
    if (is_array($robots)) {
        $seo['robots']['index'] = !in_array('noindex', $robots);
        $seo['robots']['follow'] = !in_array('nofollow', $robots);
    }

    // OpenGraph
    $og_title = get_post_meta($post->ID, 'rank_math_facebook_title', true);
    if ($og_title) {
        $seo['og_title'] = $og_title;
    }

    $og_desc = get_post_meta($post->ID, 'rank_math_facebook_description', true);
    if ($og_desc) {
        $seo['og_description'] = $og_desc;
    }

    $og_image = get_post_meta($post->ID, 'rank_math_facebook_image', true);
    if ($og_image) {
        $seo['og_image'] = $og_image;
        $og_image_id = get_post_meta($post->ID, 'rank_math_facebook_image_id', true);
        if ($og_image_id) {
            $image = wp_get_attachment_image_src($og_image_id, 'full');
            if ($image) {
                $seo['og_image_width'] = $image[1];
                $seo['og_image_height'] = $image[2];
            }
        }
    }

    // Twitter
    $twitter_title = get_post_meta($post->ID, 'rank_math_twitter_title', true);
    if ($twitter_title) {
        $seo['twitter_title'] = $twitter_title;
    }

    $twitter_desc = get_post_meta($post->ID, 'rank_math_twitter_description', true);
    if ($twitter_desc) {
        $seo['twitter_description'] = $twitter_desc;
    }

    // Focus keyword
    $focus_kw = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
    if ($focus_kw) {
        $seo['keywords'] = $focus_kw;
    }

    return $seo;
}

/**
 * RankMath variable replacement helper
 */
function adwrap_rankmath_replace_vars($string, $post) {
    $replacements = [
        '%title%' => $post->post_title,
        '%sitename%' => get_bloginfo('name'),
        '%sep%' => '-',
        '%excerpt%' => wp_strip_all_tags($post->post_excerpt),
        '%date%' => get_the_date('', $post),
        '%modified%' => get_the_modified_date('', $post),
        '%author%' => get_the_author_meta('display_name', $post->post_author),
        '%category%' => adwrap_get_primary_term($post->ID, 'category'),
        '%tag%' => adwrap_get_primary_term($post->ID, 'post_tag'),
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $string);
}

/**
 * All in One SEO data extraction
 */
function adwrap_get_aioseo_seo($post, $seo) {
    global $wpdb;
    
    // AIOSEO stores data in its own table
    $table = $wpdb->prefix . 'aioseo_posts';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        $aioseo_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id = %d",
            $post->ID
        ));
        
        if ($aioseo_data) {
            if (!empty($aioseo_data->title)) {
                $seo['title'] = $aioseo_data->title;
            }
            if (!empty($aioseo_data->description)) {
                $seo['description'] = $aioseo_data->description;
            }
            if (!empty($aioseo_data->canonical_url)) {
                $seo['canonical'] = $aioseo_data->canonical_url;
            }
            
            // Robots
            $robots = json_decode($aioseo_data->robots_default ?? '{}', true);
            if (isset($robots['noindex']) && $robots['noindex']) {
                $seo['robots']['index'] = false;
            }
            if (isset($robots['nofollow']) && $robots['nofollow']) {
                $seo['robots']['follow'] = false;
            }
            
            // OpenGraph
            $og = json_decode($aioseo_data->og_title ?? '{}', true);
            if (!empty($aioseo_data->og_title)) {
                $seo['og_title'] = $aioseo_data->og_title;
            }
            if (!empty($aioseo_data->og_description)) {
                $seo['og_description'] = $aioseo_data->og_description;
            }
            if (!empty($aioseo_data->og_image_custom_url)) {
                $seo['og_image'] = $aioseo_data->og_image_custom_url;
            }
            
            // Twitter
            if (!empty($aioseo_data->twitter_title)) {
                $seo['twitter_title'] = $aioseo_data->twitter_title;
            }
            if (!empty($aioseo_data->twitter_description)) {
                $seo['twitter_description'] = $aioseo_data->twitter_description;
            }
            
            // Keywords
            if (!empty($aioseo_data->keyphrases)) {
                $keyphrases = json_decode($aioseo_data->keyphrases, true);
                if (!empty($keyphrases['focus']['keyphrase'])) {
                    $seo['keywords'] = $keyphrases['focus']['keyphrase'];
                }
            }
        }
    }
    
    return $seo;
}

/**
 * SEOPress data extraction
 */
function adwrap_get_seopress_seo($post, $seo) {
    // Title
    $title = get_post_meta($post->ID, '_seopress_titles_title', true);
    if ($title) {
        $seo['title'] = $title;
    }

    // Description
    $desc = get_post_meta($post->ID, '_seopress_titles_desc', true);
    if ($desc) {
        $seo['description'] = $desc;
    }

    // Canonical
    $canonical = get_post_meta($post->ID, '_seopress_robots_canonical', true);
    if ($canonical) {
        $seo['canonical'] = $canonical;
    }

    // Robots
    $noindex = get_post_meta($post->ID, '_seopress_robots_index', true);
    $nofollow = get_post_meta($post->ID, '_seopress_robots_follow', true);
    $seo['robots']['index'] = $noindex !== 'yes';
    $seo['robots']['follow'] = $nofollow !== 'yes';

    // OpenGraph
    $og_title = get_post_meta($post->ID, '_seopress_social_fb_title', true);
    if ($og_title) {
        $seo['og_title'] = $og_title;
    }

    $og_desc = get_post_meta($post->ID, '_seopress_social_fb_desc', true);
    if ($og_desc) {
        $seo['og_description'] = $og_desc;
    }

    $og_image = get_post_meta($post->ID, '_seopress_social_fb_img', true);
    if ($og_image) {
        $seo['og_image'] = $og_image;
    }

    // Twitter
    $twitter_title = get_post_meta($post->ID, '_seopress_social_twitter_title', true);
    if ($twitter_title) {
        $seo['twitter_title'] = $twitter_title;
    }

    $twitter_desc = get_post_meta($post->ID, '_seopress_social_twitter_desc', true);
    if ($twitter_desc) {
        $seo['twitter_description'] = $twitter_desc;
    }

    // Keywords
    $keywords = get_post_meta($post->ID, '_seopress_analysis_target_kw', true);
    if ($keywords) {
        $seo['keywords'] = $keywords;
    }

    return $seo;
}

/**
 * The SEO Framework data extraction
 */
function adwrap_get_seoframework_seo($post, $seo) {
    // Title
    $title = get_post_meta($post->ID, '_genesis_title', true);
    if ($title) {
        $seo['title'] = $title;
    }

    // Description
    $desc = get_post_meta($post->ID, '_genesis_description', true);
    if ($desc) {
        $seo['description'] = $desc;
    }

    // Canonical
    $canonical = get_post_meta($post->ID, '_genesis_canonical_uri', true);
    if ($canonical) {
        $seo['canonical'] = $canonical;
    }

    // Robots
    $noindex = get_post_meta($post->ID, '_genesis_noindex', true);
    $nofollow = get_post_meta($post->ID, '_genesis_nofollow', true);
    $seo['robots']['index'] = !$noindex;
    $seo['robots']['follow'] = !$nofollow;

    // OpenGraph
    $og_title = get_post_meta($post->ID, '_open_graph_title', true);
    if ($og_title) {
        $seo['og_title'] = $og_title;
    }

    $og_desc = get_post_meta($post->ID, '_open_graph_description', true);
    if ($og_desc) {
        $seo['og_description'] = $og_desc;
    }

    // Twitter
    $twitter_title = get_post_meta($post->ID, '_twitter_title', true);
    if ($twitter_title) {
        $seo['twitter_title'] = $twitter_title;
    }

    $twitter_desc = get_post_meta($post->ID, '_twitter_description', true);
    if ($twitter_desc) {
        $seo['twitter_description'] = $twitter_desc;
    }

    return $seo;
}

/**
 * Slim SEO data extraction
 */
function adwrap_get_slimseo_seo($post, $seo) {
    // Slim SEO uses standard meta keys
    $title = get_post_meta($post->ID, 'slim_seo_title', true);
    if ($title) {
        $seo['title'] = $title;
    }

    $desc = get_post_meta($post->ID, 'slim_seo_description', true);
    if ($desc) {
        $seo['description'] = $desc;
    }

    $noindex = get_post_meta($post->ID, 'slim_seo_noindex', true);
    $seo['robots']['index'] = !$noindex;

    return $seo;
}

/**
 * Apply defaults for any missing SEO data
 */
function adwrap_apply_defaults($post, $seo, $site_name) {
    // Default title
    if (empty($seo['title'])) {
        $seo['title'] = $post->post_title . ' | ' . $site_name;
    }

    // Default description
    if (empty($seo['description'])) {
        if (!empty($post->post_excerpt)) {
            $seo['description'] = wp_strip_all_tags($post->post_excerpt);
        } else {
            $seo['description'] = wp_trim_words(wp_strip_all_tags($post->post_content), 30, '...');
        }
    }

    // Default OG from main SEO
    if (empty($seo['og_title'])) {
        $seo['og_title'] = $seo['title'];
    }
    if (empty($seo['og_description'])) {
        $seo['og_description'] = $seo['description'];
    }

    // Default OG image from featured image
    if (empty($seo['og_image'])) {
        $featured_image_id = get_post_thumbnail_id($post->ID);
        if ($featured_image_id) {
            $image = wp_get_attachment_image_src($featured_image_id, 'full');
            if ($image) {
                $seo['og_image'] = $image[0];
                $seo['og_image_width'] = $image[1];
                $seo['og_image_height'] = $image[2];
            }
        }
    }

    // Default Twitter from OG
    if (empty($seo['twitter_title'])) {
        $seo['twitter_title'] = $seo['og_title'];
    }
    if (empty($seo['twitter_description'])) {
        $seo['twitter_description'] = $seo['og_description'];
    }
    if (empty($seo['twitter_image'])) {
        $seo['twitter_image'] = $seo['og_image'];
    }

    return $seo;
}

/**
 * Get SEO data for taxonomy terms
 */
function adwrap_get_term_seo($term) {
    $plugin = adwrap_detect_seo_plugin();
    $site_name = get_bloginfo('name');
    
    $seo = [
        'title' => $term->name . ' | ' . $site_name,
        'description' => $term->description ?: 'Browse ' . $term->name . ' content',
        'canonical' => get_term_link($term),
        'robots' => ['index' => true, 'follow' => true],
        'og_title' => $term->name . ' | ' . $site_name,
        'og_description' => $term->description ?: 'Browse ' . $term->name . ' content',
        'og_image' => '',
        'og_image_width' => 0,
        'og_image_height' => 0,
        'og_type' => 'website',
        'twitter_title' => $term->name . ' | ' . $site_name,
        'twitter_description' => $term->description ?: 'Browse ' . $term->name . ' content',
        'twitter_image' => '',
        'twitter_card' => 'summary_large_image',
        'plugin' => $plugin,
    ];

    // Get plugin-specific term SEO
    switch ($plugin) {
        case 'yoast':
            $title = get_term_meta($term->term_id, 'wpseo_title', true);
            $desc = get_term_meta($term->term_id, 'wpseo_desc', true);
            if ($title) $seo['title'] = $seo['og_title'] = $seo['twitter_title'] = $title;
            if ($desc) $seo['description'] = $seo['og_description'] = $seo['twitter_description'] = $desc;
            break;
        case 'rankmath':
            $title = get_term_meta($term->term_id, 'rank_math_title', true);
            $desc = get_term_meta($term->term_id, 'rank_math_description', true);
            if ($title) $seo['title'] = $seo['og_title'] = $seo['twitter_title'] = $title;
            if ($desc) $seo['description'] = $seo['og_description'] = $seo['twitter_description'] = $desc;
            break;
    }

    return $seo;
}

/**
 * Get primary term for a taxonomy
 */
function adwrap_get_primary_term($post_id, $taxonomy) {
    $terms = get_the_terms($post_id, $taxonomy);
    if ($terms && !is_wp_error($terms)) {
        return $terms[0]->name;
    }
    return '';
}

/**
 * Get global SEO settings
 */
function adwrap_get_seo_settings(WP_REST_Request $request) {
    $plugin = adwrap_detect_seo_plugin();
    
    $settings = [
        'site_name' => get_bloginfo('name'),
        'site_description' => get_bloginfo('description'),
        'site_url' => home_url(),
        'language' => get_bloginfo('language'),
        'separator' => ' | ',
        'default_og_image' => '',
        'default_og_image_width' => 0,
        'default_og_image_height' => 0,
        'social' => [
            'facebook' => '',
            'twitter' => '',
            'instagram' => '',
            'linkedin' => '',
            'youtube' => '',
        ],
        'schema' => [
            'organization_name' => get_bloginfo('name'),
            'organization_logo' => '',
        ],
        'plugin' => $plugin,
    ];

    // Get plugin-specific global settings
    switch ($plugin) {
        case 'yoast':
            $social = get_option('wpseo_social', []);
            $titles = get_option('wpseo_titles', []);
            
            $settings['social']['facebook'] = $social['facebook_site'] ?? '';
            $settings['social']['twitter'] = $social['twitter_site'] ?? '';
            $settings['social']['instagram'] = $social['instagram_url'] ?? '';
            $settings['social']['linkedin'] = $social['linkedin_url'] ?? '';
            $settings['social']['youtube'] = $social['youtube_url'] ?? '';
            
            if (!empty($social['og_default_image'])) {
                $settings['default_og_image'] = $social['og_default_image'];
            }
            
            $settings['separator'] = isset($titles['separator']) ? ' ' . $titles['separator'] . ' ' : ' | ';
            break;
            
        case 'rankmath':
            $titles = get_option('rank-math-options-titles', []);
            $general = get_option('rank-math-options-general', []);
            
            $settings['social']['facebook'] = $general['social_url_facebook'] ?? '';
            $settings['social']['twitter'] = $general['twitter_author_names'] ?? '';
            $settings['social']['instagram'] = $general['social_url_instagram'] ?? '';
            $settings['social']['linkedin'] = $general['social_url_linkedin'] ?? '';
            $settings['social']['youtube'] = $general['social_url_youtube'] ?? '';
            
            if (!empty($general['open_graph_image'])) {
                $settings['default_og_image'] = $general['open_graph_image'];
            }
            break;
            
        case 'seopress':
            $social = get_option('seopress_social_option_name', []);
            
            $settings['social']['facebook'] = $social['seopress_social_accounts_facebook'] ?? '';
            $settings['social']['twitter'] = $social['seopress_social_accounts_twitter'] ?? '';
            $settings['social']['instagram'] = $social['seopress_social_accounts_instagram'] ?? '';
            $settings['social']['linkedin'] = $social['seopress_social_accounts_linkedin'] ?? '';
            $settings['social']['youtube'] = $social['seopress_social_accounts_youtube'] ?? '';
            break;
    }

    // Get site logo
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
        if ($logo) {
            $settings['schema']['organization_logo'] = $logo[0];
        }
    }

    return $settings;
}

/**
 * Get sitemap data for all content types
 */
function adwrap_get_sitemap_data(WP_REST_Request $request) {
    $plugin = adwrap_detect_seo_plugin();
    
    $sitemap = [
        'pages' => [],
        'posts' => [],
        'services' => [],
        'portfolio' => [],
        'success_stories' => [],
    ];

    // Helper to check noindex
    $is_noindex = function ($post_id) use ($plugin) {
        switch ($plugin) {
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1';
            case 'rankmath':
                $robots = get_post_meta($post_id, 'rank_math_robots', true);
                return is_array($robots) && in_array('noindex', $robots);
            case 'seopress':
                return get_post_meta($post_id, '_seopress_robots_index', true) === 'yes';
            case 'seoframework':
                return (bool) get_post_meta($post_id, '_genesis_noindex', true);
            default:
                return false;
        }
    };

    $content_types = [
        'pages' => ['post_type' => 'page', 'priority' => 0.8],
        'posts' => ['post_type' => 'post', 'priority' => 0.6],
        'services' => ['post_type' => 'service', 'priority' => 0.8],
        'portfolio' => ['post_type' => 'portfolio', 'priority' => 0.7],
        'success_stories' => ['post_type' => 'success_story', 'priority' => 0.7],
    ];

    foreach ($content_types as $key => $config) {
        $items = get_posts([
            'post_type' => $config['post_type'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        foreach ($items as $item) {
            if ($is_noindex($item->ID)) continue;

            $sitemap[$key][] = [
                'slug' => $item->post_name,
                'modified' => $item->post_modified_gmt,
                'priority' => $item->post_name === 'home' ? 1.0 : $config['priority'],
            ];
        }
    }

    return $sitemap;
}

/**
 * Add SEO field to REST API responses
 */
add_action('rest_api_init', function () {
    $post_types = ['post', 'page', 'service', 'portfolio', 'success_story'];
    
    foreach ($post_types as $post_type) {
        register_rest_field($post_type, 'seo', [
            'get_callback' => function ($post) {
                return adwrap_get_post_seo(get_post($post['id']));
            },
            'schema' => [
                'description' => 'SEO data',
                'type' => 'object',
            ],
        ]);
    }
});
