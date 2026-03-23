<?php

/**
 * Plugin Name: Adwrap Location Import
 * Description: Bulk import location (city SEO) pages using a template location for images
 */

final class AdwrapLocationImport
{
    private const NONCE_ACTION = 'adwrap_import_cities';
    private const NONCE_FIELD  = '_adwrap_import_nonce';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_import']);
    }

    public function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=location',
            'Import Cities',
            'Import Cities',
            'manage_options',
            'adwrap-import-cities',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        $locations = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $cities   = $this->get_cities();
        $results  = get_transient('adwrap_import_results');

        if ($results) {
            delete_transient('adwrap_import_results');
        }
        ?>
        <div class="wrap">
            <h1>Import Location Cities</h1>
            <p>Select a template location (with images already set up), then import all 14 cities from the PDF content.<br>
               New posts are created as <strong>drafts</strong>. Cities that already exist (same service + state + city slug) are skipped.</p>

            <?php if ($results): ?>
                <div class="notice notice-<?php echo $results['has_errors'] ? 'warning' : 'success'; ?> is-dismissible">
                    <p><strong>Import complete.</strong>
                        Created: <?php echo $results['created']; ?>,
                        Skipped (already exist): <?php echo $results['skipped']; ?>,
                        Errors: <?php echo $results['errors']; ?>
                    </p>
                    <?php if (!empty($results['messages'])): ?>
                        <ul style="list-style:disc;margin-left:20px;">
                            <?php foreach ($results['messages'] as $msg): ?>
                                <li><?php echo esc_html($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="action" value="adwrap_import_cities">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="template_id">Template Location</label></th>
                        <td>
                            <select name="template_id" id="template_id" required>
                                <option value="">— Select —</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo $loc->ID; ?>">
                                        <?php echo esc_html($loc->post_title ?: "(ID {$loc->ID}) no title"); ?>
                                        (<?php echo $loc->post_status; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose the location you've already filled in with hero image, service images, process icons, about section, etc.</p>
                        </td>
                    </tr>
                </table>

                <h2>Cities to Import (<?php echo count($cities); ?>)</h2>
                <table class="widefat striped" style="max-width:800px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>City</th>
                            <th>Slug</th>
                            <th>Nearby Cities</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cities as $i => $city): ?>
                            <?php $exists = $this->city_exists('vehicle-wraps', 'illinois', $city['city_slug']); ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo esc_html($city['city_name']); ?></strong></td>
                                <td><code><?php echo esc_html($city['city_slug']); ?></code></td>
                                <td><?php echo esc_html(implode(', ', $city['nearby'])); ?></td>
                                <td>
                                    <?php if ($exists): ?>
                                        <span style="color:#999;">Already exists</span>
                                    <?php else: ?>
                                        <span style="color:#2271b1;">Will create</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Import All Cities"
                           onclick="return confirm('Create draft location posts for all cities that don\'t exist yet?');">
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_import(): void
    {
        if (!isset($_POST['action']) || $_POST['action'] !== 'adwrap_import_cities') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $template_id = (int) ($_POST['template_id'] ?? 0);
        if (!$template_id || get_post_type($template_id) !== 'location') {
            set_transient('adwrap_import_results', [
                'created'    => 0,
                'skipped'    => 0,
                'errors'     => 1,
                'has_errors' => true,
                'messages'   => ['Invalid template location selected.'],
            ], 60);
            return;
        }

        $template_service_slug  = get_field('service_slug', $template_id) ?: 'vehicle-wraps';
        $template_service_label = get_field('service_label', $template_id) ?: 'Vehicle Wraps';
        $template_blocks        = get_field('location_blocks', $template_id) ?: [];
        $template_thumbnail_id  = get_post_thumbnail_id($template_id);

        $cities   = $this->get_cities();
        $created  = 0;
        $skipped  = 0;
        $errors   = 0;
        $messages = [];

        foreach ($cities as $city) {
            if ($this->city_exists($template_service_slug, 'illinois', $city['city_slug'])) {
                $skipped++;
                $messages[] = "Skipped {$city['city_name']} — already exists.";
                continue;
            }

            $post_id = wp_insert_post([
                'post_type'   => 'location',
                'post_status' => 'draft',
                'post_title'  => "{$template_service_label} in {$city['city_name']}, IL",
            ], true);

            if (is_wp_error($post_id)) {
                $errors++;
                $messages[] = "Error creating {$city['city_name']}: {$post_id->get_error_message()}";
                continue;
            }

            update_field('service_slug', $template_service_slug, $post_id);
            update_field('service_label', $template_service_label, $post_id);
            update_field('state', 'illinois', $post_id);
            update_field('state_label', 'Illinois', $post_id);
            update_field('state_abbreviation', 'IL', $post_id);
            update_field('city', $city['city_slug'], $post_id);
            update_field('city_label', $city['city_name'], $post_id);
            update_field('suburb', '', $post_id);
            update_field('suburb_label', '', $post_id);
            update_field('latitude', $city['lat'], $post_id);
            update_field('longitude', $city['lng'], $post_id);

            $blocks = $this->build_blocks_for_city($city, $template_blocks, $template_service_label);
            if (!empty($blocks)) {
                update_field('location_blocks', $blocks, $post_id);
            }

            if ($template_thumbnail_id) {
                set_post_thumbnail($post_id, $template_thumbnail_id);
            }

            $created++;
            $messages[] = "Created {$city['city_name']} (post #{$post_id}, draft).";
        }

        set_transient('adwrap_import_results', [
            'created'    => $created,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'has_errors' => $errors > 0,
            'messages'   => $messages,
        ], 60);

        wp_safe_redirect(admin_url('edit.php?post_type=location&page=adwrap-import-cities'));
        exit;
    }

    private function build_blocks_for_city(array $city, array $template_blocks, string $service_label): array
    {
        $name        = $city['city_name'];
        $nearby_list = implode(', ', array_slice($city['nearby'], 0, -1))
                       . ', and ' . end($city['nearby']);

        $template_map = [];
        foreach ($template_blocks as $block) {
            $layout = $block['acf_fc_layout'] ?? '';
            if ($layout) {
                $template_map[$layout] = $block;
            }
        }

        $blocks = [];

        $blocks[] = [
            'acf_fc_layout'    => 'hero_block',
            'hero_image'       => $this->img_id($template_map['hero_block']['hero_image'] ?? null),
            'hero_title'       => "{$service_label} in {$name}, IL",
            'hero_description' => "AdWrap Graphics offers professional vehicle wraps and custom graphics in {$name}, IL \u{2014} converting company vehicles into attention-grabbing mobile advertising. We serve businesses throughout {$name}, {$nearby_list}, and the greater Chicagoland area with fleet wraps, van wraps, box truck wraps, pickup truck wraps, and more.",
        ];

        $tpl_svc_items = $template_map['services_block']['items'] ?? [];
        $svc_images = array_map(fn($item) => $this->img_id($item['image'] ?? null), $tpl_svc_items);

        $blocks[] = [
            'acf_fc_layout' => 'services_block',
            'title'         => "Vehicle Wrap Services in {$name}, IL",
            'description'   => "We offer real expertise, high-quality 3M vinyl, premium eco-solvent inks, and professional graphic design services to businesses in {$name}. Every wrap is designed, printed, and installed in-house at our Itasca, IL facility.",
            'items'         => [
                [
                    'image'       => $svc_images[0] ?? '',
                    'title'       => 'Full Commercial Wraps',
                    'description' => "Maximize your brand exposure in {$name} with a full commercial wrap. A full wrap covers your entire vehicle \u{2014} hood, doors, sides, and rear \u{2014} turning it into a 360-degree mobile advertisement for your business. Full wraps deliver the highest ROI of any vehicle branding option and are built to last 5\u{2013}7 years with premium 3M vinyl.",
                    'button_text' => 'Get a free quote',
                    'button_link' => '/contact-us',
                ],
                [
                    'image'       => $svc_images[1] ?? '',
                    'title'       => 'Half Commercial Wraps',
                    'description' => "A half wrap is a smart, cost-effective branding solution for {$name} businesses. Covering the lower or upper half of your vehicle, it delivers strong visual impact while keeping costs manageable. Perfect for businesses looking to establish a brand presence on the road without a full wrap investment.",
                    'button_text' => 'Get a free quote',
                    'button_link' => '/contact-us',
                ],
                [
                    'image'       => $svc_images[2] ?? '',
                    'title'       => 'Partial Commercial Wraps',
                    'description' => "A partial wrap is the ideal entry-level vehicle branding option for {$name} businesses. Featuring your logo, business name, phone number, and website in a clean, focused layout, a partial wrap is affordable, professional, and effective \u{2014} a great first step toward building your mobile brand.",
                    'button_text' => 'Get a free quote',
                    'button_link' => '/contact-us',
                ],
            ],
        ];

        if (isset($template_map['about_block'])) {
            $ab = $template_map['about_block'];
            $blocks[] = [
                'acf_fc_layout' => 'about_block',
                'label'         => $ab['label'] ?? 'ABOUT US',
                'title'         => $ab['title'] ?? '',
                'description'   => str_replace(
                    $this->get_template_city_name($template_blocks),
                    $name,
                    $ab['description'] ?? ''
                ),
                'image'         => $this->img_id($ab['image'] ?? null),
            ];
        }

        $vt = $this->get_vehicle_types_data();
        $vt_city = $vt[$city['city_slug']] ?? null;
        if ($vt_city) {
            $tpl_vt_items = $template_map['vehicle_types_block']['items'] ?? [];
            $vt_images = array_map(fn($item) => $this->img_id($item['image'] ?? null), $tpl_vt_items);

            $blocks[] = [
                'acf_fc_layout' => 'vehicle_types_block',
                'title'         => "Vehicle Wraps for Every Business in {$name}, IL",
                'description'   => $vt_city['intro'],
                'items'         => [
                    [
                        'image'       => $vt_images[0] ?? '',
                        'title'       => "Fleet Wraps in {$name}",
                        'description' => $vt_city['fleet'],
                    ],
                    [
                        'image'       => $vt_images[1] ?? '',
                        'title'       => "Van Wraps in {$name}",
                        'description' => $vt_city['van'],
                    ],
                    [
                        'image'       => $vt_images[2] ?? '',
                        'title'       => "Box Truck Wraps in {$name}",
                        'description' => $vt_city['box_truck'],
                    ],
                    [
                        'image'       => $vt_images[3] ?? '',
                        'title'       => "Pickup Truck Wraps in {$name}",
                        'description' => $vt_city['pickup'],
                    ],
                ],
            ];
        }

        $tpl_proc_items = $template_map['process_block']['items'] ?? [];
        $proc_icons = array_map(fn($item) => $this->img_id($item['icon'] ?? null), $tpl_proc_items);

        $blocks[] = [
            'acf_fc_layout' => 'process_block',
            'title'         => 'Our Creative Process',
            'subtitle'      => "From Design to Installation \u{2014} {$name}, IL",
            'items'         => [
                [
                    'icon'        => $proc_icons[0] ?? '',
                    'title'       => 'Strategic Design For Real-World Impact',
                    'description' => "Our designers build every {$name} vehicle wrap from scratch \u{2014} tailoring colors, layout, and typography to your brand and vehicle type. Whether it\u{2019}s a fleet van, box truck, or pickup, we design for maximum readability and visual impact at speed.",
                ],
                [
                    'icon'        => $proc_icons[1] ?? '',
                    'title'       => 'High-Performance Printing For Maximum Visibility',
                    'description' => "We print all vehicle wraps in-house using premium eco-solvent inks on 3M IJ175C vinyl, finished with 3M 8418G gloss laminate. The result is a vibrant, weather-resistant wrap that holds its color and clarity for years \u{2014} even through harsh Chicago-area winters.",
                ],
                [
                    'icon'        => $proc_icons[2] ?? '',
                    'title'       => 'Certified Installation By Industry Experts',
                    'description' => "Our certified installers handle every {$name} vehicle wrap with care and precision \u{2014} ensuring a smooth, bubble-free, edge-to-edge installation that protects your paint and looks professional from every angle. Backed by our quality guarantee.",
                ],
            ],
        ];

        if (isset($template_map['projects_block'])) {
            $pj = $template_map['projects_block'];
            $blocks[] = [
                'acf_fc_layout' => 'projects_block',
                'title'         => $pj['title'] ?? '',
                'description'   => $pj['description'] ?? '',
                'portfolio_ids' => $this->extract_relationship_ids($pj['portfolio_ids'] ?? []),
            ];
        }

        if (isset($template_map['reviews_block'])) {
            $rv = $template_map['reviews_block'];
            $blocks[] = [
                'acf_fc_layout' => 'reviews_block',
                'title'         => $rv['title'] ?? '',
                'description'   => $rv['description'] ?? '',
            ];
        }

        return $blocks;
    }

    private function img_id($value): int|string
    {
        if (is_array($value) && isset($value['ID'])) {
            return (int) $value['ID'];
        }
        if (is_numeric($value) && $value > 0) {
            return (int) $value;
        }
        return '';
    }

    private function extract_relationship_ids($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_map(function ($item) {
            if (is_object($item) && isset($item->ID)) {
                return $item->ID;
            }
            if (is_array($item) && isset($item['ID'])) {
                return $item['ID'];
            }
            return (int) $item;
        }, $value);
    }

    private function get_template_city_name(array $template_blocks): string
    {
        foreach ($template_blocks as $block) {
            if (($block['acf_fc_layout'] ?? '') === 'hero_block') {
                $title = $block['hero_title'] ?? '';
                if (preg_match('/in\s+(.+),\s*[A-Z]{2}$/i', $title, $m)) {
                    return trim($m[1]);
                }
            }
        }
        return '';
    }

    private function city_exists(string $service_slug, string $state, string $city_slug): bool
    {
        $posts = get_posts([
            'post_type'      => 'location',
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'service_slug', 'value' => $service_slug, 'compare' => '='],
                ['key' => 'state',        'value' => $state,        'compare' => '='],
                ['key' => 'city',         'value' => $city_slug,    'compare' => '='],
                [
                    'relation' => 'OR',
                    ['key' => 'suburb', 'value' => '', 'compare' => '='],
                    ['key' => 'suburb', 'compare' => 'NOT EXISTS'],
                ],
            ],
            'fields' => 'ids',
        ]);

        return !empty($posts);
    }

    private function get_vehicle_types_data(): array
    {
        return [
            'schaumburg' => [
                'intro'     => "AdWrap Graphics specializes in commercial vehicle wraps for businesses of all sizes in Schaumburg. Whether you operate a single van or a full fleet, we produce high-impact, custom-printed wraps using premium 3M vinyl that turn your vehicles into powerful mobile advertising tools \u{2014} generating thousands of impressions daily on Schaumburg roads.",
                'fleet'     => "Uniform, professional fleet wraps for Schaumburg businesses help build instant brand recognition across every vehicle in your lineup. From 2-vehicle startups to 50+ vehicle fleets, we keep your branding consistent, sharp, and road-ready.",
                'van'       => "Cargo vans and sprinter vans are among the best mobile advertising surfaces available. A custom van wrap for your Schaumburg business puts your brand, phone number, and services in front of thousands of local customers every day.",
                'box_truck' => "Box trucks offer massive billboard-sized canvas space. Our Schaumburg box truck wraps are designed for maximum visibility \u{2014} bold, high-contrast graphics that are readable at highway speeds and in busy commercial zones.",
                'pickup'    => "Pickup trucks are everywhere in Schaumburg \u{2014} and a professionally wrapped truck signals professionalism and credibility. We design and install custom pickup truck wraps that represent your brand with style and durability.",
            ],
            'bloomingdale' => [
                'intro'     => "AdWrap Graphics provides professional vehicle wrap services for businesses throughout Bloomingdale. We specialize in fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} all printed with premium 3M vinyl and installed by our certified team right here in nearby Itasca, IL.",
                'fleet'     => "A cohesive fleet wrap program for your Bloomingdale business means every vehicle on the road represents your brand the same way \u{2014} professional, consistent, and memorable. We handle fleets of any size with fast turnaround.",
                'van'       => "Whether it\u{2019}s a cargo van, sprinter, or transit van, a custom wrap from AdWrap Graphics turns your Bloomingdale work vehicle into a 24/7 advertisement. Bold design, durable materials, and expert installation.",
                'box_truck' => "Box trucks deliver unmatched advertising surface for Bloomingdale businesses. Our oversized, high-resolution box truck wraps are engineered for visibility \u{2014} making your brand impossible to ignore on local roads and highways.",
                'pickup'    => "A wrapped pickup truck tells Bloomingdale customers you mean business. Custom-designed and professionally installed, our pickup truck wraps are built to withstand the elements while keeping your brand looking sharp.",
            ],
            'addison' => [
                'intro'     => "Businesses in Addison trust AdWrap Graphics for professional commercial vehicle wraps that deliver real marketing results. We wrap vans, box trucks, pickup trucks, and entire fleets using premium 3M vinyl and precision installation \u{2014} giving your vehicles a look that commands attention on every road in Addison and beyond.",
                'fleet'     => "Turn every vehicle in your Addison fleet into a branded marketing asset. Our fleet wrap programs are designed for consistency, durability, and fast production \u{2014} so your entire lineup looks professional and unified from day one.",
                'van'       => "Vans are workhorses \u{2014} and a well-designed van wrap makes them work even harder for your Addison business. We create bold, branded van wraps that are printed in-house and installed to last for years.",
                'box_truck' => "The large flat panels of a box truck are the perfect canvas for high-impact commercial graphics. Our Addison box truck wraps are designed large, printed sharp, and installed to maximize your brand\u{2019}s visibility on the road.",
                'pickup'    => "Pickup truck wraps are one of the most cost-effective branding investments an Addison contractor or service business can make. We design and install durable, professional-grade pickup wraps that represent your brand with authority.",
            ],
            'wood-dale' => [
                'intro'     => "AdWrap Graphics brings commercial vehicle wrap expertise to businesses in Wood Dale, IL. Our team designs, prints, and installs fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} all using premium 3M materials \u{2014} turning your vehicles into high-visibility mobile advertisements for your Wood Dale business.",
                'fleet'     => "A professional fleet wrap program gives every Wood Dale business vehicle a unified, polished brand identity. Whether you have 2 or 20 vehicles, we ensure consistent, high-quality graphics across your entire fleet.",
                'van'       => "Work vans travel hundreds of miles each week through Wood Dale and surrounding areas \u{2014} and a custom van wrap ensures your brand is visible the entire time. Bold, durable, and designed to generate leads.",
                'box_truck' => "Box trucks are rolling billboards, and AdWrap Graphics knows how to make them count. Our Wood Dale box truck wraps are built for maximum impact with high-resolution graphics that stand out in traffic and at job sites.",
                'pickup'    => "A wrapped pickup truck is one of the most visible brand signals a Wood Dale trade business can display. We produce rugged, professional pickup truck wraps built to handle the demands of an active work vehicle.",
            ],
            'elmhurst' => [
                'intro'     => "AdWrap Graphics is the trusted vehicle wrap partner for businesses throughout Elmhurst. We specialize in fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} all printed and installed in-house using premium 3M vinyl \u{2014} delivering commercial-grade vehicle graphics that elevate your brand on every road in Elmhurst.",
                'fleet'     => "From small business fleets to large commercial operations in Elmhurst, our fleet wrap services ensure every vehicle in your lineup represents your brand with the same high standard of quality, consistency, and professionalism.",
                'van'       => "A professionally wrapped van is one of the most effective marketing tools an Elmhurst service business can have. We craft eye-catching van wraps that drive brand awareness and generate phone calls \u{2014} all day, every day.",
                'box_truck' => "Box truck wraps are among the highest-ROI advertising investments available to Elmhurst businesses. Our large-format graphics are designed for impact and printed with vivid, fade-resistant color that lasts for years.",
                'pickup'    => "A sharp pickup truck wrap tells Elmhurst customers your business is serious. We design and install custom pickup wraps tailored to your brand \u{2014} professional, durable, and built to perform in all weather conditions.",
            ],
            'villa-park' => [
                'intro'     => "AdWrap Graphics delivers premium commercial vehicle wraps to businesses throughout Villa Park. Our in-house team handles everything \u{2014} from design to print to installation \u{2014} producing fleet wraps, van wraps, box truck wraps, and pickup truck wraps that make Villa Park businesses impossible to miss on the road.",
                'fleet'     => "A coordinated fleet wrap program for your Villa Park business creates a powerful, unified brand presence across every vehicle you operate. We manage fleet projects of any size with efficiency and consistent quality.",
                'van'       => "Cargo vans, sprinters, and transit vans are ideal wrapping surfaces for Villa Park service companies. Our van wraps are designed to turn heads, communicate your services, and drive calls from local customers.",
                'box_truck' => "The large surface area of a box truck makes it one of the best advertising mediums available to Villa Park businesses. We design and install oversized, high-impact graphics that make your brand visible from every angle.",
                'pickup'    => "For Villa Park contractors, landscapers, and service professionals, a custom pickup truck wrap is an essential business tool. We produce tough, good-looking wraps that represent your brand wherever the job takes you.",
            ],
            'lombard' => [
                'intro'     => "From single vehicle wraps to full fleet branding programs, AdWrap Graphics provides commercial vehicle wrap solutions for businesses throughout Lombard. We work with fleet vehicles, vans, box trucks, and pickup trucks \u{2014} producing premium 3M vinyl wraps that build brand recognition and drive business results.",
                'fleet'     => "Consistent fleet branding sets successful Lombard businesses apart from the competition. Our fleet wrap programs are designed for uniformity, durability, and fast turnaround \u{2014} keeping your vehicles looking professional and on-brand at all times.",
                'van'       => "Work vans covering Lombard routes are one of your most valuable advertising assets. A custom van wrap from AdWrap Graphics ensures your brand, services, and contact info are in front of local customers every mile of every day.",
                'box_truck' => "Box truck wraps deliver the biggest visual impact for Lombard businesses. We design bold, high-contrast graphics that leverage every square inch of your truck\u{2019}s surface \u{2014} turning it into a rolling billboard that works around the clock.",
                'pickup'    => "Pickup truck wraps are one of the smartest investments a Lombard trade or service business can make. We design custom wraps that project professionalism and brand authority everywhere your truck goes.",
            ],
            'carol-stream' => [
                'intro'     => "AdWrap Graphics provides expert commercial vehicle wraps for businesses throughout Carol Stream. Our in-house team specializes in fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} all produced with premium 3M materials and installed with precision to give Carol Stream businesses maximum brand visibility on the road.",
                'fleet'     => "A unified fleet wrap program for your Carol Stream business means every vehicle you send out is a professional brand ambassador. We handle fleet programs of all sizes with consistent quality and efficient production timelines.",
                'van'       => "Service vans traveling Carol Stream streets every day are prime advertising real estate. Our custom van wraps are designed to capture attention, communicate your message, and generate leads for your Carol Stream business.",
                'box_truck' => "Box trucks offer the most advertising surface of any vehicle type \u{2014} and our Carol Stream box truck wraps are built to take full advantage. High-resolution, large-format graphics that stop traffic and build brand recognition.",
                'pickup'    => "A professionally wrapped pickup truck makes a strong statement for Carol Stream contractors and service businesses. We build rugged, eye-catching wraps that represent your brand with professionalism and durability.",
            ],
            'roselle' => [
                'intro'     => "Businesses in Roselle rely on AdWrap Graphics for professional vehicle wraps that drive brand awareness and generate leads. We design, print, and install fleet wraps, van wraps, box truck wraps, and pickup truck wraps using premium 3M vinyl \u{2014} delivering mobile advertising solutions that work hard for Roselle businesses every day.",
                'fleet'     => "Fleet wraps are the most scalable branding investment a Roselle business can make. We create cohesive, durable fleet graphics that keep your brand looking sharp across every vehicle \u{2014} whether you have 3 or 30.",
                'van'       => "A wrapped service van traveling through Roselle can generate over 30,000 daily impressions. Our custom van wraps are built to capture those impressions with bold design, vibrant color, and premium long-lasting vinyl.",
                'box_truck' => "Box trucks are mobile billboards \u{2014} and AdWrap Graphics knows how to design for maximum impact. Our Roselle box truck wraps are built to be read fast, remembered long, and admired wherever your truck travels.",
                'pickup'    => "For Roselle contractors, HVAC technicians, plumbers, and other service pros, a wrapped pickup truck is a must-have marketing tool. We produce professional, long-lasting pickup wraps that make your brand stand out at every job site.",
            ],
            'hanover-park' => [
                'intro'     => "AdWrap Graphics brings professional vehicle wrap solutions to businesses in Hanover Park. We specialize in fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} all designed in-house and installed by our certified team using premium 3M vinyl that delivers lasting performance and powerful brand impact.",
                'fleet'     => "Fleet wraps give Hanover Park businesses a competitive branding edge. A uniformly wrapped fleet signals professionalism, builds local brand recognition, and turns every vehicle in your lineup into a lead-generating machine.",
                'van'       => "From cargo vans to full-size sprinters, we design and install custom van wraps for Hanover Park businesses that want to maximize every mile. Eye-catching design, premium materials, and expert installation \u{2014} all in-house.",
                'box_truck' => "Box truck wraps are one of the most cost-effective advertising tools available to Hanover Park businesses. Our team creates oversized, high-impact graphics that command attention on the road and in commercial areas.",
                'pickup'    => "A custom pickup truck wrap is one of the best investments a Hanover Park service business can make. We design wraps that build brand authority, generate leads, and hold up against daily wear and changing weather.",
            ],
            'bartlett' => [
                'intro'     => "AdWrap Graphics provides commercial vehicle wraps for businesses throughout Bartlett \u{2014} from single van wraps to complete fleet branding programs. We use premium 3M vinyl, professional design, and certified installation to produce vehicle graphics that make Bartlett businesses stand out on every road they travel.",
                'fleet'     => "Building a recognizable brand in Bartlett starts with a consistent fleet wrap program. We design and install uniform fleet graphics that ensure every vehicle you operate projects the same polished, professional image.",
                'van'       => "Work vans are some of the most effective mobile advertising platforms available. Our Bartlett van wraps are custom-designed to showcase your brand, services, and contact information in a way that generates real business results.",
                'box_truck' => "Box trucks traveling Bartlett roads and the surrounding Chicagoland area provide enormous advertising reach. We create high-impact, full-coverage box truck wraps that turn your delivery or service vehicle into a brand powerhouse.",
                'pickup'    => "Pickup truck wraps are essential for Bartlett\u{2019}s contractors, landscapers, and home service professionals. We produce bold, durable wraps that advertise your business on every job, every errand, and every mile in between.",
            ],
            'elk-grove-village' => [
                'intro'     => "AdWrap Graphics is the preferred commercial vehicle wrap provider for businesses in Elk Grove Village. Located just minutes away in Itasca, we design, print, and install fleet wraps, van wraps, box truck wraps, and pickup truck wraps that help Elk Grove Village businesses dominate local brand visibility.",
                'fleet'     => "Elk Grove Village is one of the largest business parks in the US \u{2014} and a professionally branded fleet sets your company apart. We produce cohesive, high-quality fleet wraps that make your vehicles instantly recognizable across the area.",
                'van'       => "Service and delivery vans are everywhere in Elk Grove Village\u{2019}s busy commercial corridors. A custom van wrap from AdWrap Graphics ensures your brand is seen and remembered by thousands of local business owners and consumers daily.",
                'box_truck' => "Box trucks operating in and around Elk Grove Village\u{2019}s industrial corridors are ideal for large-format commercial graphics. Our wraps are designed for high-visibility impact \u{2014} readable, bold, and built to last.",
                'pickup'    => "For trade businesses, contractors, and service companies in Elk Grove Village, a professionally wrapped pickup truck is an essential brand asset. We design and install wraps that command respect and build your brand reputation.",
            ],
            'bensenville' => [
                'intro'     => "AdWrap Graphics delivers professional commercial vehicle wraps to businesses throughout Bensenville. Just minutes away in Itasca, our team handles everything in-house \u{2014} fleet wraps, van wraps, box truck wraps, and pickup truck wraps \u{2014} using premium 3M materials to produce vehicle graphics that drive real brand results.",
                'fleet'     => "Fleet wraps are the most powerful branding investment a Bensenville business can make. We create unified, professional fleet graphics programs that ensure every vehicle in your lineup is a consistent brand ambassador on local roads.",
                'van'       => "A professionally wrapped work van traveling Bensenville and the surrounding area generates thousands of brand impressions daily. Our van wraps are bold, durable, and designed to convert visibility into phone calls and leads.",
                'box_truck' => "Box trucks near O\u{2019}Hare and throughout Bensenville\u{2019}s busy commercial zone are prime advertising real estate. We design and install large-format box truck wraps that make your business visible, credible, and memorable.",
                'pickup'    => "A custom pickup truck wrap is one of the smartest moves a Bensenville service or trade business can make. We produce tough, professional wraps that put your brand in front of local customers wherever the job takes you.",
            ],
            'chicago' => [
                'intro'     => "In a city as competitive as Chicago, standing out matters more than anywhere else \u{2014} and AdWrap Graphics helps businesses do exactly that with professional commercial vehicle wraps. We specialize in fleet wraps, van wraps, box truck wraps, and pickup truck wraps for Chicago businesses ready to turn their vehicles into high-impact mobile advertising.",
                'fleet'     => "Fleet wraps for Chicago businesses mean your brand is visible in neighborhoods, on expressways, and in commercial districts across the entire city. We produce cohesive, large-scale fleet branding programs that make Chicago companies impossible to miss.",
                'van'       => "Chicago\u{2019}s streets are crowded \u{2014} and a wrapped service van cuts through the noise. Our custom Chicago van wraps are designed to stand out in traffic, communicate your services instantly, and generate leads from every neighborhood you serve.",
                'box_truck' => "Box trucks navigating Chicago\u{2019}s urban grid offer enormous advertising reach. Our large-format Chicago box truck wraps are built for maximum visibility \u{2014} bold, high-contrast, and readable at speed from every direction.",
                'pickup'    => "Pickup truck wraps are a staple for Chicago\u{2019}s construction, landscaping, and service industries. We design and install professional-grade wraps that make your Chicago business look credible, established, and ready to win new customers.",
            ],
        ];
    }

    private function get_cities(): array
    {
        return [
            [
                'city_name' => 'Schaumburg',
                'city_slug' => 'schaumburg',
                'nearby'    => ['Hoffman Estates', 'Elk Grove Village', 'Palatine'],
                'lat'       => 42.0334,
                'lng'       => -88.0834,
            ],
            [
                'city_name' => 'Bloomingdale',
                'city_slug' => 'bloomingdale',
                'nearby'    => ['Roselle', 'Carol Stream', 'Itasca'],
                'lat'       => 41.9484,
                'lng'       => -88.0810,
            ],
            [
                'city_name' => 'Addison',
                'city_slug' => 'addison',
                'nearby'    => ['Villa Park', 'Elmhurst', 'Wood Dale'],
                'lat'       => 41.9317,
                'lng'       => -87.9890,
            ],
            [
                'city_name' => 'Wood Dale',
                'city_slug' => 'wood-dale',
                'nearby'    => ['Bensenville', 'Elk Grove Village', 'Addison'],
                'lat'       => 41.9633,
                'lng'       => -87.9790,
            ],
            [
                'city_name' => 'Elmhurst',
                'city_slug' => 'elmhurst',
                'nearby'    => ['Villa Park', 'Lombard', 'Addison'],
                'lat'       => 41.8995,
                'lng'       => -87.9403,
            ],
            [
                'city_name' => 'Villa Park',
                'city_slug' => 'villa-park',
                'nearby'    => ['Elmhurst', 'Lombard', 'Addison'],
                'lat'       => 41.8898,
                'lng'       => -87.9790,
            ],
            [
                'city_name' => 'Lombard',
                'city_slug' => 'lombard',
                'nearby'    => ['Villa Park', 'Elmhurst', 'Glen Ellyn'],
                'lat'       => 41.8800,
                'lng'       => -88.0078,
            ],
            [
                'city_name' => 'Carol Stream',
                'city_slug' => 'carol-stream',
                'nearby'    => ['Bloomingdale', 'Glendale Heights', 'Wheaton'],
                'lat'       => 41.9128,
                'lng'       => -88.1348,
            ],
            [
                'city_name' => 'Roselle',
                'city_slug' => 'roselle',
                'nearby'    => ['Bloomingdale', 'Hanover Park', 'Medinah'],
                'lat'       => 41.9842,
                'lng'       => -88.0798,
            ],
            [
                'city_name' => 'Hanover Park',
                'city_slug' => 'hanover-park',
                'nearby'    => ['Roselle', 'Bartlett', 'Streamwood'],
                'lat'       => 41.9992,
                'lng'       => -88.1451,
            ],
            [
                'city_name' => 'Bartlett',
                'city_slug' => 'bartlett',
                'nearby'    => ['Hanover Park', 'Streamwood', 'Elgin'],
                'lat'       => 41.9950,
                'lng'       => -88.1856,
            ],
            [
                'city_name' => 'Elk Grove Village',
                'city_slug' => 'elk-grove-village',
                'nearby'    => ['Bensenville', 'Wood Dale', 'Schaumburg'],
                'lat'       => 42.0040,
                'lng'       => -87.9706,
            ],
            [
                'city_name' => 'Bensenville',
                'city_slug' => 'bensenville',
                'nearby'    => ['Wood Dale', 'Elk Grove Village', 'Addison'],
                'lat'       => 41.9558,
                'lng'       => -87.9401,
            ],
            [
                'city_name' => 'Chicago',
                'city_slug' => 'chicago',
                'nearby'    => ['Evanston', 'Oak Park', 'Cicero'],
                'lat'       => 41.8781,
                'lng'       => -87.6298,
            ],
        ];
    }
}

new AdwrapLocationImport();
