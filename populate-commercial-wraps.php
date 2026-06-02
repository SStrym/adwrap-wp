<?php
/**
 * Seed the "Vehicle Wraps" (Commercial Wraps) service content_sections with the
 * Figma copy: Why Our Wraps (features_grid) + What We Wrap (sub_services_grid)
 * + Built to Last (stats_row). Idempotent — re-running overwrites the field.
 *
 * Run on any env:  lando wp eval-file populate-commercial-wraps.php
 *                  (prod:  wp eval-file populate-commercial-wraps.php)
 *
 * Icons/images are left empty on purpose — add them in the admin. Text only.
 */

$slug = 'vehicle-wraps';
$posts = get_posts([
    'post_type'   => 'service',
    'name'        => $slug,
    'post_status' => 'any',
    'numberposts' => 1,
]);
if (!$posts) {
    WP_CLI::error("Service '{$slug}' not found.");
}
$post_id = $posts[0]->ID;

$blocks = [
    // ── Why Our Wraps ───────────────────────────────────────────────
    [
        'acf_fc_layout'           => 'features_grid',
        'section_title_highlight' => '',
        'section_title'           => 'Wraps Built to Work as Hard as You Do',
        'subtitle'                => "A commercial wrap is one of the highest-ROI ads a local business can buy — when it's designed, printed, and installed right. Here's what you get with every AdWrap project.",
        'background_color'        => 'white',
        'items' => [
            ['icon' => '', 'title' => 'Color-Matched Design', 'description' => 'A free custom mockup on your exact vehicle before you commit.'],
            ['icon' => '', 'title' => 'Premium Cast Vinyl',   'description' => '3M-grade cast film + laminate for a 5–7 year outdoor finish.'],
            ['icon' => '', 'title' => 'In-House Install',      'description' => 'Certified installers in our climate-controlled Itasca bay.'],
            ['icon' => '', 'title' => 'Paint-Safe',            'description' => 'Removes cleanly and protects the factory paint underneath.'],
        ],
    ],
    // ── What We Wrap ────────────────────────────────────────────────
    [
        'acf_fc_layout' => 'sub_services_grid',
        'eyebrow'       => 'What We Wrap',
        'title'         => 'Every Vehicle, Every Surface',
        'subtitle'      => 'From a single van to a full fleet — choose the coverage that fits your goals and budget.',
        'items' => [
            ['icon' => '', 'title' => 'Full Vehicle Wraps', 'description' => 'Bumper-to-bumper coverage that turns the whole vehicle into a billboard.', 'link' => ''],
            ['icon' => '', 'title' => 'Van Wraps',          'description' => 'Cargo & Sprinter vans — the highest-impact canvas on the road.',          'link' => ''],
            ['icon' => '', 'title' => 'Box Truck Wraps',    'description' => 'Big flat panels mean maximum visibility on highways and lots.',           'link' => ''],
            ['icon' => '', 'title' => 'Fleet Wraps',        'description' => 'Consistent branding across every vehicle, installed on schedule.',         'link' => ''],
            ['icon' => '', 'title' => 'Color Change',       'description' => 'Premium color-change films — no paint, fully reversible.',                 'link' => ''],
            ['icon' => '', 'title' => 'Partial Wraps',      'description' => 'Budget-friendly coverage that still gets you noticed.',                    'link' => ''],
        ],
    ],
    // ── Built to Last ───────────────────────────────────────────────
    [
        'acf_fc_layout'    => 'stats_row',
        'eyebrow'          => 'Built to Last',
        'title'            => 'Materials & a Warranty That Hold Up',
        'subtitle'         => 'We use only premium cast films and laminates — rated for years of Chicagoland sun, salt, and snow.',
        'background_color' => 'gray',
        'items' => [
            ['value' => '5–7 yrs',   'label' => 'Outdoor lifespan',       'description' => 'Cast film + laminate rated for Midwest sun, salt & snow.'],
            ['value' => '3M · Avery','label' => 'Pro-grade vinyl',        'description' => 'Cast films that conform to deep curves, rivets & seams.'],
            ['value' => '2-yr',      'label' => 'Workmanship warranty',   'description' => 'We stand behind every edge and seam we install.'],
            ['value' => '100%',      'label' => 'Paint-safe removal',     'description' => 'Peels off clean with no residue when it is time.'],
        ],
    ],
];

update_field('content_sections', $blocks, $post_id);

$count = is_array(get_field('content_sections', $post_id)) ? count(get_field('content_sections', $post_id)) : 0;
WP_CLI::success("Populated content_sections on '{$slug}' (post #{$post_id}) — {$count} sections.");
