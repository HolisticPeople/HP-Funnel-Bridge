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

      // First pass calculation to know product net (excludes shipping/tax)
      $order->calculate_totals(false);
      $products_gross = (float) $order->get_subtotal();
      $discount_total = (float) $order->get_discount_total();
      $products_net = max(0.0, $products_gross - $discount_total);

      // Points discount (preview only). Cap to products_net (no points on shipping).
      $pointsDiscount = 0.0;
      if ($points_to_redeem > 0 && $products_net > 0) {
        $ps = new PointsService();
        $pointsDiscount = min($ps->pointsToMoney($points_to_redeem), $products_net);
        if ($pointsDiscount > 0) {
          $fee = new \WC_Order_Item_Fee();
          $fee->set_name('Points redemption (preview)');
          $fee->set_amount(-1 * $pointsDiscount);
          $fee->set_total(-1 * $pointsDiscount);
          $order->add_item($fee);
        }
      } else {
        $pointsDiscount = 0.0;
      }

      // Recalculate after applying fee to update order internals
      $order->calculate_totals(false);

      // Build grand manually from components (avoid WC rounding quirks in get_total)
      $items_total_after_discounts = 0.0;
      foreach ($order->get_items() as $it) {
        if ($it instanceof \WC_Order_Item_Product) {
          $items_total_after_discounts += (float) $it->get_total();
        }
      }
      $fees_total = 0.0;
      foreach ($order->get_fees() as $fee) {
        $fees_total += (float) $fee->get_total();
      }
      $shipping_total = (float) $order->get_shipping_total();
      $grand_manual = max(0.0, $items_total_after_discounts + $fees_total + $shipping_total);

			return new WP_REST_Response([
				'subtotal' => (float)$order->get_subtotal(),
        'discount_total' => (float)$order->get_discount_total(),
        'shipping_total' => (float)$order->get_shipping_total(),
				'tax_total' => (float)$order->get_total_tax(),
				'fees_total' => (float)$order->get_fees() ? array_sum(array_map(function($f){ return (float)$f->get_total(); }, $order->get_fees())) : 0.0,
        'points_discount' => (float)$pointsDiscount,
        'grand_total' => (float)$grand_manual,
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


