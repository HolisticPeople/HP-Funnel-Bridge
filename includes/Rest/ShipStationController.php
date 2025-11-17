<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use HP_FB\Util\Resolver;

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
				$qty = max(1, (int)($it['qty'] ?? 1));
				$product = Resolver::resolveProductFromItem((array)$it);
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

			// Build base request via EAO helper
			$base_request = \eao_build_shipstation_rates_request($order);
			if (!$base_request || !is_array($base_request)) {
				return new WP_Error('bad_request', 'Could not prepare ShipStation request', ['status' => 400]);
			}

			// Mirror EAO logic: iterate carriers and customize UPS
			$default_carriers = array('stamps_com', 'ups_walleted');
			$carriers_to_try = apply_filters('eao_shipstation_carriers_to_query', $default_carriers);
			if (!is_array($carriers_to_try)) { $carriers_to_try = $default_carriers; }

			$all_rates = array();
			$carrier_errors = array();

			foreach ($carriers_to_try as $carrier_code) {
				if (!is_string($carrier_code) || $carrier_code === '') { continue; }
				$request_data = $base_request;
				$request_data['carrierCode'] = $carrier_code;
				if (($carrier_code === 'ups_walleted' || $carrier_code === 'ups') && function_exists('eao_customize_ups_request')) {
					$request_data = \eao_customize_ups_request($request_data);
				}
				$carrier_rates_result = \eao_get_shipstation_carrier_rates($request_data);
				if (isset($carrier_rates_result['success']) && $carrier_rates_result['success'] && isset($carrier_rates_result['rates']) && is_array($carrier_rates_result['rates']) && !empty($carrier_rates_result['rates'])) {
					foreach ($carrier_rates_result['rates'] as &$rate) {
						if (is_array($rate) && !isset($rate['carrierCode'])) { $rate['carrierCode'] = $carrier_code; }
					}
					$all_rates = array_merge($all_rates, $carrier_rates_result['rates']);
				} else {
					$carrier_errors[$carrier_code] = isset($carrier_rates_result['message']) ? (string)$carrier_rates_result['message'] : 'Unknown carrier error';
				}
			}

			if (empty($all_rates)) {
				$err = !empty($carrier_errors) ? 'HTTP Error: ' . implode('; ', array_map(function($k,$v){ return $k.': '.$v; }, array_keys($carrier_errors), array_values($carrier_errors))) : 'No rates';
				return new WP_Error('shipstation_error', $err, ['status' => 502]);
			}

			$rates = $all_rates;
			if (function_exists('eao_format_shipstation_rates_response')) {
				$fmt = \eao_format_shipstation_rates_response($rates);
				if (is_array($fmt) && isset($fmt['rates'])) { $rates = $fmt['rates']; }
			}

			// Respect HP ShipStation Rates plugin allow‑list (single source of truth via helper)
			$allowed = $this->getAllowedServiceCodes();
			if (!empty($allowed)) {
				$rates = array_values(array_filter($rates, function($r) use ($allowed){
					if (!is_array($r)) { return false; }
					$code = '';
					if (isset($r['serviceCode'])) { $code = (string)$r['serviceCode']; }
					elseif (isset($r['service_code'])) { $code = (string)$r['service_code']; }
					elseif (isset($r['code'])) { $code = (string)$r['code']; }
					$code = strtolower(trim($code));
					return $code !== '' ? in_array($code, $allowed, true) : true; // if code unknown, keep
				}));
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

	/**
	 * Read allowed service codes from the HP ShipStation Rates plugin.
	 * Single source of truth: requires helper function hp_ss_get_enabled_service_codes().
	 * If helper is not present, no filtering is applied (plugin not active).
	 *
	 * @return array<string> Lowercase ShipStation serviceCode values that are enabled.
	 */
	private function getAllowedServiceCodes(): array {
		if (function_exists('hp_ss_get_enabled_service_codes')) {
			try {
				$list = \hp_ss_get_enabled_service_codes();
				if (is_array($list) && !empty($list)) {
					return array_values(array_unique(array_map(function($s){ return strtolower(trim((string)$s)); }, $list)));
				}
			} catch (\Throwable $e) { /* ignore */ }
		}
		return [];
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


