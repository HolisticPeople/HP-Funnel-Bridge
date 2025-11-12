<?php
/**
 * Plugin Name:       HP Funnel Bridge
 * Description:       Multi‑funnel bridge exposing REST endpoints for checkout, shipping rates, totals, and one‑click upsells. Reuses EAO (Stripe keys, ShipStation, YITH points) without modifying it.
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Holistic People
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hp-funnel-bridge
 */

if (!defined('ABSPATH')) {
	exit;
}

define('HP_FB_PLUGIN_VERSION', '0.1.2');
define('HP_FB_PLUGIN_FILE', __FILE__);
define('HP_FB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HP_FB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Simple autoloader for our classes
spl_autoload_register(function ($class) {
	$prefix = 'HP_FB\\';
	$base_dir = HP_FB_PLUGIN_DIR . 'includes/';
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}
	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
	if (file_exists($file)) {
		require $file;
	}
});

// Activation: nothing heavy, but reserve for future rewrites if needed
register_activation_hook(__FILE__, function () {
	flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
	flush_rewrite_rules();
});

// Load admin settings page
add_action('admin_menu', function () {
	if (!current_user_can('manage_options')) {
		return;
	}
	\HP_FB\Admin\SettingsPage::registerMenu();
});

// CORS for our REST routes only
add_action('rest_api_init', function () {
	add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
		$route = $request->get_route();
		if (strpos($route, '/hp-funnel/v1/') !== false) {
			$opts = get_option('hp_fb_settings', array());
			$env = isset($opts['env']) && $opts['env'] === 'production' ? 'production' : 'staging';
			$origins = isset($opts['allowed_origins']) ? (array)$opts['allowed_origins'] : array();
			// Add per-funnel origins (staging or production) from registry (new structure)
			if (!empty($opts['funnels']) && is_array($opts['funnels'])) {
				foreach ($opts['funnels'] as $f) {
					if (!is_array($f)) { continue; }
					if ($env === 'staging' && !empty($f['origin_staging'])) {
						$origins[] = (string)$f['origin_staging'];
					}
					if ($env === 'production' && !empty($f['origin_production'])) {
						$origins[] = (string)$f['origin_production'];
					}
				}
			}
			$origins = array_values(array_unique(array_filter(array_map('trim', $origins))));
			$origin_hdr = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
			if (!empty($origin_hdr) && (empty($origins) || in_array($origin_hdr, $origins, true))) {
				header('Access-Control-Allow-Origin: ' . $origin_hdr);
				header('Vary: Origin', false);
			}
			header('Access-Control-Allow-Methods: POST, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Idempotency-Key');
		}
		return $served;
	}, 10, 4);
});

// Add Settings link on the Plugins list row
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
	$url = admin_url('options-general.php?page=hp-funnel-bridge');
	$links[] = '<a href="' . esc_url($url) . '">Settings</a>';
	return $links;
});

// Register REST routes
add_action('rest_api_init', function () {
	(new \HP_FB\Rest\CustomerController())->register_routes();
	(new \HP_FB\Rest\ShipStationController())->register_routes();
	(new \HP_FB\Rest\TotalsController())->register_routes();
	(new \HP_FB\Rest\CheckoutController())->register_routes();
	(new \HP_FB\Rest\UpsellController())->register_routes();
	(new \HP_FB\Rest\WebhookController())->register_routes();
});


