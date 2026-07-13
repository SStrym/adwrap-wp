<?php
/**
 * One-off seeder: assign a body-type category to every vehicle in
 * {prefix}adwrap_vehicles using keyword heuristics on make+model.
 *
 * Run locally:  lando wp eval-file populate-vehicle-categories.php
 * Run on prod:  php -r 'require "web/wp/wp-load.php"; require "populate-vehicle-categories.php";'
 *
 * Idempotent — only rows whose computed category differs are updated. Existing
 * categories are OVERWRITTEN (this is a bootstrap; refine odd ones in
 * Wrap Calculator → Vehicles afterwards, or re-run after tweaking rules).
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'adwrap_vehicles';

/** Ordered rules: first regex match on "MAKE MODEL" wins. */
$rules = [
    'Food trucks'      => '/food\s*truck|food\s*trailer|concession/i',
    'Trailers'         => '/trailer/i',
    'Emergency'        => '/ambulance|police|fire\b|rescue|ems\b/i',
    'Shuttle/ Bus'     => '/\bbus\b|shuttle|coach\b/i',
    'RV/ Motorhome'    => '/\brv\b|motorhome|motor\s*home|winnebago|camper|class\s*[abc]\b/i',
    'Step vans'        => '/step\s*van|\bp\s?-?(30|500|700|1000|1200)\b|mt-?(45|55)|utilimaster|workhorse|w62/i',
    'Box trucks'       => '/box|cutaway|cube|straight\s*truck|\bnpr|\bnqr|\bnrr|\bfrr|\bfe\d|lcf\b|low\s*cab/i',
    'Passenger vans'   => '/passenger|\bwagon\b|crew\s*van/i',
    'Cargo vans'       => '/transit(?!\s*connect)|connect|sprinter|promaster|pro\s*master|express(?!\s*pickup)|savana|metris|\bnv\s?-?(200|1500|2500|3500)|econoline|\be-?(150|250|350)\b|city\s*express|caravan|caddy|vito/i',
    'Pickup'           => '/pick\s*-?up|\bf-?(150|250|350|450|550|650)\b|silverado|sierra|\bram\b|tundra|tacoma|titan\b|ranger|colorado|canyon|frontier|ridgeline|gladiator|super\s*duty/i',
];

$rows = $wpdb->get_results("SELECT id, make, model, category FROM {$table}", ARRAY_A);
if (!$rows) {
    echo "No vehicles found — import the catalog first.\n";
    return;
}

$counts  = [];
$updated = 0;

foreach ($rows as $row) {
    $haystack = $row['make'] . ' ' . $row['model'];
    $category = 'Cars/ Crossovers'; // fallback
    foreach ($rules as $cat => $regex) {
        if (preg_match($regex, $haystack)) {
            $category = $cat;
            break;
        }
    }
    $counts[$category] = ($counts[$category] ?? 0) + 1;

    if ($row['category'] !== $category) {
        $wpdb->update($table, ['category' => $category], ['id' => (int) $row['id']]);
        $updated++;
    }
}

echo "Categorized " . count($rows) . " vehicles ({$updated} updated):\n";
ksort($counts);
foreach ($counts as $cat => $n) {
    echo sprintf("  %-20s %d\n", $cat, $n);
}
