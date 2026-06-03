<?php

/**
 * Plugin Name: AdWrap Redesign (fields + seeder)
 * Description: Registers ACF fields for the 2026 selective-redesign blocks (home blocks + per-service Service Areas) and provides a guarded one-time content/location seeder used to populate ACF values and generate per-service location pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ADWRAP_SEED_KEY')) {
    define('ADWRAP_SEED_KEY', 'adwrap-redesign-2026');
}

/* -------------------------------------------------------------------------
 * 1. Register redesign ACF fields as local PHP field groups.
 *    Exposed to REST via ACF's native get_fields() page integration.
 * ---------------------------------------------------------------------- */
add_action('acf/init', 'adwrap_redesign_register_fields');
function adwrap_redesign_register_fields(): void
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    // --- Service post type: Service Areas (local SEO) block ---
    acf_add_local_field_group([
        'key'    => 'group_adwrap_service_areas',
        'title'  => 'Service Areas (local SEO)',
        'fields' => [
            ['key' => 'field_adw_svc_sa', 'label' => 'Service Areas', 'name' => 'service_areas', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_adw_svc_sa_show', 'label' => 'Show block', 'name' => 'show', 'type' => 'true_false', 'default_value' => 1, 'ui' => 1],
                ['key' => 'field_adw_svc_sa_eyebrow', 'label' => 'Eyebrow', 'name' => 'eyebrow', 'type' => 'text', 'default_value' => 'Service areas'],
                ['key' => 'field_adw_svc_sa_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text'],
                ['key' => 'field_adw_svc_sa_desc', 'label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'field_adw_svc_sa_cta_label', 'label' => 'CTA Label', 'name' => 'cta_label', 'type' => 'text'],
                ['key' => 'field_adw_svc_sa_note', 'label' => 'Closing Note', 'name' => 'note', 'type' => 'text'],
            ]],
            ['key' => 'field_adw_svc_faq', 'label' => 'FAQ', 'name' => 'faq', 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Add question', 'sub_fields' => [
                ['key' => 'field_adw_svc_faq_q', 'label' => 'Question', 'name' => 'question', 'type' => 'text'],
                ['key' => 'field_adw_svc_faq_a', 'label' => 'Answer', 'name' => 'answer', 'type' => 'textarea', 'rows' => 3],
            ]],
        ],
        'location'     => [[['param' => 'post_type', 'operator' => '==', 'value' => 'service']]],
        'show_in_rest' => 1,
    ]);
}

/* -------------------------------------------------------------------------
 * 2. Guarded one-time seeder (locations + content).
 *    GET /wp-json/adwrap/v1/redesign-seed?key=...&what=all|locations|content
 * ---------------------------------------------------------------------- */
add_action('rest_api_init', function (): void {
    register_rest_route('adwrap/v1', '/redesign-seed', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'adwrap_redesign_seed',
    ]);
});

function adwrap_redesign_seed(WP_REST_Request $req)
{
    if ((string) $req->get_param('key') !== ADWRAP_SEED_KEY) {
        return new WP_Error('forbidden', 'Invalid key', ['status' => 403]);
    }
    if (!function_exists('update_field')) {
        return new WP_Error('no_acf', 'ACF not available', ['status' => 500]);
    }

    $what = $req->get_param('what') ?: 'all';
    $out  = [];
    if ($what === 'locations' || $what === 'all') {
        $out['locations'] = adwrap_seed_locations();
    }
    if ($what === 'content' || $what === 'all') {
        $out['content'] = adwrap_seed_content((bool) $req->get_param('force'));
    }
    return rest_ensure_response($out);
}

