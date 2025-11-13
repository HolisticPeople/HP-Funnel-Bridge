<?php
namespace HP_FB\Util;

if (!defined('ABSPATH')) { exit; }

/**
 * Utility helpers to resolve Woo products from incoming item payloads.
 *
 * Supported payload shape per item:
 * - { product_id?: number, sku?: string, variation_id?: number, qty?: number }
 */
class Resolver {
	/**
	 * Resolve a WC_Product (or variation) from an incoming item payload.
	 *
	 * @param array $item
	 * @return \WC_Product|null
	 */
	public static function resolveProductFromItem(array $item) {
		$product_id   = isset($item['product_id']) ? (int)$item['product_id'] : 0;
		$variation_id = isset($item['variation_id']) ? (int)$item['variation_id'] : 0;
		$sku          = isset($item['sku']) ? (string)$item['sku'] : '';

		if ($product_id <= 0 && $sku !== '') {
			$pid = wc_get_product_id_by_sku($sku);
			if ($pid && is_numeric($pid)) {
				$product_id = (int) $pid;
			}
		}
		if ($variation_id > 0) {
			$p = wc_get_product($variation_id);
			if ($p) { return $p; }
		}
		if ($product_id > 0) {
			$p = wc_get_product($product_id);
			if ($p) { return $p; }
		}
		return null;
	}
}


