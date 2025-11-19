<?php
namespace HP_FB\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Compatibility shim for EAO refund UI.
 * Intercepts the refund-data AJAX for Bridge orders and
 * returns a products/shipping breakdown identical to EAO's,
 * so the admin sees normal per-line rows in the Refunds panel.
 */
class EAORefundCompat {
	public function register(): void {
		if (!is_admin()) { return; }
		// Run before EAO's own handler; we only handle Bridge orders.
		add_action('wp_ajax_eao_payment_get_refund_data', [$this, 'maybeHandleRefundData'], 1);
		add_action('wp_ajax_eao_payment_process_refund', [$this, 'maybeProcessRefund'], 1);
	}

	/**
	 * Handle refund processing for Bridge orders with multiple Stripe charges.
	 * We split the requested refund across charges using per-line meta _hp_fb_charge_id,
	 * perform multiple Stripe refunds, then create a single wc_refund on the order.
	 */
	public function maybeProcessRefund(): void {
		$logger = (function_exists('wc_get_logger') && ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG))) ? \wc_get_logger() : null;
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		if (!$order_id) { return; }
		$order = wc_get_order($order_id);
		if (!$order) { return; }
		$is_bridge = (
			(string) $order->get_meta('_hp_fb_stripe_payment_intent_id', true) !== '' ||
			(string) $order->get_meta('_hp_fb_stripe_charge_id', true) !== '' ||
			(string) $order->get_payment_method() === 'hp_fb_stripe' ||
			(string) $order->get_meta('_hp_fb_upsell_charge_id', true) !== ''
		);
		if (!$is_bridge) { return; } // let EAO handle

		// Parse lines payload from UI
		$lines_json = isset($_POST['lines']) ? wp_unslash($_POST['lines']) : '[]';
		$lines = json_decode($lines_json, true);
		if (!is_array($lines)) { $lines = array(); }
		$user_reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

		// Build per-charge allocations using _hp_fb_charge_id on items; default shipping to checkout charge
		$checkout_charge = (string) $order->get_meta('_hp_fb_stripe_charge_id', true);
		$per_charge = array(); // charge_id => amount float
		$amount_total = 0.0;
		$points_total = 0;
		$points_map = array();
		foreach ($lines as $row) {
			$item_id = isset($row['item_id']) ? absint($row['item_id']) : 0;
			$money = isset($row['money']) ? floatval($row['money']) : 0.0;
			$points = isset($row['points']) ? intval($row['points']) : 0;
			if ($points > 0) { $points_total += $points; $points_map[$item_id] = $points; }
			if ($item_id <= 0 || $money <= 0) { continue; }
			$charge_for_item = $checkout_charge;
			$it = $order->get_item($item_id);
			if ($it) {
				$meta_ch = (string) $it->get_meta('_hp_fb_charge_id', true);
				if ($meta_ch !== '') { $charge_for_item = $meta_ch; }
			}
			if ($charge_for_item === '') { $charge_for_item = $checkout_charge; }
			if (!isset($per_charge[$charge_for_item])) { $per_charge[$charge_for_item] = 0.0; }
			$per_charge[$charge_for_item] += $money;
			$amount_total += $money;
		}
		if ($amount_total <= 0 && $points_total <= 0) { return; }

		// Stripe credentials from EAO settings
		$stripe_mode = (string) $order->get_meta('_eao_stripe_payment_mode');
		if ($stripe_mode !== 'test') { $stripe_mode = 'live'; }
		$opts = get_option('eao_stripe_settings', array());
		$secret = ($stripe_mode === 'live') ? ($opts['live_secret'] ?? '') : ($opts['test_secret'] ?? '');
		if ($amount_total > 0 && empty($secret)) {
			wp_send_json_error(array('message' => 'Stripe API key missing for ' . (($stripe_mode === 'live') ? 'Live' : 'Test') . ' mode.'));
		}

		// Issue Stripe refunds per charge
		$refund_ids = array();
		if ($amount_total > 0) {
			$headers = array('Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/x-www-form-urlencoded');
			foreach ($per_charge as $charge_id => $amt) {
				if ($amt <= 0) { continue; }
				$rf = wp_remote_post('https://api.stripe.com/v1/refunds', array(
					'headers' => $headers,
					'body' => array(
						'charge' => $charge_id,
						'amount' => (int) round($amt * 100),
						'reason' => 'requested_by_customer',
						'metadata[order_id]' => $order_id
					),
					'timeout' => 25
				));
				if (is_wp_error($rf)) {
					wp_send_json_error(array('message' => 'Stripe refund error: ' . $rf->get_error_message()));
				}
				$rf_body = json_decode(wp_remote_retrieve_body($rf), true);
				if (empty($rf_body['id'])) {
					wp_send_json_error(array('message' => 'Stripe refund failed', 'stripe' => $rf_body));
				}
				$refund_ids[] = (string) $rf_body['id'];
			}
		}

		// Create a single WooCommerce refund to record the operation (no gateway call)
		$line_items = array();
		foreach ($lines as $row) {
			$item_id = isset($row['item_id']) ? absint($row['item_id']) : 0;
			$money = isset($row['money']) ? floatval($row['money']) : 0;
			if ($item_id && $money > 0) {
				$line_items[$item_id] = array('qty' => 0, 'refund_total' => wc_format_decimal($money, 2));
			}
		}
		$reason = 'Refund via EAO (Bridge multi-charge)';
		if ($points_total > 0) { $reason .= ' | Points to refund: ' . (int) $points_total; }
		$user_reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
		if (!empty($user_reason)) { $reason .= ' | Reason: ' . $user_reason; }

		$refund = wc_create_refund(array(
			'amount' => wc_format_decimal($amount_total, 2),
			'reason' => $reason,
			'order_id' => $order_id,
			'line_items' => $line_items,
			'refund_payment' => false,
			'restock_items' => false
		));
		if (is_wp_error($refund)) {
			wp_send_json_error(array('message' => $refund->get_error_message()));
		}
		if ($amount_total > 0) {
			update_post_meta($refund->get_id(), '_hp_fb_stripe_refunds', wp_json_encode(array_values($refund_ids)));
			update_post_meta($refund->get_id(), '_eao_refund_reference', implode(',', $refund_ids));
			update_post_meta($refund->get_id(), '_eao_refunded_via_gateway', 'Stripe (EAO ' . (($stripe_mode === 'live') ? 'Live' : 'Test') . ')');
		}

		// Handle points restore
		if ($points_total > 0) {
			if (function_exists('ywpar_increase_points')) {
				ywpar_increase_points($order->get_customer_id(), $points_total, sprintf(__('Redeemed points returned for Order #%d', 'enhanced-admin-order'), $order_id), $order_id);
			} elseif (function_exists('ywpar_get_customer')) {
				$cust = ywpar_get_customer($order->get_customer_id());
				if ($cust && method_exists($cust, 'update_points')) {
					$cust->update_points($points_total, 'order_points_return', array('order_id' => $order_id, 'description' => 'Redeemed points returned'));
				}
			}
			update_post_meta($refund->get_id(), '_eao_points_refunded', (int) $points_total);
			if (!empty($points_map)) { update_post_meta($refund->get_id(), '_eao_points_refunded_map', wp_json_encode($points_map)); }
		}

		$note = 'EAO Refund: Refund of $' . wc_format_decimal($amount_total, 2) . ' processed through Stripe (Bridge multi-charge).';
		if (!empty($refund_ids)) { $note .= ' Stripe refunds: ' . implode(', ', $refund_ids) . '.'; }
		$order->add_order_note($note, false, false);

		// Clean buffer and return JSON to UI
		if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
		wp_send_json_success(array('refund_id' => $refund->get_id(), 'amount' => wc_format_decimal($amount_total, 2), 'points' => (int) $points_total));
	}

	public function maybeHandleRefundData(): void {
		$logger = (function_exists('wc_get_logger') && ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG))) ? \wc_get_logger() : null;
		// Basic guards and order resolution
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		if (!$order_id) {
			if ($logger) { $logger->warning('EAORefundCompat: missing order_id', ['source' => 'hp-funnel-bridge']); }
			return;
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			if ($logger) { $logger->warning('EAORefundCompat: order not found', ['source' => 'hp-funnel-bridge', 'order_id' => $order_id]); }
			return;
		}

		// Only take over for Bridge-created Stripe orders
		$is_bridge = (
			(string) $order->get_meta('_hp_fb_stripe_payment_intent_id', true) !== '' ||
			(string) $order->get_meta('_hp_fb_stripe_charge_id', true) !== '' ||
			(string) $order->get_payment_method() === 'hp_fb_stripe'
		);
		if (!$is_bridge) {
			if ($logger) { $logger->info('EAORefundCompat: non-bridge order, letting EAO handle', ['source' => 'hp-funnel-bridge', 'order_id' => $order_id]); }
			return;
		}
		if ($logger) { $logger->info('EAORefundCompat: handling refund data', ['source' => 'hp-funnel-bridge', 'order_id' => $order_id]); }

		$items_resp = [];

		$order_items = $order->get_items('line_item');
		$products_total = 0.0;
		$per_item_base = [];
		foreach ($order_items as $iid => $it) {
			$base = (float) $it->get_total() + (float) $it->get_total_tax();
			if ($base <= 0.0001) { $base = (float) $it->get_subtotal() + (float) $it->get_subtotal_tax(); }
			$per_item_base[$iid] = $base;
			$products_total += $base;
		}

		$charged_cents_meta = (int) $order->get_meta('_eao_last_charged_amount_cents');
		$charged_cents = $charged_cents_meta > 0 ? $charged_cents_meta : (int) round(((float) $order->get_total()) * 100);
		$charged_total = $charged_cents / 100.0;
		$shipping_paid_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();

		$line_share = [];
		$line_remaining_share = [];
		$sum_remaining = 0.0;
		$products_refunded_total = 0.0;
		foreach ($order_items as $item_id => $item) {
			$wc_base = isset($per_item_base[$item_id]) ? (float) $per_item_base[$item_id] : 0.0;
			$product_charged_total = max(0.0, $charged_total - $shipping_paid_total);
			$share = ($products_total > 0.0) ? ($product_charged_total * ($wc_base / $products_total)) : 0.0;
			$refunded_item = method_exists($order, 'get_total_refunded_for_item') ? (float) $order->get_total_refunded_for_item($item_id) : 0.0;
			$refunded_tax  = method_exists($order, 'get_total_tax_refunded_for_item') ? (float) $order->get_total_tax_refunded_for_item($item_id) : 0.0;
			$refunded_line = $refunded_item + $refunded_tax;
			$remaining = max(0.0, $share - $refunded_line);
			$products_refunded_total += $refunded_line;
			$line_share[$item_id] = array('share' => $share, 'refunded' => $refunded_line);
			$line_remaining_share[$item_id] = $remaining;
			$sum_remaining += $remaining;
		}

		// Scale remaining to never exceed product portion of remaining charge
		$scaled_remaining_cents = [];
		$product_charged_total = max(0.0, $charged_total - $shipping_paid_total);
		$product_remaining_total = max(0.0, $product_charged_total - $products_refunded_total);
		$target_cents = (int) round($product_remaining_total * 100);
		if ($sum_remaining <= 0.0001) {
			foreach ($order_items as $item_id => $item) { $scaled_remaining_cents[$item_id] = 0; }
		} else {
			$factor = min(1.0, ($product_remaining_total > 0 ? ($product_remaining_total / $sum_remaining) : 0));
			$acc = 0; $i = 0; $last = count($order_items) - 1;
			foreach ($order_items as $item_id => $item) {
				$rem = $line_remaining_share[$item_id] * $factor;
				$cents = ($i === $last) ? max(0, $target_cents - $acc) : (int) round($rem * 100);
				$scaled_remaining_cents[$item_id] = $cents; $acc += $cents; $i++;
			}
		}

		// Points distribution (approximate to match EAO UI expectations)
		$points_redeemed_total = (int) $order->get_meta('_ywpar_coupon_points', true);
		$per_item_points_initial = [];
		$acc_pts = 0; $i_pts = 0; $last_pts = max(0, count($order_items) - 1);
		foreach ($order_items as $iid => $it_tmp) {
			$base = isset($per_item_base[$iid]) ? (float) $per_item_base[$iid] : 0.0;
			$portion = ($products_total > 0.0) ? ($base / $products_total) : 0.0;
			$alloc = ($i_pts === $last_pts) ? max(0, $points_redeemed_total - $acc_pts) : (int) round($points_redeemed_total * $portion);
			$per_item_points_initial[$iid] = $alloc; $acc_pts += $alloc; $i_pts++;
		}
		$per_item_points_refunded = [];
		foreach ($order->get_refunds() as $r) {
			$map_json = (string) get_post_meta($r->get_id(), '_eao_points_refunded_map', true);
			if ($map_json) {
				$map = json_decode($map_json, true);
				if (is_array($map)) {
					foreach ($map as $iid => $pts) {
						$iid = absint($iid);
						$per_item_points_refunded[$iid] = ($per_item_points_refunded[$iid] ?? 0) + (int) $pts;
					}
				}
			}
		}

		// Build rows
		foreach ($order_items as $item_id => $item) {
			$product = $item->get_product();
			$sku = $product ? $product->get_sku() : '';
			$image = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
			$qty = (int) $item->get_quantity();
			$points_initial = (int) ($per_item_points_initial[$item_id] ?? 0);
			$points_refunded = (int) ($per_item_points_refunded[$item_id] ?? 0);
			$points_remaining = max(0, $points_initial - $points_refunded);
			$share = isset($line_share[$item_id]) ? $line_share[$item_id]['share'] : 0.0;
			$remaining_scaled = isset($scaled_remaining_cents[$item_id]) ? ($scaled_remaining_cents[$item_id] / 100.0) : 0.0;
			$items_resp[] = array(
				'item_id' => $item_id,
				'name' => $item->get_name(),
				'sku' => $sku,
				'image' => $image,
				'qty' => $qty,
				'points_initial' => $points_initial,
				'points' => $points_remaining,
				'paid' => wc_format_decimal($share, 2),
				'remaining' => wc_format_decimal($remaining_scaled, 2)
			);
		}

		// Add shipping rows
		$shipping_items = $order->get_items('shipping');
		if (!empty($shipping_items)) {
			foreach ($shipping_items as $sh_id => $sh_item) {
				$sh_paid = (float) $sh_item->get_total() + (float) $sh_item->get_total_tax();
				$sh_refunded = 0.0;
				if (method_exists($order, 'get_total_refunded_for_item')) { $sh_refunded += (float) $order->get_total_refunded_for_item($sh_id, 'shipping'); }
				if (method_exists($order, 'get_total_tax_refunded_for_item')) { $sh_refunded += (float) $order->get_total_tax_refunded_for_item($sh_id, 'shipping'); }
				$sh_remaining = max(0.0, $sh_paid - $sh_refunded);
				$items_resp[] = array(
					'item_id' => $sh_id,
					'name' => 'Shipping: ' . $sh_item->get_name(),
					'sku' => '',
					'image' => '',
					'qty' => '',
					'points_initial' => 0,
					'points' => 0,
					'paid' => wc_format_decimal($sh_paid, 2),
					'remaining' => wc_format_decimal($sh_remaining, 2)
				);
			}
		}

		// Existing refunds snapshot
		$existing = array();
		foreach ($order->get_refunds() as $refund) {
			$existing[] = array(
				'id' => $refund->get_id(),
				'amount' => wc_format_decimal($refund->get_amount(), 2),
				'reason' => $refund->get_reason(),
				'date' => $refund->get_date_created() ? $refund->get_date_created()->date_i18n('Y-m-d H:i') : '',
				'points' => (int) get_post_meta($refund->get_id(), '_eao_points_refunded', true)
			);
		}

		// Gateway description fallback
		$gateway_info = array('label' => '');
		if (function_exists('eao_payment_describe_order_gateway')) {
			$gateway_info = \eao_payment_describe_order_gateway($order);
		} else {
			$mode_meta = strtolower((string) $order->get_meta('_eao_stripe_payment_mode'));
			$mode = ($mode_meta === 'test') ? 'Test' : 'Live';
			$gateway_info = array('label' => 'Stripe (EAO ' . $mode . ')');
		}

		if ($logger) {
			$logger->info('EAORefundCompat: responding with items', [
				'source' => 'hp-funnel-bridge',
				'order_id' => $order_id,
				'items' => count($order_items),
				'shipping_items' => count($order->get_items('shipping'))
			]);
		}

		// Ensure clean JSON (strip any prior buffered warnings from other plugins)
		if (function_exists('ob_get_level')) {
			while (ob_get_level() > 0) { @ob_end_clean(); }
		}
		wp_send_json_success(array(
			'items' => $items_resp,
			'refunds' => $existing,
			'gateway' => $gateway_info
		));
	}
}


