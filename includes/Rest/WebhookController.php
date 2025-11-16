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
		register_rest_route('hp-funnel/v1', '/stripe/webhook', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		$payload = $request->get_body();
		$event = json_decode($payload, true);
		if (!is_array($event) || empty($event['type'])) {
			return new WP_REST_Response(['ok' => false], 400);
		}
		$type = (string)$event['type'];
		$obj = isset($event['data']['object']) ? $event['data']['object'] : [];
		if ($type === 'payment_intent.succeeded') {
			return $this->onPaymentIntentSucceeded($obj);
		}
		if ($type === 'payment_intent.payment_failed') {
			return new WP_REST_Response(['ok' => true, 'received' => $type]);
		}
		return new WP_REST_Response(['ok' => true, 'ignored' => $type]);
	}

	private function onPaymentIntentSucceeded(array $pi) {
		$draft_id = isset($pi['metadata']['order_draft_id']) ? (string)$pi['metadata']['order_draft_id'] : '';
		if ($draft_id === '') {
			return new WP_REST_Response(['ok' => false, 'reason' => 'no_draft'], 400);
		}
		$store = new OrderDraftStore();
		$draft = $store->get($draft_id);
		if (!$draft) {
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
		foreach ((array)$draft['items'] as $it) {
			$qty = max(1, (int)($it['qty'] ?? 1));
			$product = Resolver::resolveProductFromItem((array)$it);
			if (!$product) { continue; }
			$item = new \WC_Order_Item_Product();
			$item->set_product($product);
			$item->set_quantity($qty);
			$item->set_subtotal($product->get_price() * $qty);
			$item->set_total($product->get_price() * $qty);
			$order->add_item($item);
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

		// Set gateway so refunds work in Woo admin
		if (method_exists($order, 'set_payment_method')) { $order->set_payment_method('hp_fb_stripe'); }
		if (method_exists($order, 'set_payment_method_title')) { $order->set_payment_method_title('HP Funnel Bridge (Stripe)'); }
		$order->update_meta_data('_payment_method', 'hp_fb_stripe');
		$order->update_meta_data('_payment_method_title', 'HP Funnel Bridge (Stripe)');

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

		// Update Stripe PI description to include Woo order number for backoffice clarity
		if ($pi_id !== '') {
			try {
				$stripe = new \HP_FB\Stripe\Client();
				$order_no = method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id();
				$desc = 'HolisticPeople - ' . $funnel_name . ' - Order #' . $order_no;
				$stripe->updatePaymentIntent($pi_id, ['description' => $desc]);
			} catch (\Throwable $e) { /* ignore non-fatal */ }
		}

		// Remove draft
		$store->delete($draft_id);

		return new WP_REST_Response(['ok' => true, 'order_id' => $order->get_id()]);
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


