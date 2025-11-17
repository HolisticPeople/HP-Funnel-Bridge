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
	}

	public function maybeHandleRefundData(): void {
		// Basic guards and order resolution
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		if (!$order_id) { return; }
		$order = wc_get_order($order_id);
		if (!$order) { return; }

		// Only take over for Bridge-created Stripe orders
		$is_bridge = (
			(string) $order->get_meta('_hp_fb_stripe_payment_intent_id', true) !== '' ||
			(string) $order->get_meta('_hp_fb_stripe_charge_id', true) !== '' ||
			(string) $order->get_payment_method() === 'hp_fb_stripe'
		);
		if (!$is_bridge) { return; }

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

		wp_send_json_success(array(
			'items' => $items_resp,
			'refunds' => $existing,
			'gateway' => $gateway_info
		));
	}
}


