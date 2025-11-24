<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use HP_FB\Services\PointsService;
use HP_FB\Util\Resolver;
use HP_FB\Util\FunnelConfig;

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
		$funnel_id = (string) ($request->get_param('funnel_id') ?? 'default');
		$address   = (array) $request->get_param('address');
		$items     = (array) $request->get_param('items');
		$coupons   = (array) $request->get_param('coupon_codes');
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
				$qty = max(1, (int)($it['qty'] ?? 1));
				$product = Resolver::resolveProductFromItem((array)$it);
				if (!$product) { continue; }
				$item = new WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);

				$price = (float) $product->get_price();
				$subtotal = $price * $qty;
				$total    = $subtotal;

				$exclude_gd = !empty($it['exclude_global_discount']);
				$item_pct   = isset($it['item_discount_percent']) ? (float) $it['item_discount_percent'] : null;

				if ($item_pct !== null && $item_pct >= 0) {
					$discounted = $price * (1 - ($item_pct / 100.0));
					$total = max(0.0, $discounted * $qty);
					// Record per-item discount percent so downstream logic (and EAO) can see it
					$item->add_meta_data('_eao_item_discount_percent', $item_pct, true);
				}
				if ($exclude_gd) {
					$item->add_meta_data('_eao_exclude_global_discount', '1', true);
				}

				$item->set_subtotal($subtotal);
				$item->set_total($total);

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
			
			// Load funnel config for discounts
			$fConfig = FunnelConfig::get($funnel_id);
			$global_percent = (float)$fConfig['global_discount_percent'];
			
			// Apply per-item overrides (exclude global, specific item discount) from config
			// but only for items that do NOT already carry explicit overrides from the payload.
			foreach ($order->get_items() as $item) {
				if (!$item instanceof WC_Order_Item_Product) { continue; }

				$has_explicit_exclude = (bool) $item->get_meta('_eao_exclude_global_discount', true);
				$has_explicit_pct     = $item->get_meta('_eao_item_discount_percent', true) !== '';
				if ($has_explicit_exclude || $has_explicit_pct) {
					continue;
				}

				$pid = $item->get_product_id();
				$product = $item->get_product();
				if (!$product) { continue; }
				$sku = (string)$product->get_sku();
				
				$pConf = FunnelConfig::getProductConfig($fConfig, $pid, $sku);
				if ($pConf && !empty($pConf['exclude_global_discount'])) {
					// Excluded from global discount. Check for specific item discount.
					$item_discount = isset($pConf['item_discount_percent']) ? (float)$pConf['item_discount_percent'] : 0.0;
					if ($item_discount > 0) {
						$qty = $item->get_quantity();
						$regular = (float) $product->get_price(); // MSRP
						$discounted = $regular * (1 - ($item_discount / 100.0));
						// Set subtotal to MSRP (standard WC practice) and total to discounted
						$item->set_subtotal($regular * $qty);
						$item->set_total($discounted * $qty);
						$item->add_meta_data('_eao_item_discount_percent', $item_discount, true);
					}
					// Mark as excluded so global discount logic ignores it
					$item->add_meta_data('_eao_exclude_global_discount', '1', true);
				}
			}

			$products_gross = 0.0;
			// Sum up subtotal of items NOT excluded from global discount
			foreach ($order->get_items() as $item) {
				if (!$item instanceof WC_Order_Item_Product) { continue; }
				// If excluded, it contributes to net but not to the global discount base
				if ($item->get_meta('_eao_exclude_global_discount')) {
					continue;
				}
				$products_gross += (float)$item->get_subtotal();
			}

			$discount_total = (float) $order->get_discount_total();
			$global_discount = 0.0;
			if ($global_percent > 0.0 && $products_gross > 0.0) {
				$global_discount = round($products_gross * ($global_percent / 100.0), 2);
				if ($global_discount > 0.0) {
					$fee = new \WC_Order_Item_Fee();
					$fee->set_name('Global discount (' . $global_percent . '%)');
					$fee->set_amount(-1 * $global_discount);
					$fee->set_total(-1 * $global_discount);
					$order->add_item($fee);
				}
			}
			
			// Re-calculate subtotal of all products (including excluded ones) for net calculation
			$all_products_subtotal = (float)$order->get_subtotal(); 
			// Note: get_subtotal() might be affected by our set_total() changes? 
			// Actually WC_Order::get_subtotal() sums line subtotals. We kept set_subtotal() as MSRP.
			
			$products_net = max(0.0, $all_products_subtotal - $discount_total - $global_discount);

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
      $fees_excluding_points = 0.0;
      foreach ($order->get_fees() as $fee) {
        $val = (float) $fee->get_total();
        $fees_total += $val;
        $name = method_exists($fee, 'get_name') ? (string)$fee->get_name() : '';
        if (stripos($name, 'Points redemption') === false) {
          $fees_excluding_points += $val;
        }
      }
      $shipping_total = (float) $order->get_shipping_total();
      $grand_manual = max(0.0, $items_total_after_discounts + $fees_total + $shipping_total);

			return new WP_REST_Response([
				'subtotal' => (float)$order->get_subtotal(),
        'discount_total' => (float)$order->get_discount_total(),
        'shipping_total' => (float)$order->get_shipping_total(),
				'tax_total' => (float)$order->get_total_tax(),
        'fees_total' => (float)$order->get_fees() ? array_sum(array_map(function($f){ return (float)$f->get_total(); }, $order->get_fees())) : 0.0,
        'global_discount' => (float)$global_discount,
        'points_discount' => (float)$pointsDiscount,
        // Subtotal after global discount, before points (for display)
        'discounted_subtotal' => (float) max(0.0, $items_total_after_discounts + $fees_excluding_points),
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


