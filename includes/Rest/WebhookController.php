<?php
namespace HP_FB\Rest;

use HP_FB\Services\OrderDraftStore;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use HP_FB\Util\Resolver;

if (!defined('ABSPATH')) { exit; }

class WebhookController {
	public function register_routes(): void {
		// Accept POST for real Stripe deliveries, and also allow GET so tools
		// that probe the endpoint (e.g. Stripe Workbench “event destination”)
		// don’t fail on a 404 during creation. GET simply returns a 200/OK.
		register_rest_route('hp-funnel/v1', '/stripe/webhook', [
			'methods'  => ['POST', 'GET'],
			'callback' => [$this, 'receive'],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Wrapper that returns 200 for non-POST (health-check/ping), otherwise
	 * forwards to the actual webhook handler.
	 */
	public function receive(WP_REST_Request $request) {
		if (strtoupper($request->get_method() ?? '') !== 'POST') {
			$mode = 'unknown';
			try {
				$stripe = new \HP_FB\Stripe\Client(null);
				$mode = $stripe->mode ?? 'unknown';
			} catch (\Throwable $e) {}
			return new WP_REST_Response([
				'ok' => true,
				'message' => 'hp-funnel-bridge stripe webhook endpoint',
				'mode' => $mode,
			], 200);
		}
		return $this->handle($request);
	}

	public function handle(WP_REST_Request $request) {
		$payload = $request->get_body();

		// Optional signature verification (recommended in production)
		if (!$this->verifyStripeSignatureIfConfigured($payload)) {
			$this->debugLog('stripe webhook signature failed');
			return new WP_REST_Response(['ok' => false, 'reason' => 'sig_verify_failed'], 400);
		}

		$event = json_decode($payload, true);
		if (!is_array($event) || empty($event['type'])) {
			$this->debugLog('stripe webhook bad payload');
			return new WP_REST_Response(['ok' => false], 400);
		}
		$type = (string)$event['type'];
		$obj = isset($event['data']['object']) ? $event['data']['object'] : [];
		$this->debugLog('stripe webhook received', ['type' => $type, 'pi' => $obj['id'] ?? null, 'livemode' => $obj['livemode'] ?? null]);
		if ($type === 'payment_intent.succeeded') {
			return $this->onPaymentIntentSucceeded($obj);
		}
		if ($type === 'payment_intent.payment_failed') {
			$this->debugLog('payment_intent.payment_failed', ['pi' => $obj['id'] ?? null]);
			return new WP_REST_Response(['ok' => true, 'received' => $type]);
		}
		return new WP_REST_Response(['ok' => true, 'ignored' => $type]);
	}

	private function onPaymentIntentSucceeded(array $pi) {
		$draft_id = isset($pi['metadata']['order_draft_id']) ? (string)$pi['metadata']['order_draft_id'] : '';
		if ($draft_id === '') {
			$this->debugLog('pi.succeeded missing draft id', ['pi' => $pi['id'] ?? null]);
			return new WP_REST_Response(['ok' => false, 'reason' => 'no_draft'], 400);
		}
		$store = new OrderDraftStore();
		$draft = $store->get($draft_id);
		if (!$draft) {
			$this->debugLog('pi.succeeded draft not found', ['draft_id' => $draft_id]);
			return new WP_REST_Response(['ok' => false, 'reason' => 'draft_not_found'], 404);
		}
		$order = wc_create_order();
		// Link to existing user by email, if exists
		$email = isset($draft['customer']['email']) ? (string)$draft['customer']['email'] : '';
		$user = $email ? get_user_by('email', $email) : false;
		if ($user) {
			if (method_exists($order, 'set_customer_id')) {
				$order->set_customer_id((int)$user->ID);
			}
		}
		// Items
		$added_items = 0;
		foreach ((array)$draft['items'] as $it) {
			$qty = max(1, (int)($it['qty'] ?? 1));
			$product = Resolver::resolveProductFromItem((array)$it);
			if (!$product) { continue; }
			// Prefer Woo's helper to add products so line items are identical to native orders
			if (method_exists($order, 'add_product')) {
				$order->add_product($product, $qty);
			} else {
				$item = new \WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);
				$item->set_subtotal($product->get_price() * $qty);
				$item->set_total($product->get_price() * $qty);
				$order->add_item($item);
			}
			$added_items++;
		}
		// Address
		$this->applyAddress($order, 'billing', array_merge((array)$draft['shipping_address'], ['email' => $email]));
		$this->applyAddress($order, 'shipping', (array)$draft['shipping_address']);
		// Shipping
		if (!empty($draft['selected_rate']) && isset($draft['selected_rate']['amount'])) {
			$ship = new \WC_Order_Item_Shipping();
			$ship->set_method_title(isset($draft['selected_rate']['serviceName']) ? (string)$draft['selected_rate']['serviceName'] : 'Shipping');
			$ship->set_total((float)$draft['selected_rate']['amount']);
			$order->add_item($ship);
		}
		// Coupons
		if (!empty($draft['coupon_codes']) && is_array($draft['coupon_codes'])) {
			foreach ($draft['coupon_codes'] as $code) {
				$code = wc_format_coupon_code((string)$code);
				if ($code !== '') {
					$order->apply_coupon($code);
				}
			}
		}
		// Points redemption (requires user)
		$points_to_redeem = isset($draft['points_to_redeem']) ? (int)$draft['points_to_redeem'] : 0;
		// Ensure EAO YITH points helpers are available outside admin (Bridge context)
		if ($points_to_redeem > 0 && $user && !function_exists('eao_process_yith_points_redemption')) {
			$this->tryLoadEaoYithPoints();
		}
		if ($points_to_redeem > 0 && $user && function_exists('eao_process_yith_points_redemption')) {
			$order->save(); // ensure ID before processing
			$res = \eao_process_yith_points_redemption($order->get_id(), ['eao_points_to_redeem' => $points_to_redeem]);
			// ignore result soft-fail; totals recalculated below
		}
		$order->calculate_totals(false);

		// Stripe identifiers
		$pi_id = isset($pi['id']) ? (string)$pi['id'] : '';
		$charge_id = '';
		if (!empty($pi['latest_charge'])) { $charge_id = (string)$pi['latest_charge']; }
		if (!$charge_id && !empty($pi['charges']['data'][0]['id'])) { $charge_id = (string)$pi['charges']['data'][0]['id']; }
		if ($pi_id !== '') { $order->update_meta_data('_hp_fb_stripe_payment_intent_id', $pi_id); }
		if ($charge_id !== '') { $order->update_meta_data('_hp_fb_stripe_charge_id', $charge_id); }
		if (!empty($draft['stripe_customer'])) {
			$order->update_meta_data('_hp_fb_stripe_customer_id', (string)$draft['stripe_customer']);
		}
		// Mirror EAO Stripe meta so EAO refund UI behaves like native charges
		$livemode = !empty($pi['livemode']);
		$eao_mode = $livemode ? 'live' : 'test';
		if ($pi_id !== '') { $order->update_meta_data('_eao_stripe_payment_intent_id', $pi_id); }
		if ($charge_id !== '') { $order->update_meta_data('_eao_stripe_charge_id', $charge_id); }
		$order->update_meta_data('_eao_stripe_payment_mode', $eao_mode);
		// Store last charged amount/currency (cents) for proportional refunds
		$amount_cents = 0;
		if (isset($pi['amount_received']) && is_numeric($pi['amount_received'])) {
			$amount_cents = (int) $pi['amount_received'];
		} elseif (isset($pi['amount']) && is_numeric($pi['amount'])) {
			$amount_cents = (int) $pi['amount'];
		}
		if ($amount_cents > 0) {
			$order->update_meta_data('_eao_last_charged_amount_cents', $amount_cents);
		}
		$currency = isset($pi['currency']) ? strtoupper((string) $pi['currency']) : '';
		if ($currency !== '') {
			$order->update_meta_data('_eao_last_charged_currency', $currency);
		}
		$order->update_meta_data('_eao_payment_gateway', $livemode ? 'stripe_live' : 'stripe_test');

		// Set gateway and make mode explicit in title (Live/Test)
		$modeLabel = ($livemode) ? 'Live' : 'Test';
		$pmTitle = 'HP Funnel Bridge (Stripe - ' . $modeLabel . ')';
		if (method_exists($order, 'set_payment_method')) { $order->set_payment_method('hp_fb_stripe'); }
		if (method_exists($order, 'set_payment_method_title')) { $order->set_payment_method_title($pmTitle); }
		$order->update_meta_data('_payment_method', 'hp_fb_stripe');
		$order->update_meta_data('_payment_method_title', $pmTitle);

		// Funnel note and analytics
		$funnel_name = isset($draft['funnel_name']) ? (string)$draft['funnel_name'] : 'Funnel';
		$order->add_order_note('Funnel: ' . $funnel_name);
		if (!empty($draft['analytics'])) {
			foreach ((array)$draft['analytics'] as $k => $v) {
				$order->update_meta_data('_hp_fb_' . sanitize_key((string)$k), is_scalar($v) ? (string)$v : wp_json_encode($v));
			}
		}

		// Mark paid (processing)
		if (method_exists($order, 'payment_complete') && $charge_id !== '') {
			$order->set_transaction_id($charge_id);
			$order->payment_complete($charge_id);
		} else {
			$order->set_status('processing');
		}
		$order->save();
		$this->debugLog('order created from pi.succeeded', ['order_id' => $order->get_id(), 'items' => count($order->get_items('line_item')), 'shipping_items' => count($order->get_items('shipping')), 'pi' => $pi_id, 'charge' => $charge_id, 'mode' => $modeLabel]);

		// Update Stripe PI + Charge description to include Woo order number for backoffice clarity
		if ($pi_id !== '') {
			try {
				$stripe = new \HP_FB\Stripe\Client();
				$order_no = method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id();
				$desc = 'HolisticPeople - ' . $funnel_name . ' - Order #' . $order_no;
				$stripe->updatePaymentIntent($pi_id, ['description' => $desc]);
				if ($charge_id !== '') {
					$stripe->updateCharge($charge_id, ['description' => $desc]);
				}
			} catch (\Throwable $e) { /* ignore non-fatal */ }
		}

		// Remove draft
		$store->delete($draft_id);

		return new WP_REST_Response(['ok' => true, 'order_id' => $order->get_id()]);
	}

	/**
	 * Debug logger: writes to WooCommerce status logs (source: hp-funnel-bridge) only when WP_DEBUG or WP_DEBUG_LOG is enabled.
	 */
	private function debugLog(string $message, array $context = []): void {
		if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
			if (function_exists('wc_get_logger')) {
				try {
					$logger = \wc_get_logger();
					$logger->info($message . ' ' . wp_json_encode($context), ['source' => 'hp-funnel-bridge']);
					return;
				} catch (\Throwable $e) {}
			}
			@error_log('[hp-funnel-bridge] ' + $message . ' ' . json_encode($context));
		}
	}
	/**
	 * If webhook signing secrets are configured, verify Stripe-Signature header.
	 * Accepts either the test or live secret (so it works across modes).
	 * Returns true if verification passes or if no secret is configured.
	 */
	private function verifyStripeSignatureIfConfigured(string $payload): bool {
		$opts = get_option('hp_fb_settings', []);
		$test = isset($opts['webhook_secret_test']) ? trim((string)$opts['webhook_secret_test']) : '';
		$live = isset($opts['webhook_secret_live']) ? trim((string)$opts['webhook_secret_live']) : '';
		// If neither secret is set, do not enforce verification (keeps staging flexible)
		if ($test === '' && $live === '') {
			return true;
		}
		$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string)$_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
		if ($sigHeader === '') {
			return false;
		}
		$secrets = array_filter([$test, $live], function($v){ return $v !== ''; });
		foreach ($secrets as $secret) {
			if ($this->verifyStripeSig($payload, $sigHeader, $secret)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify Stripe v1 signature with tolerance (default 5 minutes).
	 */
	private function verifyStripeSig(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool {
		$timestamp = null;
		$signatures = [];
		foreach (explode(',', $sigHeader) as $kv) {
			$kv = trim($kv);
			if ($kv === '') { continue; }
			[$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
			if ($k === 't') { $timestamp = (int)$v; }
			if ($k === 'v1') { $signatures[] = $v; }
		}
		if (!$timestamp || empty($signatures)) {
			return false;
		}
		if (abs(time() - $timestamp) > $tolerance) {
			// Too old/new
			return false;
		}
		$signedPayload = $timestamp . '.' . $payload;
		$expected = hash_hmac('sha256', $signedPayload, $secret);
		foreach ($signatures as $sig) {
			if (hash_equals($expected, $sig)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Attempt to include EAO YITH Points helpers when functions are not available in non-admin context.
	 * We set DOING_AJAX to true temporarily so EAO's core initializes outside wp-admin.
	 */
	private function tryLoadEaoYithPoints(): void {
		$base = trailingslashit(WP_PLUGIN_DIR) . 'enhanced-admin-order-plugin/';
		$core = $base . 'eao-yith-points-core.php';
		$save = $base . 'eao-yith-points-save.php';
		$unsetAjax = false;
		if (!defined('DOING_AJAX')) {
			define('DOING_AJAX', true);
			$unsetAjax = true;
		}
		if (file_exists($core)) { require_once $core; }
		if (file_exists($save)) { require_once $save; }
		// no need to unset DOING_AJAX; harmless to keep defined for request lifetime
	}

	private function applyAddress($order, string $type, array $addr): void {
		$map = [
			'first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','email'
		];
		foreach ($map as $key) {
			$method = "set_{$type}_{$key}";
			if (method_exists($order, $method) && isset($addr[$key])) {
				$order->{$method}((string)$addr[$key]);
			}
		}
	}
}


