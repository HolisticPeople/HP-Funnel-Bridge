<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

class OrdersController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/orders/resolve', [
			'methods'  => 'GET',
			'callback' => [$this, 'resolveByPi'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('hp-funnel/v1', '/orders/summary', [
			'methods'  => 'GET',
			'callback' => [$this, 'summaryById'],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Resolve WooCommerce order id by Stripe PaymentIntent id.
	 * GET /hp-funnel/v1/orders/resolve?pi_id=pi_xxx
	 */
	public function resolveByPi(WP_REST_Request $request) {
		$pi = (string) ($request->get_param('pi') ?? $request->get_param('pi_id') ?? '');
		if ($pi === '') {
			return new WP_REST_Response(['ok' => false, 'reason' => 'missing_pi'], 400);
		}
		if (!function_exists('wc_get_orders')) {
			return new WP_REST_Response(['ok' => false, 'reason' => 'wc_missing'], 500);
		}
		$args = [
			'limit'      => 1,
			'return'     => 'ids',
			'meta_key'   => '_hp_fb_stripe_payment_intent_id',
			'meta_value' => $pi,
			'status'     => array_keys(wc_get_order_statuses()),
		];
		$ids = wc_get_orders($args);
		$order_id = !empty($ids) ? (int) $ids[0] : 0;
		if ($order_id > 0) {
			return new WP_REST_Response(['ok' => true, 'order_id' => $order_id], 200);
		}
		// Not found yet – instruct caller to retry
		$resp = new WP_REST_Response(['ok' => false, 'pending' => true], 404);
		$resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
		return $resp;
	}

	/**
	 * Get a lightweight order summary for Thank You page rendering.
	 * GET /hp-funnel/v1/orders/summary?order_id=123
	 */
	public function summaryById(WP_REST_Request $request) {
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : (int) $request->get_param('order_id');
		if ($order_id <= 0) {
			return new WP_REST_Response(['ok' => false, 'reason' => 'missing_order_id'], 400);
		}
		if (!function_exists('wc_get_order')) {
			return new WP_REST_Response(['ok' => false, 'reason' => 'wc_missing'], 500);
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			return new WP_REST_Response(['ok' => false, 'reason' => 'not_found'], 404);
		}

		$items = [];
		$subtotal = 0.0;
		$items_discount = 0.0;
		foreach ($order->get_items('line_item') as $it) {
			$name = $it->get_name();
			$qty  = (int) $it->get_quantity();
			$sub  = (float) $it->get_subtotal();
			$total= (float) $it->get_total();
			$price= $qty > 0 ? ($sub / $qty) : 0.0;
			$discount = max(0.0, $sub - $total);
			$subtotal += $sub;
			$items_discount += $discount;
			$image = '';
			try {
				$prod = $it->get_product();
				if ($prod && method_exists($prod, 'get_image_id')) {
					$img_id = $prod->get_image_id();
					if ($img_id) {
						$img_url = wp_get_attachment_image_url($img_id, 'thumbnail');
						if ($img_url) { $image = (string) $img_url; }
					}
				}
			} catch (\Throwable $e) {}
			$items[] = [
				'name' => $name,
				'qty'  => $qty,
				'price'=> round($price, 2),
				'discount' => round($discount, 2),
				'total'=> round($total, 2),
				'image'=> $image,
			];
		}

		$shipping_total = (float) $order->get_shipping_total();
		$shipping_tax   = (float) $order->get_shipping_tax();

		// Fees (can include negative fees for discounts)
		$fees_total = 0.0;
		foreach ($order->get_items('fee') as $fee) {
			$fees_total += (float) $fee->get_total();
		}

		// Points redeemed (if YITH points was used; meta key may vary)
		$points_redeemed = (int) $order->get_meta('_ywpar_coupon_points', true);

		$summary = [
			'ok' => true,
			'order_id' => $order->get_id(),
			'order_number' => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id(),
			'currency' => $order->get_currency(),
			'items' => $items,
			'subtotal' => round($subtotal, 2),
			'items_discount' => round($items_discount, 2),
			'shipping_total' => round($shipping_total + $shipping_tax, 2),
			'fees_total' => round($fees_total, 2),
			'grand_total' => round((float) $order->get_total(), 2),
			'points_redeemed' => $points_redeemed,
		];

		return new WP_REST_Response($summary, 200);
	}
}


