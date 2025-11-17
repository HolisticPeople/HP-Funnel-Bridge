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
}


