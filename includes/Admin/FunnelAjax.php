<?php
namespace HP_FB\Admin;

if (!defined('ABSPATH')) { exit; }

class FunnelAjax {
	public static function register(): void {
		add_action('wp_ajax_hp_fb_search_products', [__CLASS__, 'searchProducts']);
		add_action('wp_ajax_hp_fb_save_funnel_config', [__CLASS__, 'saveFunnelConfig']);
	}

	/**
	 * AJAX: search WooCommerce products by term (name or SKU).
	 * Returns a lightweight list used by the funnel config UI.
	 */
	public static function searchProducts(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied'], 403);
		}
		$nonce = isset($_REQUEST['_ajax_nonce']) ? (string) $_REQUEST['_ajax_nonce'] : '';
		$funnel_id = isset($_REQUEST['funnel_id']) ? sanitize_key((string) $_REQUEST['funnel_id']) : '';
		if (!wp_verify_nonce($nonce, 'hp_fb_funnel_config_' . $funnel_id) && !wp_verify_nonce($nonce, 'hp_fb_funnel_config_' . '')) {
			wp_send_json_error(['message' => 'Bad nonce'], 403);
		}
		$term = isset($_GET['term']) ? wc_clean(wp_unslash((string) $_GET['term'])) : '';
		if ($term === '') {
			wp_send_json_success([]);
		}
		// Reuse EAO's rich product search to ensure identical behavior to the
		// Enhanced Admin Order editor. We call its admin-ajax endpoint and then
		// adapt the response to a lightweight structure for this UI.
		$ajax_url = admin_url('admin-ajax.php');
		$eao_nonce = wp_create_nonce('eao_search_products_for_admin_order_nonce');
		$resp = wp_remote_post($ajax_url, [
			'timeout' => 10,
			'body' => [
				'action'      => 'eao_search_products_for_admin_order',
				'nonce'       => $eao_nonce,
				'search_term' => $term,
				'order_id'    => 0,
			],
		]);
		if (is_wp_error($resp)) {
			wp_send_json_error(['message' => 'Search failed'], 500);
		}
		$body = wp_remote_retrieve_body($resp);
		$data = json_decode((string) $body, true);
		if (!is_array($data) || empty($data['success']) || empty($data['data']) || !is_array($data['data'])) {
			wp_send_json_success([]);
		}
		$out = [];
		foreach ($data['data'] as $row) {
			$pid = isset($row['id']) ? (int)$row['id'] : 0;
			if ($pid <= 0) { continue; }
			$name = isset($row['name']) ? (string)$row['name'] : '';
			$sku  = isset($row['sku']) ? (string)$row['sku'] : '';
			$price_raw = isset($row['price_raw']) ? (float)$row['price_raw'] : 0.0;
			$thumb = isset($row['thumbnail_url']) ? (string)$row['thumbnail_url'] : '';
			// Fallbacks from product object if needed
			if (($price_raw <= 0 || $thumb === '') && function_exists('wc_get_product')) {
				$p = wc_get_product($pid);
				if ($p) {
					if ($price_raw <= 0) {
						$price_raw = (float) $p->get_regular_price();
					}
					if ($thumb === '') {
						$img_id = $p->get_image_id();
						if ($img_id) {
							$url = wp_get_attachment_image_url($img_id, 'thumbnail');
							if ($url) { $thumb = $url; }
						}
					}
				}
			}
			$out[] = [
				'id'    => $pid,
				'name'  => $name,
				'sku'   => $sku,
				'image' => $thumb,
				'price' => $price_raw,
			];
		}
		wp_send_json_success($out);
	}

	private static function formatProduct($product): array {
		$img = '';
		if (method_exists($product, 'get_image_id')) {
			$img_id = $product->get_image_id();
			if ($img_id) {
				$url = wp_get_attachment_image_url($img_id, 'thumbnail');
				if ($url) { $img = $url; }
			}
		}
		$price = (float) $product->get_regular_price();
		return [
			'id' => $product->get_id(),
			'name' => $product->get_name(),
			'sku' => (string) $product->get_sku(),
			'image' => $img,
			'price' => $price,
		];
	}

	/**
	 * AJAX: save per-funnel config (global discount + products).
	 */
	public static function saveFunnelConfig(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied'], 403);
		}
		$raw = file_get_contents('php://input');
		$payload = json_decode((string) $raw, true);
		if (!is_array($payload)) {
			wp_send_json_error(['message' => 'Invalid payload'], 400);
		}
		$funnel_id = isset($payload['funnel_id']) ? sanitize_key((string)$payload['funnel_id']) : '';
		$nonce = isset($payload['nonce']) ? (string)$payload['nonce'] : '';
		if ($funnel_id === '' || !wp_verify_nonce($nonce, 'hp_fb_funnel_config_' . $funnel_id)) {
			wp_send_json_error(['message' => 'Bad nonce'], 403);
		}
		$global = isset($payload['global_discount_percent']) ? (float)$payload['global_discount_percent'] : 0.0;
		if ($global < 0) { $global = 0.0; }
		if ($global > 100) { $global = 100.0; }
		$products_in = isset($payload['products']) && is_array($payload['products']) ? $payload['products'] : [];
		$products = [];
		if (!empty($products_in)) {
			foreach ($products_in as $p) {
				if (!is_array($p)) { continue; }
				$pid = isset($p['product_id']) ? (int)$p['product_id'] : 0;
				if ($pid <= 0) { continue; }
				$role = isset($p['role']) ? (string)$p['role'] : 'base';
				if (!in_array($role, ['base','optional','upsell'], true)) { $role = 'base'; }
				$products[] = [
					'product_id' => $pid,
					'sku' => isset($p['sku']) ? sanitize_text_field((string)$p['sku']) : '',
					'role' => $role,
					'exclude_global_discount' => !empty($p['exclude_global_discount']) ? 1 : 0,
					'item_discount_percent' => isset($p['item_discount_percent']) ? (float)$p['item_discount_percent'] : 0.0,
				];
			}
		}
		$opts = get_option('hp_fb_settings', []);
		if (!isset($opts['funnel_configs']) || !is_array($opts['funnel_configs'])) {
			$opts['funnel_configs'] = [];
		}
		$opts['funnel_configs'][$funnel_id] = [
			'global_discount_percent' => $global,
			'products' => $products,
		];
		update_option('hp_fb_settings', $opts);
		wp_send_json_success();
	}
}


