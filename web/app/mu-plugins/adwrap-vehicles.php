<?php

/**
 * Plugin Name: Adwrap Vehicles & Wrap Calculator
 * Description: Vehicle square-footage catalog (PVO list) with CSV import, an
 *              editable admin screen, configurable pricing, and a REST API that
 *              powers the Next.js wrap price calculator.
 */

final class AdwrapVehicles
{
    /** Bump this when the table schema changes to trigger a re-create via dbDelta. */
    private const SCHEMA_VERSION = '1';
    private const SCHEMA_OPTION  = 'adwrap_vehicles_schema_version';
    private const PRICING_OPTION = 'adwrap_wrap_pricing';

    private const NONCE_IMPORT = 'adwrap_vehicles_import';
    private const NONCE_EDIT   = 'adwrap_vehicles_edit';
    private const NONCE_PRICE  = 'adwrap_vehicles_pricing';

    /** Numeric measurement columns, in display order. */
    private const MEASURE_COLS = [
        'side_width', 'side_height', 'side_sqft',
        'back_width', 'back_height', 'back_sqft',
        'hood_width', 'hood_length', 'hood_sqft',
        'roof_width', 'roof_length', 'roof_sqft',
        'total_sqft',
    ];

    public function __construct()
    {
        add_action('init', [$this, 'maybe_create_table']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_post']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /* ------------------------------------------------------------------ */
    /* Schema                                                              */
    /* ------------------------------------------------------------------ */

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'adwrap_vehicles';
    }

    public function maybe_create_table(): void
    {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        $table   = $this->table();
        $charset = $wpdb->get_charset_collate();

        $measures = '';
        foreach (self::MEASURE_COLS as $col) {
            $measures .= "{$col} DECIMAL(7,2) NULL,\n";
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            make VARCHAR(100) NOT NULL DEFAULT '',
            model VARCHAR(191) NOT NULL DEFAULT '',
            year_raw VARCHAR(20) NOT NULL DEFAULT '',
            year_from SMALLINT UNSIGNED NULL,
            year_to SMALLINT UNSIGNED NULL,
            {$measures}
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY make (make),
            KEY model (model(50)),
            KEY years (year_from, year_to)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    /* ------------------------------------------------------------------ */
    /* Pricing settings                                                    */
    /* ------------------------------------------------------------------ */

    public function pricing(): array
    {
        $defaults = [
            'currency'       => '$',
            'price_per_sqft' => 12.0,
            'base_fee'       => 0.0,
            'min_price'      => 0.0,
            'materials'      => [
                ['label' => 'Gloss',                'multiplier' => 1.0],
                ['label' => 'Matte',                'multiplier' => 1.1],
                ['label' => 'Color-shift / Chrome', 'multiplier' => 1.6],
            ],
        ];
        $saved = get_option(self::PRICING_OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $p = array_merge($defaults, $saved);
        if (empty($p['materials']) || !is_array($p['materials'])) {
            $p['materials'] = $defaults['materials'];
        }
        return $p;
    }

    /* ------------------------------------------------------------------ */
    /* Admin menu                                                          */
    /* ------------------------------------------------------------------ */

    public function admin_menu(): void
    {
        add_menu_page(
            'Wrap Calculator',
            'Wrap Calculator',
            'manage_options',
            'adwrap-vehicles',
            [$this, 'render_vehicles_page'],
            'dashicons-car',
            56
        );
        add_submenu_page('adwrap-vehicles', 'Vehicles', 'Vehicles', 'manage_options', 'adwrap-vehicles', [$this, 'render_vehicles_page']);
        add_submenu_page('adwrap-vehicles', 'Import CSV', 'Import CSV', 'manage_options', 'adwrap-vehicles-import', [$this, 'render_import_page']);
        add_submenu_page('adwrap-vehicles', 'Pricing', 'Pricing', 'manage_options', 'adwrap-vehicles-pricing', [$this, 'render_pricing_page']);
    }

    /* ------------------------------------------------------------------ */
    /* Admin: POST handling (router)                                       */
    /* ------------------------------------------------------------------ */

    public function handle_admin_post(): void
    {
        if (empty($_POST['adwrap_action']) || !current_user_can('manage_options')) {
            return;
        }

        switch ($_POST['adwrap_action']) {
            case 'save_vehicle':
                $this->handle_save_vehicle();
                break;
            case 'delete_vehicle':
                $this->handle_delete_vehicle();
                break;
            case 'import_csv':
                $this->handle_import_csv();
                break;
            case 'save_pricing':
                $this->handle_save_pricing();
                break;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Admin: Vehicles list + edit form                                    */
    /* ------------------------------------------------------------------ */

    public function render_vehicles_page(): void
    {
        global $wpdb;
        $table = $this->table();

        // Edit / Add screen
        $edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $is_add   = isset($_GET['add']);
        if ($edit_id || $is_add) {
            $row = $edit_id
                ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id), ARRAY_A)
                : null;
            $this->render_edit_form($row);
            return;
        }

        // List screen
        $search   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $make     = isset($_GET['make']) ? sanitize_text_field(wp_unslash($_GET['make'])) : '';
        $per_page = 50;
        $paged    = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $offset   = ($paged - 1) * $per_page;

        $where  = '1=1';
        $params = [];
        if ($search !== '') {
            $where   .= ' AND (make LIKE %s OR model LIKE %s OR year_raw LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($make !== '') {
            $where   .= ' AND make = %s';
            $params[] = $make;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total     = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $list_sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY make ASC, model ASC, year_from ASC LIMIT %d OFFSET %d";
        $list_params = array_merge($params, [$per_page, $offset]);
        $rows        = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

        $makes = $wpdb->get_col("SELECT DISTINCT make FROM {$table} WHERE make <> '' ORDER BY make ASC");
        $notice = $this->take_notice();
        $base   = admin_url('admin.php?page=adwrap-vehicles');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Vehicles</h1>
            <a href="<?php echo esc_url(add_query_arg('add', '1', $base)); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['msg']); ?></p></div>
            <?php endif; ?>

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="adwrap-vehicles">
                <select name="make">
                    <option value="">All makes</option>
                    <?php foreach ($makes as $m): ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($make, $m); ?>><?php echo esc_html($m); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search make / model / year">
                <button class="button">Filter</button>
                <span style="margin-left:8px;color:#666;"><?php echo number_format($total); ?> vehicles</span>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Make</th><th>Model</th><th>Year</th>
                        <th>Side&nbsp;ft²</th><th>Back&nbsp;ft²</th><th>Hood&nbsp;ft²</th><th>Roof&nbsp;ft²</th>
                        <th>Total&nbsp;ft²</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9">No vehicles found.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?php echo esc_html($r['make']); ?></strong></td>
                            <td><?php echo esc_html($r['model']); ?></td>
                            <td><?php echo esc_html($r['year_raw']); ?></td>
                            <td><?php echo esc_html($r['side_sqft']); ?></td>
                            <td><?php echo esc_html($r['back_sqft']); ?></td>
                            <td><?php echo esc_html($r['hood_sqft']); ?></td>
                            <td><?php echo esc_html($r['roof_sqft']); ?></td>
                            <td><strong><?php echo esc_html($r['total_sqft']); ?></strong></td>
                            <td><a href="<?php echo esc_url(add_query_arg('edit', $r['id'], $base)); ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = (int) ceil($total / $per_page);
            if ($total_pages > 1) {
                $current_args = array_filter(['page' => 'adwrap-vehicles', 's' => $search, 'make' => $make]);
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'      => add_query_arg(array_merge($current_args, ['paged' => '%#%']), admin_url('admin.php')),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                ]);
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private function render_edit_form(?array $row): void
    {
        $base    = admin_url('admin.php?page=adwrap-vehicles');
        $is_edit = !empty($row);
        $val     = fn(string $k) => esc_attr($row[$k] ?? '');
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit vehicle' : 'Add vehicle'; ?></h1>
            <p><a href="<?php echo esc_url($base); ?>">&larr; Back to list</a></p>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_EDIT); ?>
                <input type="hidden" name="adwrap_action" value="save_vehicle">
                <input type="hidden" name="id" value="<?php echo (int) ($row['id'] ?? 0); ?>">

                <table class="form-table">
                    <tr><th><label>Make</label></th><td><input name="make" class="regular-text" value="<?php echo $val('make'); ?>" required></td></tr>
                    <tr><th><label>Model</label></th><td><input name="model" class="regular-text" value="<?php echo $val('model'); ?>"></td></tr>
                    <tr><th><label>Year (range)</label></th>
                        <td>
                            <input name="year_from" type="number" min="1900" max="2100" style="width:90px" value="<?php echo $val('year_from'); ?>" placeholder="from">
                            &ndash;
                            <input name="year_to" type="number" min="1900" max="2100" style="width:90px" value="<?php echo $val('year_to'); ?>" placeholder="to">
                            <p class="description">Leave "to" empty for a single year. The display label is generated from these.</p>
                        </td>
                    </tr>
                </table>

                <h2>Measurements (inches, ft²)</h2>
                <table class="form-table">
                    <?php
                    $groups = [
                        'Side' => ['side_width' => 'Width', 'side_height' => 'Height', 'side_sqft' => 'Sq ft'],
                        'Back' => ['back_width' => 'Width', 'back_height' => 'Height', 'back_sqft' => 'Sq ft'],
                        'Hood' => ['hood_width' => 'Width', 'hood_length' => 'Length', 'hood_sqft' => 'Sq ft'],
                        'Roof' => ['roof_width' => 'Width', 'roof_length' => 'Length', 'roof_sqft' => 'Sq ft'],
                    ];
                    foreach ($groups as $label => $cols): ?>
                        <tr>
                            <th><label><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php foreach ($cols as $col => $cl): ?>
                                    <label style="display:inline-block;margin-right:14px;"><?php echo esc_html($cl); ?>
                                        <input name="<?php echo esc_attr($col); ?>" type="number" step="0.01" style="width:90px" value="<?php echo $val($col); ?>">
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><label>Total ft²</label></th>
                        <td><input name="total_sqft" type="number" step="0.01" style="width:120px" value="<?php echo $val('total_sqft'); ?>">
                            <p class="description">Full-wrap area. Typically ≈ 2×side + back + hood + roof.</p></td>
                    </tr>
                </table>

                <p class="submit">
                    <button class="button button-primary"><?php echo $is_edit ? 'Save changes' : 'Add vehicle'; ?></button>
                    <?php if ($is_edit): ?>
                        <span style="margin-left:18px;"></span>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ($is_edit): ?>
                <form method="post" onsubmit="return confirm('Delete this vehicle permanently?');">
                    <?php wp_nonce_field(self::NONCE_EDIT); ?>
                    <input type="hidden" name="adwrap_action" value="delete_vehicle">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="button button-link-delete" style="color:#b32d2e;">Delete vehicle</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_save_vehicle(): void
    {
        check_admin_referer(self::NONCE_EDIT);
        global $wpdb;

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'make'      => sanitize_text_field(wp_unslash($_POST['make'] ?? '')),
            'model'     => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
            'year_from' => $this->int_or_null($_POST['year_from'] ?? ''),
            'year_to'   => $this->int_or_null($_POST['year_to'] ?? ''),
        ];
        $data['year_raw'] = $this->build_year_label($data['year_from'], $data['year_to']);

        foreach (self::MEASURE_COLS as $col) {
            $data[$col] = $this->float_or_null($_POST[$col] ?? '');
        }
        $data['updated_at'] = current_time('mysql');

        if ($id) {
            $wpdb->update($this->table(), $data, ['id' => $id]);
            $this->set_notice('success', 'Vehicle updated.');
        } else {
            $wpdb->insert($this->table(), $data);
            $this->set_notice('success', 'Vehicle added.');
        }

        wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles'));
        exit;
    }

    private function handle_delete_vehicle(): void
    {
        check_admin_referer(self::NONCE_EDIT);
        global $wpdb;
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $wpdb->delete($this->table(), ['id' => $id]);
            $this->set_notice('success', 'Vehicle deleted.');
        }
        wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles'));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Admin: CSV import                                                   */
    /* ------------------------------------------------------------------ */

    public function render_import_page(): void
    {
        global $wpdb;
        $count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
        $notice = $this->take_notice();
        ?>
        <div class="wrap">
            <h1>Import Vehicles (CSV)</h1>
            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['msg']); ?></p></div>
            <?php endif; ?>

            <p>Current catalog: <strong><?php echo number_format($count); ?></strong> vehicles.</p>
            <p>Upload a CSV with the header row:
                <code>make,model,year,side_width,side_height,side_sqft,back_width,back_height,back_sqft,hood_width,hood_length,hood_sqft,roof_width,roof_length,roof_sqft,total_sqft</code>.
                <br>The <code>year</code> column accepts a range like <code>2014-2021</code> or a single year <code>2021</code>.
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field(self::NONCE_IMPORT); ?>
                <input type="hidden" name="adwrap_action" value="import_csv">
                <table class="form-table">
                    <tr>
                        <th><label for="csv">CSV file</label></th>
                        <td><input type="file" name="csv" id="csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th>Options</th>
                        <td>
                            <label><input type="checkbox" name="extend_2021" value="1" checked>
                                Extend year ranges ending in 2021 up to <strong>2026</strong></label><br>
                            <label><input type="checkbox" name="replace_all" value="1">
                                Replace the entire catalog (delete existing rows first)</label>
                            <p class="description">Without "replace", rows are matched on make + model + year and updated in place; new combinations are inserted.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary">Import CSV</button></p>
            </form>
        </div>
        <?php
    }

    private function handle_import_csv(): void
    {
        check_admin_referer(self::NONCE_IMPORT);
        global $wpdb;
        $table = $this->table();

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $this->set_notice('error', 'No file uploaded.');
            wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles-import'));
            exit;
        }

        $extend  = !empty($_POST['extend_2021']);
        $replace = !empty($_POST['replace_all']);

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $this->set_notice('error', 'Could not read the uploaded file.');
            wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles-import'));
            exit;
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            $this->set_notice('error', 'CSV appears to be empty.');
            wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles-import'));
            exit;
        }
        // Normalize header → column index map.
        $idx = [];
        foreach ($header as $i => $h) {
            $idx[strtolower(trim((string) $h))] = $i;
        }

        if ($replace) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }

        $created = 0;
        $updated = 0;
        $now     = current_time('mysql');

        while (($cells = fgetcsv($fh)) !== false) {
            $get = function (string $key) use ($idx, $cells) {
                $i = $idx[$key] ?? null;
                return $i === null ? '' : trim((string) ($cells[$i] ?? ''));
            };

            $make  = sanitize_text_field($get('make'));
            $model = sanitize_text_field($get('model'));
            $yrraw = $get('year');
            if ($make === '' && $model === '' && $yrraw === '') {
                continue; // skip blank lines
            }

            [$from, $to] = $this->parse_year_range($yrraw);
            if ($extend && $to === 2021) {
                $to = 2026;
            }
            $year_label = $this->build_year_label($from, $to);

            $data = [
                'make'      => $make,
                'model'     => $model,
                'year_raw'  => $year_label,
                'year_from' => $from,
                'year_to'   => $to,
                'updated_at' => $now,
            ];
            foreach (self::MEASURE_COLS as $col) {
                $data[$col] = $this->float_or_null($get($col));
            }

            // Upsert on make+model+year_raw (unless we just truncated).
            $existing = $replace ? null : $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE make = %s AND model = %s AND year_raw = %s LIMIT 1",
                $make, $model, $year_label
            ));

            if ($existing) {
                $wpdb->update($table, $data, ['id' => (int) $existing]);
                $updated++;
            } else {
                $wpdb->insert($table, $data);
                $created++;
            }
        }
        fclose($fh);

        $this->notify_next_revalidate();
        $this->set_notice('success', sprintf(
            'Import complete. Created %d, updated %d.%s',
            $created,
            $updated,
            $extend ? ' Year ranges ending 2021 were extended to 2026.' : ''
        ));
        wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles-import'));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Admin: Pricing                                                      */
    /* ------------------------------------------------------------------ */

    public function render_pricing_page(): void
    {
        $p      = $this->pricing();
        $notice = $this->take_notice();
        ?>
        <div class="wrap">
            <h1>Wrap Pricing</h1>
            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['msg']); ?></p></div>
            <?php endif; ?>
            <p>These values drive the public wrap calculator:
                <code>price = coverage&nbsp;ft² × price/ft² × material&nbsp;multiplier + base fee</code> (clamped to a minimum).</p>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_PRICE); ?>
                <input type="hidden" name="adwrap_action" value="save_pricing">
                <table class="form-table">
                    <tr><th><label>Currency symbol</label></th><td><input name="currency" value="<?php echo esc_attr($p['currency']); ?>" style="width:60px"></td></tr>
                    <tr><th><label>Price per ft²</label></th><td><input name="price_per_sqft" type="number" step="0.01" value="<?php echo esc_attr($p['price_per_sqft']); ?>"></td></tr>
                    <tr><th><label>Base / labour fee</label></th><td><input name="base_fee" type="number" step="0.01" value="<?php echo esc_attr($p['base_fee']); ?>"></td></tr>
                    <tr><th><label>Minimum price</label></th><td><input name="min_price" type="number" step="0.01" value="<?php echo esc_attr($p['min_price']); ?>"></td></tr>
                </table>