function adwrap_seed_locations(): array
{
    $services = [
        'van-wraps'               => 'Van Wraps',
        'box-truck-wraps'         => 'Box Truck Wraps',
        'fleet-vehicle-wraps'     => 'Fleet Vehicle Wraps',
        'pickup-truck-wraps'      => 'Pickup Truck Wraps',
        'branded-company-apparel' => 'Branded Company Apparel',
        'logo-brand-identity'     => 'Logo & Brand Identity',
    ];

    // Use the existing vehicle-wraps locations as the city template (slug, label, coords).
    $templates = get_posts([
        'post_type'      => 'location',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [['key' => 'service_slug', 'value' => 'vehicle-wraps', 'compare' => '=']],
    ]);

    $cities = [];
    foreach ($templates as $t) {
        $f = get_fields($t->ID) ?: [];
        if (empty($f['city'])) {
            continue;
        }
        $cities[] = [
            'city'       => $f['city'],
            'city_label' => $f['city_label'] ?? ucwords(str_replace('-', ' ', $f['city'])),
            'state'      => $f['state'] ?? 'illinois',
            'state_lbl'  => $f['state_label'] ?? 'Illinois',
            'state_abbr' => $f['state_abbreviation'] ?? 'IL',
            'lat'        => $f['latitude'] ?? null,
            'lng'        => $f['longitude'] ?? null,
        ];
    }

    $created = 0;
    $skipped = 0;
    foreach ($services as $slug => $label) {
        foreach ($cities as $c) {
            $exist = get_posts([
                'post_type'      => 'location',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => 'service_slug', 'value' => $slug,        'compare' => '='],
                    ['key' => 'state',        'value' => $c['state'],  'compare' => '='],
                    ['key' => 'city',         'value' => $c['city'],   'compare' => '='],
                ],
            ]);
            if (!empty($exist)) {
                $skipped++;
                continue;
            }

            $pid = wp_insert_post([
                'post_type'   => 'location',
                'post_status' => 'publish',
                'post_title'  => "{$label} in {$c['city_label']}, {$c['state_abbr']}",
                'post_name'   => sanitize_title("{$slug}-{$c['city']}-{$c['state']}"),
            ], true);
            if (is_wp_error($pid)) {
                continue;
            }

            update_field('service_slug', $slug, $pid);
            update_field('service_label', $label, $pid);
            update_field('state', $c['state'], $pid);
            update_field('state_label', $c['state_lbl'], $pid);
            update_field('state_abbreviation', $c['state_abbr'], $pid);
            update_field('city', $c['city'], $pid);
            update_field('city_label', $c['city_label'], $pid);
            if ($c['lat'] && $c['lng']) {
                update_field('latitude', $c['lat'], $pid);
                update_field('longitude', $c['lng'], $pid);
            }
            $created++;
        }
    }

    return ['created' => $created, 'skipped' => $skipped, 'cities' => count($cities), 'services' => array_keys($services)];
}

function adwrap_service_faq(string $slug): array
{
    $wrap = [
        ['question' => 'How long does a vehicle wrap last?', 'answer' => 'With premium cast vinyl and proper care, a professionally installed wrap lasts 5–7 years.'],
        ['question' => 'Will a wrap damage my paint?', 'answer' => 'No — on factory OEM paint in good condition a wrap protects it and removes cleanly when you are ready for a change.'],
        ['question' => 'How long does installation take?', 'answer' => 'Most commercial wraps are designed, printed, and installed in 3–5 business days.'],
        ['question' => 'How do I care for my wrap?', 'answer' => 'Hand wash or use a touchless car wash; avoid abrasive brushes, pressure-washing seams, and harsh solvents.'],
    ];
    $apparel = [
        ['question' => 'What printing methods do you offer?', 'answer' => 'Screen printing, embroidery, and DTG — we recommend the best fit for your garment, artwork, and quantity.'],
        ['question' => 'Is there a minimum order?', 'answer' => 'Minimums depend on the method: embroidery and DTG allow small runs, while screen printing is most economical at higher quantities.'],
        ['question' => 'What is the typical turnaround?', 'answer' => 'About 7–10 business days after artwork approval, depending on quantity and decoration method.'],
        ['question' => 'What garments can you brand?', 'answer' => 'T-shirts, polos, hoodies, jackets, hats, and hi-vis workwear from all major blank brands.'],
    ];
    $logo = [
        ['question' => 'What is included in a branding project?', 'answer' => 'Discovery, multiple logo concepts, revisions, and a full set of final files for print and web.'],
        ['question' => 'How many concepts do I get?', 'answer' => 'You will review several initial directions, then we refine the strongest one to final.'],
        ['question' => 'How many revisions are included?', 'answer' => 'We revise until you are happy within the agreed project scope.'],
        ['question' => 'What file formats do I receive?', 'answer' => 'Vector files (AI, EPS, SVG, PDF) plus PNG/JPG exports for everyday use.'],
    ];
    if ($slug === 'branded-company-apparel') {
        return $apparel;
    }
    if ($slug === 'logo-brand-identity') {
        return $logo;
    }
    return $wrap;
}

