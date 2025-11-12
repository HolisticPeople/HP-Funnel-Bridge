<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use HP_FB\Services\PointsService;

if (!defined('ABSPATH')) { exit; }

class TotalsController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/totals', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		$address = (array) $request->get_param('address');
		$items   = (array) $request->get_param('items');
		$coupons = (array) $request->get_param('coupon_codes');
		$selected_rate = (array) $request->get_param('selected_rate');
		$points_to_redeem = (int) ($request->get_param('points_to_redeem') ?? 0);
		$email = (string) ($request->get_param('customer_email') ?? '');

		if (empty($items)) {
			return new WP_Error('bad_request', 'Items required', ['status' => 400]);
		}

		$order_id = 0;
		try {
			$order = wc_create_order(['status' => 'auto-draft']);
			$order_id = $order->get_id();

			// Items
			foreach ($items as $it) {
				$product_id = isset($it['product_id']) ? (int)$it['product_id'] : 0;
				$variation_id = isset($it['variation_id']) ? (int)$it['variation_id'] : 0;
				$qty = max(1, (int)($it['qty'] ?? 1));
				if ($product_id <= 0) { continue; }
				$product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
				if (!$product) { continue; }
				$item = new WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);
				$item->set_subtotal($product->get_price() * $qty);
				$item->set_total($product->get_price() * $qty);
				$order->add_item($item);
			}

			// Address
			$this->applyAddress($order, 'billing', $address);
			$this->applyAddress($order, 'shipping', $address);

			// Coupons
			if (!empty($coupons) && is_array($coupons)) {
				foreach ($coupons as $code) {
					$code = wc_format_coupon_code((string)$code);
					if ($code !== '') {
						$order->apply_coupon($code);
					}
				}
			}

			// Shipping (pass-through amount)
			if (!empty($selected_rate) && isset($selected_rate['amount'])) {
				$ship = new WC_Order_Item_Shipping();
				$ship->set_method_title(isset($selected_rate['serviceName']) ? (string)$selected_rate['serviceName'] : 'Shipping');
				$ship->set_total((float)$selected_rate['amount']);
				$order->add_item($ship);
			}

			// Points discount (preview only)
			$pointsDiscount = 0.0;
			if ($points_to_redeem > 0) {
				$ps = new PointsService();
				$pointsDiscount = $ps->pointsToMoney($points_to_redeem);
				// Represent as a negative fee for preview
				if ($pointsDiscount > 0) {
					$order->add_fee('Points redemption (preview)', -1 * $pointsDiscount);
				}
			}

			$order->calculate_totals(false);

			return new WP_REST_Response([
				'subtotal' => (float)$order->get_subtotal(),
				'discount_total' => (float)$order->get_discount_total(),
				'shipping_total' => (float)$order->get_shipping_total(),
				'tax_total' => (float)$order->get_total_tax(),
				'fees_total' => (float)$order->get_fees() ? array_sum(array_map(function($f){ return (float)$f->get_total(); }, $order->get_fees())) : 0.0,
				'points_discount' => $pointsDiscount,
				'grand_total' => (float)$order->get_total(),
			]);
		} finally {
			if ($order_id > 0) {
				wp_delete_post($order_id, true);
			}
		}
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


