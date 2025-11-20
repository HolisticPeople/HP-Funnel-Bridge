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
		global $wpdb;
		$search_term = $term;
		$search_term_lower = strtolower($search_term);
		$search_words = array_filter(explode(' ', $search_term_lower));
		$limit_per_query = 20;

		$products_with_scores = [];
		$searched_product_ids = [];

		// 1. Title search (adapted from EAO).
		$title_query = $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type IN ('product','product_variation') AND post_status = 'publish'
			 AND post_title LIKE %s
			 ORDER BY CASE
				WHEN post_title LIKE %s THEN 1
				WHEN post_title LIKE %s THEN 2
				ELSE 3
			 END, post_title ASC
			 LIMIT %d",
			'%' . $wpdb->esc_like($search_term) . '%',
			$wpdb->esc_like($search_term),
			$wpdb->esc_like($search_term) . '%',
			$limit_per_query
		);
		$title_matches = $wpdb->get_results($title_query);
		foreach ($title_matches as $product_post) {
			$product_title_lower = strtolower($product_post->post_title);
			$score = 0;
			if ($product_title_lower === $search_term_lower) {
				$score = 100;
			} elseif (strpos($product_title_lower, $search_term_lower) === 0) {
				$score = 90;
			} else {
				$score = 70;
				$match_count = 0;
				foreach ($search_words as $word) {
					if (strpos($product_title_lower, $word) !== false) {
						$match_count++;
					}
				}
				if (count($search_words) > 1 && $match_count === count($search_words)) {
					$score += 10;
				}
			}
			$products_with_scores[$product_post->ID] = ['id' => $product_post->ID, 'score' => $score];
			$searched_product_ids[] = $product_post->ID;
		}

		// 2. SKU search.
		if (count($searched_product_ids) < $limit_per_query) {
			$sku_query_args = [
				"SELECT p.ID, p.post_title, pm.meta_value as sku
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
				 AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s"
			];
			$prepared_values = ['%' . $wpdb->esc_like($search_term) . '%'];
			if (!empty($searched_product_ids)) {
				$placeholders = implode(',', array_fill(0, count($searched_product_ids), '%d'));
				$sku_query_args[] = "AND p.ID NOT IN ({$placeholders})";
				$prepared_values = array_merge($prepared_values, $searched_product_ids);
			}
			$sku_query_args[] = "ORDER BY CASE
									WHEN pm.meta_value LIKE %s THEN 1
									WHEN pm.meta_value LIKE %s THEN 2
									ELSE 3
								 END, p.post_title ASC
								 LIMIT %d";
			$prepared_values[] = $wpdb->esc_like($search_term);
			$prepared_values[] = $wpdb->esc_like($search_term) . '%';
			$prepared_values[] = $limit_per_query - count($searched_product_ids);

			$sku_query = $wpdb->prepare(implode(' ', $sku_query_args), $prepared_values);
			$sku_matches = $wpdb->get_results($sku_query);
			foreach ($sku_matches as $product_post) {
				$sku_lower = strtolower($product_post->sku);
				$score = ($sku_lower === $search_term_lower) ? 95 : 80;
				if (isset($products_with_scores[$product_post->ID])) {
					$products_with_scores[$product_post->ID]['score'] = max($products_with_scores[$product_post->ID]['score'], $score + 5);
				} else {
					$products_with_scores[$product_post->ID] = ['id' => $product_post->ID, 'score' => $score];
					$searched_product_ids[] = $product_post->ID;
				}
			}
		}

		// Sort by score desc and load products.
		if (!empty($products_with_scores)) {
			uasort($products_with_scores, function($a, $b){
				return $b['score'] - $a['score'];
			});
		}
		$final_ids = array_slice(array_map(function($row){ return $row['id']; }, $products_with_scores), 0, $limit_per_query);
		$out = [];
		if (!empty($final_ids)) {
			foreach ($final_ids as $pid) {
				$product = wc_get_product($pid);
				if (!$product) { continue; }
				$out[] = self::formatProduct($product);
			}
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


