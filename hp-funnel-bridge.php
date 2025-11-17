<?php
/**
 * Plugin Name:       HP Funnel Bridge
 * Description:       Multi‑funnel bridge exposing REST endpoints for checkout, shipping rates, totals, and one‑click upsells. Reuses EAO (Stripe keys, ShipStation, YITH points) without modifying it.
 * Version:           0.2.12
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

define('HP_FB_PLUGIN_VERSION', '0.2.12');
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
	(new \HP_FB\Rest\StatusController())->register_routes();
});

// Register lightweight hosted confirmation page: /hp-funnel-confirm?cs=CLIENT_SECRET
add_action('init', function () {
	add_rewrite_rule('^hp-funnel-confirm/?$', 'index.php?hp_fb_confirm=1', 'top');
});
add_filter('query_vars', function ($vars) {
	$vars[] = 'hp_fb_confirm';
	return $vars;
});
add_action('template_redirect', function () {
	$flag = get_query_var('hp_fb_confirm');
	if (!$flag) { return; }
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
	$isTest = (strpos($pubVal, '_test_') !== false) ? true : ($stripe->mode === 'test');
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>HP Funnel Payment</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:24px;}#checkout{max-width:520px;margin:0 auto;}button{padding:10px 14px;border:1px solid #ccc;border-radius:6px;background:#111;color:#fff;cursor:pointer;}#messages{margin-top:12px;color:#c00}#amount{margin:6px 0 14px;color:#111;font-weight:600}.badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:12px;margin-left:8px}.test{background:#eef7ff;color:#0a66c2}.hint{font-size:13px;color:#333;background:#fafafa;border:1px solid #eee;border-radius:6px;padding:8px 10px;margin:8px 0}.hint code{background:#f2f2f2;padding:1px 4px;border-radius:3px}.copy{margin-left:8px;padding:3px 6px;border:1px solid #333;background:#333;color:#fff;border-radius:4px;cursor:pointer;font-size:12px}</style><script src="https://js.stripe.com/v3/"></script></head><body><div id="checkout"><h2>Complete Payment'.($isTest?'<span class="badge test" id="testbadge">Stripe Test Mode</span>':'').'</h2><div id="amount"></div>';
	if ($isTest) {
		echo '<div class="hint">Use Stripe test values: <br/>Card <code id="tcard">4242 4242 4242 4242</code><button class="copy" data-copy="#tcard">Copy</button> &nbsp; Exp <code id="texp">12/34</code><button class="copy" data-copy="#texp">Copy</button> &nbsp; CVC <code id="tcvc">123</code><button class="copy" data-copy="#tcvc">Copy</button></div>';
	}
	$pub_json = wp_json_encode($pubVal);
	$cs_json = wp_json_encode($cs);
	$here_json = wp_json_encode($here);
	echo '<div id="element"></div><div style="margin-top:12px;"><button id="pay" disabled>Pay</button></div><div id="messages"></div></div><script>(async function(){try{var pub='.$pub_json.';var cs='.$cs_json.';var ret='.$here_json.';var stripe=Stripe(pub);var elements=stripe.elements({clientSecret:cs});var paymentElement;try{paymentElement=elements.create("payment");paymentElement.mount("#element");}catch(pe){var m=document.getElementById("messages");if(m)m.textContent=(pe&&pe.message)?pe.message:"Failed to load payment fields";return;}var btn=document.getElementById("pay");var msg=document.getElementById("messages");var amt=document.getElementById("amount");function bindCopy(){var nodes=document.querySelectorAll(".copy");nodes.forEach(function(n){n.addEventListener("click",function(){var sel=n.getAttribute("data-copy");var el=document.querySelector(sel);if(!el) return;var txt=el.textContent||"";if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt);}else{var ta=document.createElement("textarea");ta.value=txt;document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(ta);}});});}bindCopy();try{var pi=await stripe.retrievePaymentIntent(cs);if(pi&&pi.paymentIntent){var cents=pi.paymentIntent.amount||0;var cur=(pi.paymentIntent.currency||"usd").toUpperCase();amt.textContent="Amount: $"+(cents/100).toFixed(2)+" "+cur;}}catch(e){}paymentElement.on("change",function(e){btn.disabled=!e.complete;msg.textContent="";});btn.addEventListener("click",async function(){btn.disabled=true;msg.textContent="Processing...";try{var submitRes=await elements.submit();if(submitRes&&submitRes.error){msg.textContent=submitRes.error.message||"Please check your details";btn.disabled=false;return;}var res=await stripe.confirmPayment({elements:elements,clientSecret:cs,confirmParams:{return_url:ret},redirect:"if_required"});if(res.error){msg.textContent=(res.error&&res.error.message)?res.error.message:"Payment failed";btn.disabled=false;}else{msg.textContent="Payment processed. You can close this page.";btn.disabled=true;}}catch(err){msg.textContent=(err&&err.message)?err.message:"Payment failed";btn.disabled=false;}});}catch(_err){var m=document.getElementById("messages");if(m)m.textContent="Failed to initialize payment";}})();</script></body></html>';
	exit;
});

// Register refund-capable gateway for Bridge orders
add_filter('woocommerce_payment_gateways', function ($gateways) {
	$gateways[] = \HP_FB\Gateway\StripeGateway::class;
	return $gateways;
});


