<?php
/**
 * Plugin Name:       HP Funnel Bridge
 * Description:       Multi‑funnel bridge exposing REST endpoints for checkout, shipping rates, totals, and one‑click upsells. Reuses EAO (Stripe keys, ShipStation, YITH points) without modifying it.
 * Version:           0.2.35
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

define('HP_FB_PLUGIN_VERSION', '0.2.35');
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
// Admin minor UI tweaks
if (is_admin()) {
	// Lazy-load Tweaks class if present
	add_action('admin_init', function () {
		if (class_exists('\HP_FB\Admin\Tweaks')) {
			(new \HP_FB\Admin\Tweaks())->register();
		}
	});
}

// Ensure EAO refund compatibility is registered early for admin-ajax requests too
add_action('plugins_loaded', function () {
	if (is_admin() && class_exists('\HP_FB\Admin\EAORefundCompat')) {
		(new \HP_FB\Admin\EAORefundCompat())->register();
	}
}, 1);

// Guard admin-ajax JSON for EAO refunds against noisy output from other plugins (open_basedir warnings etc.)
// Clean noisy output (warnings) from other plugins for EAO refund AJAX endpoints so JSON stays valid
if (!function_exists('hp_fb_json_sanitize_buffer')) {
	function hp_fb_json_sanitize_buffer($buffer) {
		// Keep only the first JSON object/array in the buffer
		$posObj = strpos($buffer, '{');
		$posArr = strpos($buffer, '[');
		$start = false;
		if ($posObj !== false && $posArr !== false) { $start = min($posObj, $posArr); }
		elseif ($posObj !== false) { $start = $posObj; }
		elseif ($posArr !== false) { $start = $posArr; }
		if ($start === false) { return $buffer; }
		$trimmed = substr($buffer, $start);
		$endObj = strrpos($trimmed, '}');
		$endArr = strrpos($trimmed, ']');
		$end = false;
		if ($endObj !== false && $endArr !== false) { $end = max($endObj, $endArr); }
		elseif ($endObj !== false) { $end = $endObj; }
		elseif ($endArr !== false) { $end = $endArr; }
		if ($end !== false) {
			$trimmed = substr($trimmed, 0, $end + 1);
		}
		return $trimmed;
	}
}
add_action('init', function () {
	if (!defined('DOING_AJAX') || !DOING_AJAX) { return; }
	$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
	if ($action === 'eao_payment_get_refund_data' || $action === 'eao_payment_process_refund') {
		// Reduce chance of warnings being printed
		if (function_exists('ini_set')) { @ini_set('display_errors', '0'); }
		// Start buffer with sanitizer
		if (function_exists('ob_start')) { @ob_start('hp_fb_json_sanitize_buffer'); }
	}
}, 0);

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
		(new \HP_FB\Rest\OrdersController())->register_routes();
	(new \HP_FB\Rest\UpsellController())->register_routes();
	(new \HP_FB\Rest\WebhookController())->register_routes();
	(new \HP_FB\Rest\StatusController())->register_routes();
	(new \HP_FB\Rest\CatalogController())->register_routes();
});

// Register lightweight hosted confirmation page: /hp-funnel-confirm?cs=CLIENT_SECRET
add_action('init', function () {
	add_rewrite_rule('^hp-funnel-confirm/?$', 'index.php?hp_fb_confirm=1', 'top');
});
add_filter('query_vars', function ($vars) {
	$vars[] = 'hp_fb_confirm';
	return $vars;
});
// Start an early output buffer for the hosted confirm page to swallow noisy warnings from other plugins
add_action('template_redirect', function () {
	if (!isset($_GET['hp_fb_confirm'])) { return; }
	if (function_exists('ob_start')) { @ob_start(); }
}, 0);

add_action('template_redirect', function () {
	$flag = get_query_var('hp_fb_confirm');
	if (!$flag) { return; }
	// Avoid noisy PHP warnings polluting the hosted page
	if (function_exists('ini_set')) { @ini_set('display_errors', '0'); }
	// Clear any buffered output collected before we render the HTML
	if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
	$cs = isset($_GET['cs']) ? (string)$_GET['cs'] : '';
	if ($cs === '') {
		status_header(400);
		echo '<!doctype html><meta charset="utf-8"><title>Missing client_secret</title><p>Missing client_secret (?cs=...)</p>';
		exit;
	}
	$stripe = new \HP_FB\Stripe\Client();
	if (!$stripe->isConfigured()) {
		status_header(500);
		echo '<!doctype html><meta charset="utf-8"><title>Stripe not configured</title><p>Stripe keys are missing.</p>';
		exit;
	}
	// Allow frontend to hint the publishable key via ?pk=... so we pick the correct Stripe environment
	$pk_hint = isset($_GET['pk']) ? (string) $_GET['pk'] : '';
	$pubVal = $pk_hint !== '' ? $pk_hint : $stripe->publishable;
	$pub = esc_js($pubVal);
	$cs_js = esc_js($cs);
	// Build a valid absolute return URL for Stripe (must be https)
	$here = esc_url(home_url('/'));
	// Determine badge purely from publishable key; do not rely on global env
	$isTest = (strpos($pubVal, '_test_') !== false);
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>HP Funnel Payment</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:24px;}#checkout{max-width:520px;margin:0 auto;}button{padding:10px 14px;border:1px solid #ccc;border-radius:6px;background:#111;color:#fff;cursor:pointer;}#messages{margin-top:12px;color:#c00}#amount{margin:6px 0 14px;color:#111;font-weight:600}.badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:12px;margin-left:8px}.test{background:#eef7ff;color:#0a66c2}.hint{font-size:13px;color:#333;background:#fafafa;border:1px solid #eee;border-radius:6px;padding:8px 10px;margin:8px 0}.hint code{background:#f2f2f2;padding:1px 4px;border-radius:3px}.copy{margin-left:8px;padding:3px 6px;border:1px solid #333;background:#333;color:#fff;border-radius:4px;cursor:pointer;font-size:12px}</style><script src="https://js.stripe.com/v3/"></script></head><body><div id="checkout"><h2>Complete Payment'.($isTest?'<span class="badge test" id="testbadge">Stripe Test Mode</span>':'').'</h2><div id="amount"></div>';
	if ($isTest) {
		echo '<div class="hint">Use Stripe test values: <br/>Card <code id="tcard">4242 4242 4242 4242</code><button class="copy" data-copy="#tcard">Copy</button> &nbsp; Exp <code id="texp">12/34</code><button class="copy" data-copy="#texp">Copy</button> &nbsp; CVC <code id="tcvc">123</code><button class="copy" data-copy="#tcvc">Copy</button></div>';
	}
	// Server-side robust succ extraction and normalization
	$succ_in = isset($_GET['succ']) ? (string) $_GET['succ'] : '';
	$succ_norm = $succ_in;
	// Decode up to twice (handles encodeURIComponent + server re-encoding)
	for ($i = 0; $i < 2; $i++) {
		$dec = rawurldecode($succ_norm);
		if ($dec === $succ_norm) { break; }
		$succ_norm = $dec;
	}
	// Validate scheme
	if ($succ_norm !== '') {
		$ok = false;
		try {
			$u = @parse_url($succ_norm);
			if (is_array($u) && isset($u['scheme']) && ($u['scheme'] === 'https' || $u['scheme'] === 'http')) {
				$ok = true;
			}
		} catch (\Throwable $e) {}
		if (!$ok) { $succ_norm = ''; }
	}
	$pub_e = esc_attr($pubVal);
	$cs_e = esc_attr($cs);
	$here_e = esc_attr($here);
	$succ_e = esc_attr($succ_norm);
	echo '<div id="element"></div><div style="margin-top:12px;"><button id="pay" disabled>Pay</button></div><div id="messages"></div></div>';
	// Pass config via data attributes and load external script to avoid inline parsing issues
	echo '<div id="hp-fb-config" data-pub="' . $pub_e . '" data-cs="' . $cs_e . '" data-ret="' . $here_e . '" data-succ="' . $succ_e . '"></div>';
	echo '<script src="' . esc_url(HP_FB_PLUGIN_URL . 'assets/confirm.js?v=' . rawurlencode(HP_FB_PLUGIN_VERSION)) . '"></script></body></html>';
	exit;
});

// Register refund-capable gateway for Bridge orders
add_filter('woocommerce_payment_gateways', function ($gateways) {
	$gateways[] = \HP_FB\Gateway\StripeGateway::class;
	return $gateways;
});


