<?php

/**
 * Plugin Name: Adwrap ACF "Page Slug" Location Rule
 * Description: Adds an ACF location rule "Page Slug" so field groups can be bound
 *              to a page by its slug instead of a numeric ID. Page IDs differ
 *              between environments (local vs prod), which broke field-group
 *              binding after deploy; slugs are stable, so this makes ACF group
 *              location portable across environments.
 */

add_action('acf/init', function () {
    if (!function_exists('acf_register_location_type') || !class_exists('ACF_Location')) {
        return;
    }

    class Adwrap_ACF_Page_Slug_Location extends ACF_Location
    {
        public function initialize()
        {
            $this->name        = 'page_slug';
            $this->label       = __('Page Slug', 'adwrap');
            $this->category    = 'page';
            $this->object_type = 'post';
        }

        public function match($rule, $screen, $field_group)
        {
            $post_id = isset($screen['post_id']) ? (int) $screen['post_id'] : 0;
            if (!$post_id) {
                return false;
            }
            $slug  = get_post_field('post_name', $post_id);
            $match = ($slug === $rule['value']);
            return ($rule['operator'] === '!=') ? !$match : $match;
        }

        public function get_values($rule)
        {
            $choices = [];
            $pages = get_posts([
                'post_type'      => 'page',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            foreach ($pages as $p) {
                if ($p->post_name) {
                    $choices[$p->post_name] = $p->post_title . ' (' . $p->post_name . ')';
                }
            }
            return $choices;
        }
    }

    acf_register_location_type('Adwrap_ACF_Page_Slug_Location');
});
