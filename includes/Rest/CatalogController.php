<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) { exit; }

class CatalogController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/catalog/prices', [
			'methods'  => 'GET',
			'callback' => [$this, 'prices'],
			'permission_callback' => '__return_true',
		]);
	}

	public function prices(WP_REST_Request $request) {
		$skus_raw = (string) ($request->get_param('skus') ?? '');
		$skus = array_values(array_filter(array_map('trim', explode(',', $skus_raw))));
		if (empty($skus)) {
			return new WP_REST_Response(['ok' => false, 'reason' => 'missing_skus'], 400);
		}
		$out = [];
		foreach ($skus as $sku) {
			$id = function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
			if ($id) {
				$prod = wc_get_product($id);
				if ($prod) {
					$price = method_exists($prod, 'get_regular_price') ? (float) $prod->get_regular_price() : (float) $prod->get_price();
					$out[$sku] = $price;
				}
			}
		}
		return new WP_REST_Response(['ok' => true, 'prices' => $out], 200);
	}
}


