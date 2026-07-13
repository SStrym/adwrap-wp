<?php

/**
 * Plugin Name: Adwrap Calculator Config
 * Description: Admin-configurable settings for the public wrap price
 *              calculator (vinyl tiers, color catalogs, wrap levels, design
 *              options, add-on services, fleet discounts, and all page copy).
 *              Exposed read-only at REST `adwrap/v1/calculator` for the
 *              Next.js /wrap-calculator page. Vehicle catalog itself lives in
 *              adwrap-vehicles.php.
 */

final class AdwrapCalculatorConfig
{
    private const OPTION = 'adwrap_calculator_config';
    private const NONCE  = 'adwrap_calculator_save';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu'], 20); // after adwrap-vehicles registers the parent
        add_action('admin_init', [$this, 'handle_save']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    /* ------------------------------------------------------------------ */
    /* Defaults (mirror the Figma design content)                          */
    /* ------------------------------------------------------------------ */

    public static function defaults(): array
    {
        return [
            'currency'  => '$',
            'phone'     => '(847) 637-0009',
            'min_price' => 0,

            'copy' => [
                'eyebrow'            => 'WRAP CALCULATOR',
                'title'              => 'Get your wrap price in 3 easy steps',
                'subtitle'           => 'Choose your vehicle to get an instant estimate based on its exact wrappable area. Final pricing is confirmed with a free design mock-up.',
                'step1_title'        => 'Select vehicle',
                'step1_desc'         => 'Choose from {count} real work vehicles. Vans, trucks, SUVs, trailers, and more.',
                'step2_title'        => 'Pick options',
                'step2_desc'         => 'Choose vinyl tier, wrap coverage, design level, and any add-on services.',
                'step3_title'        => 'Get your price',
                'step3_desc'         => 'Instant estimate with full breakdown — formal quote in seconds.',
                'vehicle_title'      => 'Select vehicle',
                'search_placeholder' => 'Search make or model…',
                'model_year_label'   => 'Model year',
                'no_results'         => 'No vehicles found',
                'tiers_title'        => 'Vinyl tier',
                'levels_title'       => 'Wrap level',
                'designs_title'      => 'Design',
                'addons_title'       => 'Add-on services',
                'fleet_title'        => 'Fleet size',
                'fleet_note_none'    => 'Select fleet size for volume discount',
                'fleet_note'         => 'Volume discount: {pct}% off wrap cost',
                'color_placeholder'  => 'Not specified',
                'empty_state'        => 'Select a vehicle to see instant pricing',
                'estimate_title'     => 'Your Estimate',
                'estimate_note'      => '(Cost per vehicle)',
                'material_label'     => 'Wrap Material & Labor',
                'discount_label'     => 'Fleet Discount',
                'design_label'       => 'Design',
                'addons_label'       => 'Add-on Services',
                'total_label'        => 'Estimated price',
                'submit_label'       => 'SUBMIT THIS QUOTE',
                'call_label'         => 'CALL {phone}',
                'promo_text'         => 'Want a real quote in 2 hours backed by a 2-year warranty?',
                'promo_button'       => 'GET FLEET PRICING →',
                'disclaimer'         => 'Estimate valid for 30 days. Final pricing subject to vehicle condition and design finalization. Taxes and shop fees not included.',
            ],

            'tiers' => [
                [
                    'id'             => 'color-change',
                    'name'           => 'Color change',
                    'price_per_sqft' => 8.25,
                    'description'    => 'Cast color change film. 3M 2080 / Avery SW900',
                    'catalog_ids'    => ['3m-2080', 'avery-sw900'],
                ],
                [
                    'id'             => 'premium-cast',
                    'name'           => 'Premium Cast',
                    'price_per_sqft' => 9.75,
                    'description'    => 'Standard wrap. full coverage, max durability',
                    'catalog_ids'    => [],
                ],
                [
                    'id'             => 'reflective',
                    'name'           => 'Reflective',
                    'price_per_sqft' => 24.00,
                    'description'    => 'DOT-grade reflective. night visibility, compliance',
                    'catalog_ids'    => [],
                ],
            ],

            'catalogs' => [
                [
                    'id'     => '3m-2080',
                    'label'  => '3M 2080 Color',
                    'colors' => "[Gloss]\nG10 Gloss White\nG12 Gloss Black\nG13 Gloss Hot Rod Red\nG31 Gloss Storm Gray\nG227 Gloss Deep Blue\n[Matte]\nM10 Matte White\nM12 Matte Black\nM21 Matte Silver\nM206 Matte Pine Green Metallic\nM229 Matte Slate Blue Metallic",
                ],
                [
                    'id'     => 'avery-sw900',
                    'label'  => 'Avery SW900 Color',
                    'colors' => "[Gloss]\n900-101 Gloss Black\n900-102 Gloss White\n900-437 Gloss Cardinal Red\n900-607 Gloss Dark Blue\n900-853 Gloss Metallic Silver\n[Matte]\n900-180 Matte Black\n900-190 Matte White\n900-643 Matte Steel Blue Metallic\n900-864 Matte Charcoal Metallic\n900-196 Matte Gray",
                ],
            ],

            'levels' => [
                ['id' => 'spot',    'name' => 'Spot graphics', 'description' => 'Hood, doors, or accent panels', 'percent' => 25],
                ['id' => 'partial', 'name' => 'Partial wrap',  'description' => 'Rear side + 30% sides coverage', 'percent' => 50],
                ['id' => 'half',    'name' => 'Half wrap',     'description' => 'Rear half + cab or full sides',  'percent' => 75],
                ['id' => 'full',    'name' => 'Full wrap',     'description' => 'Every panel. 100% coverage',     'percent' => 100],
            ],

            'designs' => [
                ['id' => 'own',      'name' => 'Your design',    'description' => 'Supply your own print-ready files',      'price' => 0,   'badge' => 'INCLUDED'],
                ['id' => 'basic',    'name' => 'Basic',          'description' => 'Logo placement, 1–2 colors, simple layout', 'price' => 399, 'badge' => ''],
                ['id' => 'standard', 'name' => 'Standard',       'description' => 'Full layout, up to 4 colors, custom graphics', 'price' => 599, 'badge' => ''],
                ['id' => 'premium',  'name' => 'Premium custom', 'description' => 'Illustrated artwork, full original concept', 'price' => 999, 'badge' => ''],
            ],

            'addons' => [
                ['id' => 'window-tint',    'name' => 'Window tint',           'description' => 'Full vehicle. UV block + privacy',   'price' => 350, 'from' => 0],
                ['id' => 'ceramic',        'name' => 'Ceramic coating',       'description' => 'Gloss seal & protection over wrap',  'price' => 800, 'from' => 0],
                ['id' => 'wrap-removal',   'name' => 'Existing wrap removal', 'description' => 'Safe removal of current graphics',   'price' => 800, 'from' => 1],
            ],

            'fleet' => [
                ['label' => '1',     'discount_pct' => 0],
                ['label' => '2-4',   'discount_pct' => 3],
                ['label' => '5-9',   'discount_pct' => 7],
                ['label' => '10-24', 'discount_pct' => 10],
                ['label' => '25+',   'discount_pct' => 12],
            ],
        ];
    }

    /** Saved config merged over defaults (shallow for scalars, replace for lists). */
    public function config(): array
    {
        $defaults = self::defaults();
        $saved    = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $config = $defaults;
        foreach (['currency', 'phone', 'min_price'] as $k) {
            if (isset($saved[$k])) {
                $config[$k] = $saved[$k];
            }
        }
        if (!empty($saved['copy']) && is_array($saved['copy'])) {
            $config['copy'] = array_merge($defaults['copy'], $saved['copy']);
        }
        foreach (['tiers', 'catalogs', 'levels', 'designs', 'addons', 'fleet'] as $list) {
            if (isset($saved[$list]) && is_array($saved[$list])) {
                $config[$list] = $saved[$list];
            }
        }
        return $config;
    }

    /* ------------------------------------------------------------------ */
    /* Admin                                                               */
    /* ------------------------------------------------------------------ */

    public function admin_menu(): void
    {
        add_submenu_page(
            'adwrap-vehicles',
            'Calculator Settings',
            'Calculator',
            'manage_options',
            'adwrap-calculator',
            [$this, 'render_page']
        );
    }

    public function handle_save(): void
    {
        if (($_POST['adwrap_action'] ?? '') !== 'save_calculator' || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer(self::NONCE);

        $rows = fn(string $key) => array_values(array_filter(
            (array) ($_POST[$key] ?? []),
            'is_array'
        ));

        $config = [
            'currency'  => sanitize_text_field(wp_unslash($_POST['currency'] ?? '$')) ?: '$',
            'phone'     => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'min_price' => max(0, (float) ($_POST['min_price'] ?? 0)),
            'copy'      => [],
            'tiers'     => [],
            'catalogs'  => [],
            'levels'    => [],
            'designs'   => [],
            'addons'    => [],
            'fleet'     => [],
        ];

        $copy_keys = array_keys(self::defaults()['copy']);
        $posted    = (array) ($_POST['copy'] ?? []);
        foreach ($copy_keys as $key) {
            if (isset($posted[$key])) {
                $config['copy'][$key] = sanitize_textarea_field(wp_unslash($posted[$key]));
            }
        }

        foreach ($rows('catalogs') as $row) {
            $label = sanitize_text_field(wp_unslash($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $id = sanitize_title($row['id'] ?? '') ?: sanitize_title($label);
            $config['catalogs'][] = [
                'id'     => $id,
                'label'  => $label,
                'colors' => sanitize_textarea_field(wp_unslash($row['colors'] ?? '')),
            ];
        }
        $catalog_ids = array_column($config['catalogs'], 'id');

        foreach ($rows('tiers') as $row) {
            $name = sanitize_text_field(wp_unslash($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ids = array_values(array_intersect(
                array_map('sanitize_title', (array) ($row['catalog_ids'] ?? [])),
                $catalog_ids
            ));
            $config['tiers'][] = [
                'id'             => sanitize_title($row['id'] ?? '') ?: sanitize_title($name),
                'name'           => $name,
                'price_per_sqft' => max(0, (float) ($row['price_per_sqft'] ?? 0)),
                'description'    => sanitize_text_field(wp_unslash($row['description'] ?? '')),
                'catalog_ids'    => $ids,
            ];
        }

        foreach ($rows('levels') as $row) {
            $name = sanitize_text_field(wp_unslash($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $config['levels'][] = [
                'id'          => sanitize_title($row['id'] ?? '') ?: sanitize_title($name),
                'name'        => $name,
                'description' => sanitize_text_field(wp_unslash($row['description'] ?? '')),
                'percent'     => min(100, max(1, (int) ($row['percent'] ?? 100))),
            ];
        }

        foreach ($rows('designs') as $row) {
            $name = sanitize_text_field(wp_unslash($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $config['designs'][] = [
                'id'          => sanitize_title($row['id'] ?? '') ?: sanitize_title($name),
                'name'        => $name,
                'description' => sanitize_text_field(wp_unslash($row['description'] ?? '')),
                'price'       => max(0, (float) ($row['price'] ?? 0)),
                'badge'       => sanitize_text_field(wp_unslash($row['badge'] ?? '')),
            ];
        }

        foreach ($rows('addons') as $row) {
            $name = sanitize_text_field(wp_unslash($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $config['addons'][] = [
                'id'          => sanitize_title($row['id'] ?? '') ?: sanitize_title($name),
                'name'        => $name,
                'description' => sanitize_text_field(wp_unslash($row['description'] ?? '')),
                'price'       => max(0, (float) ($row['price'] ?? 0)),
                'from'        => empty($row['from']) ? 0 : 1,
            ];
        }

        foreach ($rows('fleet') as $row) {
            $label = sanitize_text_field(wp_unslash($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $config['fleet'][] = [
                'label'        => $label,
                'discount_pct' => min(90, max(0, (float) ($row['discount_pct'] ?? 0))),
            ];
        }

        update_option(self::OPTION, $config);
        $this->notify_next_revalidate();

        set_transient('adwrap_calculator_notice', ['type' => 'success', 'msg' => 'Calculator settings saved.'], 60);
        wp_safe_redirect(admin_url('admin.php?page=adwrap-calculator'));
        exit;
    }

    public function render_page(): void
    {
        $c      = $this->config();
        $notice = get_transient('adwrap_calculator_notice');
        if ($notice) {
            delete_transient('adwrap_calculator_notice');
        }

        $text = fn($v) => esc_attr((string) $v);
        ?>
        <div class="wrap">
            <h1>Calculator Settings</h1>
            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['msg']); ?></p></div>
            <?php endif; ?>
            <p>Everything the public wrap calculator shows — pricing tiers, coverage levels, design packages, add-ons, fleet discounts, and all texts — is configured here.
                Price formula: <code>sq&nbsp;ft × coverage% × tier&nbsp;$/sqft − fleet&nbsp;discount + design + add-ons</code>.</p>

            <form method="post" id="adwrap-calc-form">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="adwrap_action" value="save_calculator">

                <h2 class="title">General</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label>Currency symbol</label></th>
                        <td><input name="currency" value="<?php echo $text($c['currency']); ?>" style="width:60px"></td></tr>
                    <tr><th><label>Phone number</label></th>
                        <td><input name="phone" value="<?php echo $text($c['phone']); ?>" class="regular-text">
                            <p class="description">Shown on the CALL button. Format as it should display, e.g. <code>(847) 637-0009</code>.</p></td></tr>
                    <tr><th><label>Minimum estimate</label></th>
                        <td><input name="min_price" type="number" step="1" min="0" value="<?php echo $text($c['min_price']); ?>" style="width:120px">
                            <p class="description">The estimate never goes below this amount. 0 = no minimum.</p></td></tr>
                </table>

                <h2 class="title">Vinyl tiers</h2>
                <p class="description">Material tiers with a price per square foot. Attach color catalogs to show color pickers (e.g. for a color-change tier). Leave a name blank to drop the row.</p>
                <table class="widefat striped adwrap-repeater" id="rep-tiers">
                    <thead><tr><th style="width:16%">Name</th><th style="width:10%">$/sq&nbsp;ft</th><th>Description</th><th style="width:22%">Color catalogs</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['tiers'] as $i => $t): ?>
                        <tr>
                            <td><input name="tiers[<?php echo $i; ?>][name]" value="<?php echo $text($t['name']); ?>" style="width:100%">
                                <input type="hidden" name="tiers[<?php echo $i; ?>][id]" value="<?php echo $text($t['id']); ?>"></td>
                            <td><input name="tiers[<?php echo $i; ?>][price_per_sqft]" type="number" step="0.01" min="0" value="<?php echo $text($t['price_per_sqft']); ?>" style="width:100%"></td>
                            <td><input name="tiers[<?php echo $i; ?>][description]" value="<?php echo $text($t['description']); ?>" style="width:100%"></td>
                            <td>
                                <?php foreach ($c['catalogs'] as $cat): ?>
                                    <label style="display:block;white-space:nowrap;">
                                        <input type="checkbox" name="tiers[<?php echo $i; ?>][catalog_ids][]" value="<?php echo $text($cat['id']); ?>"
                                            <?php checked(in_array($cat['id'], (array) ($t['catalog_ids'] ?? []), true)); ?>>
                                        <?php echo esc_html($cat['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-tiers">+ Add tier</button></p>

                <h2 class="title">Color catalogs</h2>
                <p class="description">One color per line. Start a group with <code>[Group name]</code> (e.g. <code>[Gloss]</code>, <code>[Matte]</code>). Catalogs must be saved before they can be attached to a tier above.</p>
                <table class="widefat striped adwrap-repeater" id="rep-catalogs">
                    <thead><tr><th style="width:25%">Label (shown above the dropdown)</th><th>Colors</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['catalogs'] as $i => $cat): ?>
                        <tr>
                            <td><input name="catalogs[<?php echo $i; ?>][label]" value="<?php echo $text($cat['label']); ?>" style="width:100%">
                                <input type="hidden" name="catalogs[<?php echo $i; ?>][id]" value="<?php echo $text($cat['id']); ?>"></td>
                            <td><textarea name="catalogs[<?php echo $i; ?>][colors]" rows="5" style="width:100%"><?php echo esc_textarea($cat['colors']); ?></textarea></td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-catalogs">+ Add catalog</button></p>

                <h2 class="title">Wrap levels (coverage)</h2>
                <table class="widefat striped adwrap-repeater" id="rep-levels">
                    <thead><tr><th style="width:20%">Name</th><th style="width:12%">Coverage&nbsp;%</th><th>Description</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['levels'] as $i => $l): ?>
                        <tr>
                            <td><input name="levels[<?php echo $i; ?>][name]" value="<?php echo $text($l['name']); ?>" style="width:100%">
                                <input type="hidden" name="levels[<?php echo $i; ?>][id]" value="<?php echo $text($l['id']); ?>"></td>
                            <td><input name="levels[<?php echo $i; ?>][percent]" type="number" step="1" min="1" max="100" value="<?php echo $text($l['percent']); ?>" style="width:100%"></td>
                            <td><input name="levels[<?php echo $i; ?>][description]" value="<?php echo $text($l['description']); ?>" style="width:100%"></td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-levels">+ Add level</button></p>

                <h2 class="title">Design options</h2>
                <p class="description">Fixed price added to the estimate. Price 0 shows the badge text instead (e.g. <code>INCLUDED</code>).</p>
                <table class="widefat striped adwrap-repeater" id="rep-designs">
                    <thead><tr><th style="width:20%">Name</th><th style="width:12%">Price&nbsp;$</th><th>Description</th><th style="width:14%">Badge (optional)</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['designs'] as $i => $d): ?>
                        <tr>
                            <td><input name="designs[<?php echo $i; ?>][name]" value="<?php echo $text($d['name']); ?>" style="width:100%">
                                <input type="hidden" name="designs[<?php echo $i; ?>][id]" value="<?php echo $text($d['id']); ?>"></td>
                            <td><input name="designs[<?php echo $i; ?>][price]" type="number" step="1" min="0" value="<?php echo $text($d['price']); ?>" style="width:100%"></td>
                            <td><input name="designs[<?php echo $i; ?>][description]" value="<?php echo $text($d['description']); ?>" style="width:100%"></td>
                            <td><input name="designs[<?php echo $i; ?>][badge]" value="<?php echo $text($d['badge']); ?>" style="width:100%" placeholder="auto: +$price"></td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-designs">+ Add design option</button></p>

                <h2 class="title">Add-on services</h2>
                <p class="description">Selectable extras added on top. Check “from” to display the price as <code>from $X</code>.</p>
                <table class="widefat striped adwrap-repeater" id="rep-addons">
                    <thead><tr><th style="width:20%">Name</th><th style="width:12%">Price&nbsp;$</th><th style="width:8%">“from”</th><th>Description</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['addons'] as $i => $a): ?>
                        <tr>
                            <td><input name="addons[<?php echo $i; ?>][name]" value="<?php echo $text($a['name']); ?>" style="width:100%">
                                <input type="hidden" name="addons[<?php echo $i; ?>][id]" value="<?php echo $text($a['id']); ?>"></td>
                            <td><input name="addons[<?php echo $i; ?>][price]" type="number" step="1" min="0" value="<?php echo $text($a['price']); ?>" style="width:100%"></td>
                            <td style="text-align:center"><input type="checkbox" name="addons[<?php echo $i; ?>][from]" value="1" <?php checked(!empty($a['from'])); ?>></td>
                            <td><input name="addons[<?php echo $i; ?>][description]" value="<?php echo $text($a['description']); ?>" style="width:100%"></td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-addons">+ Add add-on</button></p>

                <h2 class="title">Fleet size tiers</h2>
                <p class="description">Discount is applied to the wrap material &amp; labor line only.</p>
                <table class="widefat striped adwrap-repeater" id="rep-fleet" style="max-width:480px">
                    <thead><tr><th>Label</th><th style="width:30%">Discount&nbsp;%</th><th style="width:60px"></th></tr></thead>
                    <tbody>
                        <?php foreach ($c['fleet'] as $i => $f): ?>
                        <tr>
                            <td><input name="fleet[<?php echo $i; ?>][label]" value="<?php echo $text($f['label']); ?>" style="width:100%"></td>
                            <td><input name="fleet[<?php echo $i; ?>][discount_pct]" type="number" step="0.5" min="0" max="90" value="<?php echo $text($f['discount_pct']); ?>" style="width:100%"></td>
                            <td><button type="button" class="button-link-delete adwrap-remove">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button adwrap-add" data-target="rep-fleet">+ Add fleet tier</button></p>

                <h2 class="title">Texts</h2>
                <p class="description">Every label and message on the calculator page. Placeholders: <code>{count}</code> = number of vehicles, <code>{pct}</code> = fleet discount %, <code>{phone}</code> = phone number.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $labels = [
                        'eyebrow'            => 'Eyebrow pill',
                        'title'              => 'Page title (H1)',
                        'subtitle'           => 'Page subtitle',
                        'step1_title'        => 'Step 1 title',
                        'step1_desc'         => 'Step 1 description',
                        'step2_title'        => 'Step 2 title',
                        'step2_desc'         => 'Step 2 description',
                        'step3_title'        => 'Step 3 title',
                        'step3_desc'         => 'Step 3 description',
                        'vehicle_title'      => 'Vehicle section title',
                        'search_placeholder' => 'Search placeholder',
                        'model_year_label'   => 'Model year label',
                        'no_results'         => 'No search results',
                        'tiers_title'        => 'Vinyl tier section title',
                        'levels_title'       => 'Wrap level section title',
                        'designs_title'      => 'Design section title',
                        'addons_title'       => 'Add-ons section title',
                        'fleet_title'        => 'Fleet size section title',
                        'fleet_note_none'    => 'Fleet note (no discount)',
                        'fleet_note'         => 'Fleet note (with discount)',
                        'color_placeholder'  => 'Color dropdown placeholder',
                        'empty_state'        => 'Empty state (no vehicle picked)',
                        'estimate_title'     => 'Estimate title (mobile bar)',
                        'estimate_note'      => 'Estimate note',
                        'material_label'     => 'Material & labor line label',
                        'discount_label'     => 'Fleet discount line label',
                        'design_label'       => 'Design line label',
                        'addons_label'       => 'Add-ons line label',
                        'total_label'        => 'Total line label',
                        'submit_label'       => 'Submit button',
                        'call_label'         => 'Call button',
                        'promo_text'         => 'Promo box text',
                        'promo_button'       => 'Promo box button',
                        'disclaimer'         => 'Disclaimer',
                    ];
                    foreach ($labels as $key => $label):
                        $val = (string) ($c['copy'][$key] ?? '');
                        $long = in_array($key, ['subtitle', 'promo_text', 'disclaimer'], true);
                        ?>
                        <tr>
                            <th scope="row"><label for="copy-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php if ($long): ?>
                                    <textarea id="copy-<?php echo esc_attr($key); ?>" name="copy[<?php echo esc_attr($key); ?>]" rows="2" class="large-text"><?php echo esc_textarea($val); ?></textarea>
                                <?php else: ?>
                                    <input id="copy-<?php echo esc_attr($key); ?>" name="copy[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" class="large-text">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <p class="submit"><button class="button button-primary button-hero">Save calculator settings</button></p>
            </form>
        </div>

        <script>
        (function () {
            // Repeater add/remove. New rows clone the last row, clear values,
            // and get a fresh index so PHP receives them as separate entries.
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('adwrap-remove')) {
                    var tbody = e.target.closest('tbody');
                    if (tbody.rows.length > 1) {
                        e.target.closest('tr').remove();
                    } else {
                        // keep one row; just clear it
                        e.target.closest('tr').querySelectorAll('input[type=text],input:not([type]),textarea,input[type=number]').forEach(function (el) { el.value = ''; });
                    }
                    return;
                }
                if (e.target.classList.contains('adwrap-add')) {
                    var table = document.getElementById(e.target.dataset.target);
                    var tbody = table.tBodies[0];
                    var row   = tbody.rows[tbody.rows.length - 1].cloneNode(true);
                    var next  = Date.now() % 1000000; // unique enough index
                    row.querySelectorAll('input,textarea').forEach(function (el) {
                        if (el.name) { el.name = el.name.replace(/\[\d+\]/, '[' + next + ']'); }
                        if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; }
                    });
                    tbody.appendChild(row);
                }
            });
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* REST                                                                */
    /* ------------------------------------------------------------------ */

    public function register_rest(): void
    {
        register_rest_route('adwrap/v1', '/calculator', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'rest_calculator'],
        ]);
    }

    public function rest_calculator(): WP_REST_Response
    {
        $c = $this->config();

        $catalogs = array_map(function ($cat) {
            return [
                'id'     => (string) $cat['id'],
                'label'  => (string) $cat['label'],
                'groups' => $this->parse_colors((string) $cat['colors']),
            ];
        }, $c['catalogs']);

        $out = [
            'currency'  => (string) $c['currency'],
            'phone'     => (string) $c['phone'],
            'min_price' => (float) $c['min_price'],
            'copy'      => array_map('strval', $c['copy']),
            'tiers'     => array_map(fn($t) => [
                'id'             => (string) $t['id'],
                'name'           => (string) $t['name'],
                'price_per_sqft' => (float) $t['price_per_sqft'],
                'description'    => (string) $t['description'],
                'catalog_ids'    => array_map('strval', (array) ($t['catalog_ids'] ?? [])),
            ], $c['tiers']),
            'catalogs'  => $catalogs,
            'levels'    => array_map(fn($l) => [
                'id'          => (string) $l['id'],
                'name'        => (string) $l['name'],
                'description' => (string) $l['description'],
                'percent'     => (int) $l['percent'],
            ], $c['levels']),
            'designs'   => array_map(fn($d) => [
                'id'          => (string) $d['id'],
                'name'        => (string) $d['name'],
                'description' => (string) $d['description'],
                'price'       => (float) $d['price'],
                'badge'       => (string) $d['badge'],
            ], $c['designs']),
            'addons'    => array_map(fn($a) => [
                'id'          => (string) $a['id'],
                'name'        => (string) $a['name'],
                'description' => (string) $a['description'],
                'price'       => (float) $a['price'],
                'from'        => !empty($a['from']),
            ], $c['addons']),
            'fleet'     => array_map(fn($f) => [
                'label'        => (string) $f['label'],
                'discount_pct' => (float) $f['discount_pct'],
            ], $c['fleet']),
        ];

        $response = new WP_REST_Response($out, 200);
        $response->header('Cache-Control', 'public, max-age=300');
        return $response;
    }

    /**
     * Parse the colors textarea into groups:
     *   "[Gloss]\nG12 Gloss Black\n[Matte]\nM12 Matte Black"
     * → [ ['name'=>'Gloss','colors'=>['G12 Gloss Black']], ... ]
     * Lines before any [Group] header land in a group with an empty name.
     */
    private function parse_colors(string $raw): array
    {
        $groups  = [];
        $current = null;
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                if ($current && $current['colors']) {
                    $groups[] = $current;
                }
                $current = ['name' => trim($m[1]), 'colors' => []];
                continue;
            }
            if ($current === null) {
                $current = ['name' => '', 'colors' => []];
            }
            $current['colors'][] = $line;
        }
        if ($current && $current['colors']) {
            $groups[] = $current;
        }
        return $groups;
    }

    /** Same fire-and-forget revalidation ping as adwrap-vehicles.php. */
    private function notify_next_revalidate(): void
    {
        $settings = get_option('next_revalidation_settings');
        if (empty($settings['next_url']) || empty($settings['webhook_secret'])) {
            return;
        }
        wp_remote_post(trailingslashit($settings['next_url']) . 'api/revalidate', [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [
                'Content-Type'     => 'application/json',
                'x-webhook-secret' => $settings['webhook_secret'],
            ],
            'body'     => wp_json_encode(['contentType' => 'vehicles']),
        ]);
    }
}

new AdwrapCalculatorConfig();
