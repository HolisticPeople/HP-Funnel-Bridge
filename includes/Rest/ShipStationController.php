<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

if (!defined('ABSPATH')) { exit; }

class ShipStationController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/shipstation/rates', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		// Ensure EAO ShipStation helpers are loaded even if EAO loads them only in admin
		if (!function_exists('eao_build_shipstation_rates_request') || !function_exists('eao_get_shipstation_carrier_rates')) {
			$this->tryLoadEaoShipStation();
			if (!function_exists('eao_build_shipstation_rates_request') || !function_exists('eao_get_shipstation_carrier_rates')) {
				return new WP_Error('dependency_missing', 'EAO ShipStation utilities not available', ['status' => 500]);
			}
		}

		$address = (array) $request->get_param('address');
		$items   = (array) $request->get_param('items');

		if (empty($items)) {
			return new WP_Error('bad_request', 'Items required', ['status' => 400]);
		}

		// Create transient order
		$order_id = 0;
		$order = null;
		try {
			$order = wc_create_order(['status' => 'auto-draft']);
			$order_id = $order->get_id();
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
				$item->set_total($product->get_price() * $qty);
				$order->add_item($item);
			}
			// Set shipping address (only what we have)
			$this->applyAddress($order, 'shipping', $address);
			$order->save();

			// Build request via EAO helper and fetch rates
			$req = \eao_build_shipstation_rates_request($order);
			if (!$req || !is_array($req)) {
				return new WP_Error('bad_request', 'Could not prepare ShipStation request', ['status' => 400]);
			}
			$ratesResult = \eao_get_shipstation_carrier_rates($req);
			if (!is_array($ratesResult) || empty($ratesResult['success'])) {
				$msg = is_array($ratesResult) && !empty($ratesResult['message']) ? (string)$ratesResult['message'] : 'Failed to fetch rates';
				return new WP_Error('shipstation_error', $msg, ['status' => 502]);
			}
			$rates = isset($ratesResult['rates']) && is_array($ratesResult['rates']) ? $ratesResult['rates'] : [];
			// Format (if helper exists)
			if (function_exists('eao_format_shipstation_rates_response')) {
				$fmt = \eao_format_shipstation_rates_response($rates);
				if (is_array($fmt) && isset($fmt['rates'])) {
					$rates = $fmt['rates'];
				}
			}
			return new WP_REST_Response(['rates' => $rates]);
		} finally {
			// Cleanup the transient order
			if ($order_id > 0) {
				wp_delete_post($order_id, true);
			}
		}
	}

	/**
	 * Attempt to include EAO ShipStation helper files when functions are not available in non-admin context.
	 */
	private function tryLoadEaoShipStation(): void {
		// Common slug for EAO
		$base = trailingslashit(WP_PLUGIN_DIR) . 'enhanced-admin-order-plugin/';
		$utils = $base . 'eao-shipstation-utils.php';
		$core  = $base . 'eao-shipstation-core.php';
		if (file_exists($utils)) { require_once $utils; }
		if (file_exists($core)) { require_once $core; }
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