function adwrap_seed_content(bool $force = false): array
{
    $log = [];

    // ---- Home page ----
    $home = get_page_by_path('home');
    if (!$home) {
        $q = get_posts(['post_type' => 'page', 'name' => 'home', 'posts_per_page' => 1]);
        $home = $q ? $q[0] : null;
    }
    if ($home) {
        $hid = $home->ID;
        $set = function (string $name, $val) use ($hid, &$log, $force): void {
            $cur = get_field($name, $hid);
            if ($force || $cur === null || $cur === '' || $cur === false || (is_array($cur) && count($cur) === 0)) {
                update_field($name, $val, $hid);
                $log[] = "home.$name";
            }
        };

        $set('hero_eyebrow', 'Vehicle Wraps · Itasca, IL');
        $set('hero_title', 'Commercial Vehicle Wraps');
        $set('hero_title_secondary', 'Across');
        $set('hero_highlight_text', 'Chicagoland');
        $set('hero_subtitle', 'Designed, printed, and installed in-house in Itasca, IL — premium cast vinyl that lasts 5–7 years, ready in 3–5 days.');
        $set('hero_cta_label', 'Get a Free Quote');
        $set('hero_cta_link', '/contact-us');
        $set('hero_phone', '847-637-0009');
        $set('hero_rating', '4.9');
        $set('hero_rating_note', '200+ wraps installed');

        $set('process_eyebrow', 'Our process');
        $set('process_title', 'How We Work — From Concept to Installed');
        $set('process_steps', [
            ['step_number' => '01', 'step_title' => 'Consult', 'step_desc' => 'We learn your brand, vehicles, and goals.'],
            ['step_number' => '02', 'step_title' => 'Design', 'step_desc' => 'Custom mockups on your exact vehicle.'],
            ['step_number' => '03', 'step_title' => 'Proof', 'step_desc' => 'You approve every detail before we print.'],
            ['step_number' => '04', 'step_title' => 'Print & Wrap', 'step_desc' => 'Premium cast vinyl, color-matched.'],
            ['step_number' => '05', 'step_title' => 'Install', 'step_desc' => 'Certified install in our Itasca bay.'],
        ]);

        $set('stats_items', [
            ['value' => '200+', 'label' => 'Wraps installed'],
            ['value' => '4.9★', 'label' => 'Average rating'],
            ['value' => '5-yr', 'label' => 'Material warranty'],
            ['value' => '3–5 days', 'label' => 'Typical turnaround'],
        ]);

        $set('trust_items', [
            ['label' => 'Free design mockup'],
            ['label' => '5-year material warranty'],
            ['label' => 'In-house certified install'],
            ['label' => 'On-time install guarantee'],
        ]);

        $set('services_eyebrow', 'What we do');
        $set('services_title', 'Everything That Carries Your Brand');
        $set('services_subtitle', 'From full vehicle wraps to branded apparel and a website that converts — all under one roof in Itasca.');

        $set('service_areas_eyebrow', 'Service areas');
        $set('service_areas_title', 'Serving All of Chicagoland');
        $set('service_areas_description', 'Commercial vehicle wraps designed, printed, and installed across the Chicago metro and suburbs.');
        $set('service_areas_service', 'vehicle-wraps');

        $set('cta_title', 'Ready to Turn Your Vehicle Into a Billboard?');
        $set('cta_subtitle', 'Get a free design mockup on your exact vehicle — no obligation.');
        $set('cta_button_label', 'Get a Free Quote');
        $set('cta_button_link', '/contact-us');
        $set('cta_phone', '847-637-0009');

        $log[] = '__home_id=' . $hid;
        $log[] = '__home_type=' . get_post_type($hid);
        $log[] = '__readback_hero_eyebrow=' . var_export(get_field('hero_eyebrow', $hid), true);
        $log[] = '__readback_process_rows=' . count((array) get_field('process_steps', $hid));
        $log[] = '__raw_meta_hero_eyebrow=' . var_export(get_post_meta($hid, 'hero_eyebrow', true), true);
    } else {
        $log[] = '__home_PAGE_NOT_FOUND';
    }

    // ---- Services: Service Areas group ----
    $svcs = get_posts(['post_type' => 'service', 'posts_per_page' => -1, 'post_status' => 'publish']);
    foreach ($svcs as $s) {
        $cur = get_field('service_areas', $s->ID);
        if ($force || empty($cur) || empty($cur['title'])) {
            update_field('service_areas', [
                'show'        => true,
                'eyebrow'     => 'Service areas',
                'title'       => $s->post_title . ' Across Chicagoland',
                'description' => 'Serving Chicago and the surrounding suburbs with professional ' . strtolower($s->post_title) . '.',
            ], $s->ID);
            $log[] = 'service.' . $s->post_name;
        }

        $faq_cur = get_field('faq', $s->ID);
        if ($force || empty($faq_cur)) {
            update_field('faq', adwrap_service_faq($s->post_name), $s->ID);
            $log[] = 'service_faq.' . $s->post_name;
        }
    }

    return $log;
}
