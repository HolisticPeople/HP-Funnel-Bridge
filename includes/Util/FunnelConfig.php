<?php
namespace HP_FB\Util;

if (!defined('ABSPATH')) { exit; }

class FunnelConfig {
	/**
	 * Get configuration for a specific funnel.
	 *
	 * @param string $funnel_id
	 * @return array{global_discount_percent: float, products: array}
	 */
	public static function get(string $funnel_id): array {
		$opts = get_option('hp_fb_settings', []);
		if (!empty($opts['funnel_configs']) && isset($opts['funnel_configs'][$funnel_id])) {
			return $opts['funnel_configs'][$funnel_id];
		}
		
		// Fallback for legacy/hardcoded behavior (Fasting Kit) if not in config
		if ($funnel_id === 'fastingkit') {
			return [
				'global_discount_percent' => 10.0,
				'products' => []
			];
		}

		return [
			'global_discount_percent' => 0.0,
			'products' => []
		];
	}

	/**
	 * Get product configuration by ID or SKU from the funnel config.
	 *
	 * @param array $config The funnel config array.
	 * @param int $product_id
	 * @param string $sku
	 * @return array|null The product config array or null if not found.
	 */
	public static function getProductConfig(array $config, int $product_id, string $sku = ''): ?array {
		if (empty($config['products'])) {
			return null;
		}
		foreach ($config['products'] as $p) {
			// Match by ID
			if (isset($p['product_id']) && (int)$p['product_id'] === $product_id) {
				return $p;
			}
			// Match by SKU if ID mismatch (fallback)
			if ($sku !== '' && isset($p['sku']) && (string)$p['sku'] === $sku) {
				return $p;
			}
		}
		return null;
	}
}

