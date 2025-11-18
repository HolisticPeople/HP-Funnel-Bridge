<?php
namespace HP_FB\Rest;

use HP_FB\Stripe\Client as StripeClient;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;

if (!defined('ABSPATH')) { exit; }

class UpsellController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/upsell/charge', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		$parent_order_id = (int) ($request->get_param('parent_order_id') ?? 0);
		$items = (array) $request->get_param('items');
		$funnel_name = (string) ($request->get_param('funnel_name') ?? 'Funnel');
		// Allow either explicit items OR an amount_override; at least one is required
		$override = $request->get_param('amount_override');
		$has_override = is_numeric($override) && (float)$override > 0;
		if ($parent_order_id <= 0 || (empty($items) && !$has_override)) {
			return new WP_Error('bad_request', 'parent_order_id and items or amount_override are required', ['status' => 400]);
		}
		$parent = wc_get_order($parent_order_id);
		if (!$parent) {
			return new WP_Error('not_found', 'Parent order not found', ['status' => 404]);
		}
		$cus = (string) $parent->get_meta('_hp_fb_stripe_customer_id', true);
		if ($cus === '') {
			return new WP_Error('missing_customer', 'Parent order missing Stripe customer id', ['status' => 400]);
		}
		// Try to reuse the same payment method as the parent charge
		$pm_id = '';
		try {
			$stripe = new StripeClient();
			$parent_pi = (string) $parent->get_meta('_hp_fb_stripe_payment_intent_id', true);
			if ($parent_pi !== '') {
				$pi_data = $stripe->retrievePaymentIntent($parent_pi);
				if (is_array($pi_data) && !empty($pi_data['payment_method'])) {
					$pm_id = (string) $pi_data['payment_method'];
				}
			}
			if ($pm_id === '') {
				$cust = $stripe->retrieveCustomer($cus);
				if (is_array($cust) && !empty($cust['invoice_settings']['default_payment_method'])) {
					$pm_id = (string) $cust['invoice_settings']['default_payment_method'];
				}
			}
		} catch (\Throwable $e) {}
		// Amount
		$amount = 0.0;
		$added_items = 0;
		foreach ($items as $it) {
			$product_id = isset($it['product_id']) ? (int)$it['product_id'] : 0;
			$variation_id = isset($it['variation_id']) ? (int)$it['variation_id'] : 0;
			$qty = max(1, (int)($it['qty'] ?? 1));
			if ($product_id <= 0) { continue; }
			$product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
			if (!$product) { continue; }
			$amount += (float)$product->get_price() * $qty;
			$added_items++;
		}
		if ($has_override) {
			$amount = (float)$override;
		}
		$amount_cents = (int) round($amount * 100);
		if ($amount_cents <= 0) {
			return new WP_Error('bad_amount', 'Amount must be greater than zero', ['status' => 400]);
		}

		// (re)use client
		if (!$stripe->isConfigured()) {
			return new WP_Error('stripe_not_configured', 'Stripe keys are missing', ['status' => 500]);
		}
		// Off-session PI using default PM on customer
		$params = [
			'amount' => $amount_cents,
			'currency' => strtolower(get_woocommerce_currency('USD') ?: 'usd'),
			'customer' => $cus,
			'off_session' => 'true',
			'confirm' => 'true',
			'payment_method_types[]' => 'card',
			'metadata[parent_order_id]' => (string)$parent_order_id,
			'metadata[funnel_name]' => $funnel_name,
		];
		if ($pm_id !== '') {
			$params['payment_method'] = $pm_id;
		}
		$pi = $stripe->createPaymentIntent($params);
		if (!$pi || (string)($pi['status'] ?? '') !== 'succeeded') {
			return new WP_Error('stripe_pi', 'Off-session charge failed', ['status' => 402, 'debug' => $pi]);
		}
		// Create child order
		$child = wc_create_order();
		if ($parent->get_customer_id()) {
			$child->set_customer_id($parent->get_customer_id());
		}
		foreach ($items as $it) {
			$product_id = isset($it['product_id']) ? (int)$it['product_id'] : 0;
			$variation_id = isset($it['variation_id']) ? (int)$it['variation_id'] : 0;
			$qty = max(1, (int)($it['qty'] ?? 1));
			if ($product_id <= 0) { continue; }
			$product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
			if (!$product) { continue; }
			$item = new \WC_Order_Item_Product();
			$item->set_product($product);
			$item->set_quantity($qty);
			$item->set_subtotal($product->get_price() * $qty);
			$item->set_total($product->get_price() * $qty);
			$child->add_item($item);
		}
		// If no product items were provided, record the upsell as a fee line so totals match.
		if ($added_items === 0) {
			$label = (string) ($request->get_param('fee_label') ?? 'Off The Fast Kit');
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name($label);
			$fee->set_total($amount);
			$child->add_item($fee);
		}
		// Copy addresses from parent
		$this->copyAddress($parent, $child, 'billing');
		$this->copyAddress($parent, $child, 'shipping');
		$child->calculate_totals(false);
		// Meta and notes
		$child->update_meta_data('_hp_fb_parent_order_id', $parent_order_id);
		if (!empty($pi['id'])) { $child->update_meta_data('_hp_fb_stripe_payment_intent_id', (string)$pi['id']); }
		if (!empty($pi['latest_charge'])) { $child->update_meta_data('_hp_fb_stripe_charge_id', (string)$pi['latest_charge']); }
		$child->add_order_note('Funnel: ' . $funnel_name . ' (upsell)');
		if (method_exists($child, 'payment_complete') && !empty($pi['latest_charge'])) {
			$child->set_transaction_id((string)$pi['latest_charge']);
			$child->payment_complete((string)$pi['latest_charge']);
		} else {
			$child->set_status('processing');
		}
		$child->save();
		return new WP_REST_Response(['ok' => true, 'order_id' => $child->get_id()]);
	}

	private function copyAddress($from, $to, string $type): void {
		$map = [
			'first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','email'
		];
		foreach ($map as $key) {
			$get = "get_{$type}_{$key}";
			$set = "set_{$type}_{$key}";
			if (method_exists($from, $get) && method_exists($to, $set)) {
				$to->{$set}($from->{$get}());
			}
		}
	}
}