                <h2>Materials / finishes</h2>
                <p class="description">Multiplier applied to the per-ft² price. Leave a label blank to remove that row.</p>
                <table class="widefat" style="max-width:520px;">
                    <thead><tr><th>Label</th><th>Multiplier</th></tr></thead>
                    <tbody>
                        <?php
                        $materials = $p['materials'];
                        $materials[] = ['label' => '', 'multiplier' => 1.0]; // one empty row to add more
                        foreach ($materials as $m): ?>
                            <tr>
                                <td><input name="material_label[]" value="<?php echo esc_attr($m['label']); ?>" class="regular-text"></td>
                                <td><input name="material_mult[]" type="number" step="0.01" value="<?php echo esc_attr($m['multiplier']); ?>" style="width:90px"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit"><button class="button button-primary">Save pricing</button></p>
            </form>
        </div>
        <?php
    }

    private function handle_save_pricing(): void
    {
        check_admin_referer(self::NONCE_PRICE);

        $labels = (array) ($_POST['material_label'] ?? []);
        $mults  = (array) ($_POST['material_mult'] ?? []);
        $materials = [];
        foreach ($labels as $i => $label) {
            $label = sanitize_text_field(wp_unslash($label));
            if ($label === '') {
                continue;
            }
            $materials[] = [
                'label'      => $label,
                'multiplier' => (float) ($mults[$i] ?? 1),
            ];
        }
        if (!$materials) {
            $materials[] = ['label' => 'Gloss', 'multiplier' => 1.0];
        }

        update_option(self::PRICING_OPTION, [
            'currency'       => sanitize_text_field(wp_unslash($_POST['currency'] ?? '$')) ?: '$',
            'price_per_sqft' => (float) ($_POST['price_per_sqft'] ?? 0),
            'base_fee'       => (float) ($_POST['base_fee'] ?? 0),
            'min_price'      => (float) ($_POST['min_price'] ?? 0),
            'materials'      => $materials,
        ]);

        $this->notify_next_revalidate();
        $this->set_notice('success', 'Pricing saved.');
        wp_safe_redirect(admin_url('admin.php?page=adwrap-vehicles-pricing'));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* REST API (namespace adwrap/v1) — read-only, public                  */
    /* ------------------------------------------------------------------ */

    public function register_rest_routes(): void
    {
        $public = ['methods' => 'GET', 'permission_callback' => '__return_true'];

        register_rest_route('adwrap/v1', '/makes', $public + ['callback' => [$this, 'rest_makes']]);
        register_rest_route('adwrap/v1', '/models', $public + ['callback' => [$this, 'rest_models']]);
        register_rest_route('adwrap/v1', '/vehicles', $public + ['callback' => [$this, 'rest_vehicles']]);
        register_rest_route('adwrap/v1', '/pricing', $public + ['callback' => [$this, 'rest_pricing']]);
    }

    public function rest_makes(): WP_REST_Response
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT make, COUNT(*) AS count FROM {$this->table()} WHERE make <> '' GROUP BY make ORDER BY make ASC", ARRAY_A);
        $rows = array_map(fn($r) => ['make' => $r['make'], 'count' => (int) $r['count']], $rows ?: []);
        return new WP_REST_Response($rows, 200);
    }

    public function rest_models(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $make = sanitize_text_field((string) $req->get_param('make'));
        if ($make === '') {
            return new WP_REST_Response([], 200);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT model, COUNT(*) AS variants FROM {$this->table()} WHERE make = %s AND model <> '' GROUP BY model ORDER BY model ASC",
            $make
        ), ARRAY_A);
        $rows = array_map(fn($r) => ['model' => $r['model'], 'variants' => (int) $r['variants']], $rows ?: []);
        return new WP_REST_Response($rows, 200);
    }

    public function rest_vehicles(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $table = $this->table();

        $make   = sanitize_text_field((string) $req->get_param('make'));
        $model  = sanitize_text_field((string) $req->get_param('model'));
        $search = sanitize_text_field((string) $req->get_param('search'));
        $per    = min(2000, max(1, (int) ($req->get_param('per_page') ?: 50)));
        $page   = max(1, (int) ($req->get_param('page') ?: 1));
        $offset = ($page - 1) * $per;

        $where  = '1=1';
        $params = [];
        if ($make !== '')  { $where .= ' AND make = %s';  $params[] = $make; }
        if ($model !== '') { $where .= ' AND model = %s'; $params[] = $model; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (make LIKE %s OR model LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY make ASC, model ASC, year_from ASC LIMIT %d OFFSET %d";
        $rows   = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per, $offset])), ARRAY_A);
        $items  = array_map([$this, 'shape_vehicle'], $rows ?: []);

        return new WP_REST_Response(['total' => $total, 'page' => $page, 'per_page' => $per, 'items' => $items], 200);
    }

    public function rest_pricing(): WP_REST_Response
    {
        $p = $this->pricing();
        $p['price_per_sqft'] = (float) $p['price_per_sqft'];
        $p['base_fee']       = (float) $p['base_fee'];
        $p['min_price']      = (float) $p['min_price'];
        $p['materials']      = array_map(
            fn($m) => ['label' => (string) $m['label'], 'multiplier' => (float) $m['multiplier']],
            $p['materials']
        );
        return new WP_REST_Response($p, 200);
    }

    private function shape_vehicle(array $r): array
    {
        $num = fn($k) => $r[$k] === null || $r[$k] === '' ? null : (float) $r[$k];
        return [
            'id'        => (int) $r['id'],
            'make'      => $r['make'],
            'model'     => $r['model'],
            'year'      => $r['year_raw'],
            'year_from' => $r['year_from'] !== null ? (int) $r['year_from'] : null,
            'year_to'   => $r['year_to'] !== null ? (int) $r['year_to'] : null,
            'panels'    => [
                'side' => ['width' => $num('side_width'), 'height' => $num('side_height'), 'sqft' => $num('side_sqft')],
                'back' => ['width' => $num('back_width'), 'height' => $num('back_height'), 'sqft' => $num('back_sqft')],
                'hood' => ['width' => $num('hood_width'), 'length' => $num('hood_length'), 'sqft' => $num('hood_sqft')],
                'roof' => ['width' => $num('roof_width'), 'length' => $num('roof_length'), 'sqft' => $num('roof_sqft')],
            ],
            'total_sqft' => $num('total_sqft'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** Parse "2014-2021" / "2021" / "" → [from|null, to|null]. */
    private function parse_year_range(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})\s*-\s*(\d{2,4})$/', $raw, $m)) {
            $from = (int) $m[1];
            $to   = (int) $m[2];
            if ($to < 100) { // handle 2-digit end like 2006-11
                $to += (int) (substr($m[1], 0, 2) . '00');
            }
            return [$from, $to];
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[1]];
        }
        return [null, null];
    }

    private function build_year_label(?int $from, ?int $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }
        if ($from !== null && $to !== null) {
            return $from === $to ? (string) $from : "{$from}-{$to}";
        }
        return (string) ($from ?? $to);
    }

    private function int_or_null($v): ?int
    {
        $v = trim((string) $v);
        return $v === '' ? null : (int) $v;
    }

    private function float_or_null($v): ?float
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return null;
        }
        return (float) $v;
    }

    /**
     * Ping the Next.js site to bust its cached calculator data after an import
     * or pricing change. Reuses the credentials configured by the "Next.js
     * Revalidation" plugin (option `next_revalidation_settings`) so there is no
     * duplicate setup; sends contentType "vehicles" — the Next /api/revalidate
     * route always revalidates the shared "wordpress" tag, which the calculator
     * fetch is tagged with. No-op (and harmless) if Next isn't configured.
     */
    private function notify_next_revalidate(): void
    {
        $settings = get_option('next_revalidation_settings');
        if (empty($settings['next_url']) || empty($settings['webhook_secret'])) {
            return;
        }

        $endpoint = trailingslashit($settings['next_url']) . 'api/revalidate';
        wp_remote_post($endpoint, [
            'timeout'  => 5,
            'blocking' => false, // fire-and-forget; don't delay the admin redirect
            'headers'  => [
                'Content-Type'     => 'application/json',
                'x-webhook-secret' => $settings['webhook_secret'],
            ],
            'body'     => wp_json_encode(['contentType' => 'vehicles']),
        ]);
    }

    private function set_notice(string $type, string $msg): void
    {
        set_transient('adwrap_vehicles_notice', ['type' => $type, 'msg' => $msg], 60);
    }

    private function take_notice(): ?array
    {
        $n = get_transient('adwrap_vehicles_notice');
        if ($n) {
            delete_transient('adwrap_vehicles_notice');
        }
        return $n ?: null;
    }
}

new AdwrapVehicles();
