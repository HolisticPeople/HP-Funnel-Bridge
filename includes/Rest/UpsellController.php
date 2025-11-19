<?php
namespace HP_FB\Rest;

use HP_FB\Stripe\Client as StripeClient;
use HP_FB\Util\Resolver;
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
		$upsell_percent = 15.0;
		foreach ($items as $it) {
			$product_id = isset($it['product_id']) ? (int)$it['product_id'] : 0;
			$variation_id = isset($it['variation_id']) ? (int)$it['variation_id'] : 0;
			$qty = max(1, (int)($it['qty'] ?? 1));
			if ($product_id <= 0) { continue; }
			$product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
			if (!$product) { continue; }
			$unit = (float)$product->get_price();
			$unit_after = round($unit * (1 - ($upsell_percent / 100)), 2);
			$amount += $unit_after * $qty;
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
		// Off-session PI using saved payment method
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

		// Attach the upsell to the original (parent) order: add products or a fee and recalc totals.
		$upsellSubtotal = 0.0;
		$upsell_charge_id = '';
		if (!empty($pi['latest_charge'])) { $upsell_charge_id = (string) $pi['latest_charge']; }
		if (!$upsell_charge_id && !empty($pi['charges']['data'][0]['id'])) { $upsell_charge_id = (string) $pi['charges']['data'][0]['id']; }
		foreach ($items as $it) {
			$qty = max(1, (int)($it['qty'] ?? 1));
			$product = Resolver::resolveProductFromItem($it);
			if (!$product) { continue; }
			$unit = (float)$product->get_price();
			$unit_after = round($unit * (1 - ($upsell_percent / 100)), 2);
			$upsellSubtotal += $unit_after * $qty;
			if (function_exists('wc_add_product_to_order')) {
				$item = wc_add_product_to_order($parent->get_id(), $product, $qty);
				if ($item && is_object($item)) {
					if (method_exists($item, 'set_total')) { $item->set_total($unit_after * $qty); }
					if (method_exists($item, 'set_subtotal')) { $item->set_subtotal($unit_after * $qty); }
					if (method_exists($item, 'update_meta_data')) {
						$item->update_meta_data('_eao_exclude_global_discount', 1);
						$item->update_meta_data('_eao_item_discount_percent', $upsell_percent);
						if ($upsell_charge_id !== '') { $item->update_meta_data('_hp_fb_charge_id', $upsell_charge_id); }
					}
					if (method_exists($item, 'save')) { $item->save(); }
				}
			} else {
				$item = new \WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);
				$item->set_subtotal($unit_after * $qty);
				$item->set_total($unit_after * $qty);
				$item->update_meta_data('_eao_exclude_global_discount', 1);
				$item->update_meta_data('_eao_item_discount_percent', $upsell_percent);
				if ($upsell_charge_id !== '') { $item->update_meta_data('_hp_fb_charge_id', $upsell_charge_id); }
				$parent->add_item($item);
			}
		}
		if ($added_items === 0) {
			$label = (string) ($request->get_param('fee_label') ?? 'Off The Fast Kit');
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name($label);
			$fee->set_total($amount);
			$parent->add_item($fee);
		}
		$parent->calculate_totals(false);

		// Meta and notes on parent
		if (!empty($pi['id'])) { $parent->update_meta_data('_hp_fb_upsell_payment_intent_id', (string)$pi['id']); }
		if (!empty($pi['latest_charge'])) { $parent->update_meta_data('_hp_fb_upsell_charge_id', (string)$pi['latest_charge']); }
		$parent->add_order_note('Funnel: ' . $funnel_name . ' (upsell)');
		$parent->save();

		// Update Stripe PI/Charge descriptions to include order number and "Upsell"
		try {
			$order_no = method_exists($parent, 'get_order_number') ? (string) $parent->get_order_number() : (string) $parent->get_id();
			$desc = 'HolisticPeople - ' . $funnel_name . ' - Order #' . $order_no . ' - Upsell';
			if (!empty($pi['id'])) { $stripe->updatePaymentIntent((string)$pi['id'], ['description' => $desc]); }
			if (!empty($pi['latest_charge'])) { $stripe->updateCharge((string)$pi['latest_charge'], ['description' => $desc]); }
		} catch (\Throwable $e) {}

		return new WP_REST_Response(['ok' => true, 'order_id' => $parent->get_id()]);
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


