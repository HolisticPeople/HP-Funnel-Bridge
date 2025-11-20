<?php
namespace HP_FB\Rest;

use HP_FB\Services\OrderDraftStore;
use HP_FB\Services\PointsService;
use HP_FB\Stripe\Client as StripeClient;
use HP_FB\Util\Resolver;
use HP_FB\Util\FunnelConfig;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

if (!defined('ABSPATH')) { exit; }

class CheckoutController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/checkout/intent', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		// Determine per-funnel Stripe mode override if configured
		$opts = get_option('hp_fb_settings', []);
		$modeOverride = null;
		if (!empty($opts['funnels']) && is_array($opts['funnels']) && $request->get_param('funnel_id')) {
			$fid = (string)$request->get_param('funnel_id');
			$currentHost = parse_url(home_url(), PHP_URL_HOST);
			foreach ($opts['funnels'] as $f) {
				if (is_array($f) && !empty($f['id']) && (string)$f['id'] === $fid) {
					$stgHost = !empty($f['origin_staging']) ? parse_url((string)$f['origin_staging'], PHP_URL_HOST) : null;
					$prodHost = !empty($f['origin_production']) ? parse_url((string)$f['origin_production'], PHP_URL_HOST) : null;
					if ($currentHost && $stgHost && $currentHost === $stgHost) {
						$modeOverride = $f['mode_staging'] ?? 'test';
					} elseif ($currentHost && $prodHost && $currentHost === $prodHost) {
						$modeOverride = $f['mode_production'] ?? 'live';
					} else {
						// Fallback: if not matched, prefer staging mode
						$modeOverride = $f['mode_staging'] ?? 'test';
					}
					break;
				}
			}
		}
		// If funnel is switched off in this environment, instruct caller to redirect away
		if ($modeOverride === 'off') {
			$redirect = home_url('/');
			return new WP_Error(
				'funnel_off',
				'Funnel is temporarily disabled for this environment.',
				['status' => 409, 'redirect' => $redirect]
			);
		}

		$stripe = new StripeClient(($modeOverride === 'test' || $modeOverride === 'live') ? $modeOverride : null);
		if (!$stripe->isConfigured()) {
			return new WP_Error('stripe_not_configured', 'Stripe keys are missing in EAO settings', ['status' => 500]);
		}

		$funnel_id = (string) ($request->get_param('funnel_id') ?? 'default');
		$funnel_name = (string) ($request->get_param('funnel_name') ?? 'Funnel');
		$customer = (array) $request->get_param('customer');
		$address  = (array) $request->get_param('shipping_address');
		$items    = (array) $request->get_param('items');
		$coupons  = (array) $request->get_param('coupon_codes');
		$selected_rate = (array) $request->get_param('selected_rate');
		$points_to_redeem = (int) ($request->get_param('points_to_redeem') ?? 0);
		$analytics = [
			'campaign' => (string) ($request->get_param('campaign') ?? ''),
			'source'   => (string) ($request->get_param('source') ?? ''),
			'utm'      => (array) ($request->get_param('utm') ?? []),
		];

		if (empty($items)) {
			return new WP_Error('bad_request', 'Items required', ['status' => 400]);
		}
		$email = isset($customer['email']) ? (string)$customer['email'] : '';
		if ($email === '' || !is_email($email)) {
			return new WP_Error('bad_request', 'Valid customer email required', ['status' => 400]);
		}

		// Compute amount by building transient order (to leverage Woo pricing, coupons)
		$order_id = 0;
		$grand_total = 0.0;
		try {
			$order = wc_create_order(['status' => 'auto-draft']);
			$order_id = $order->get_id();

			foreach ($items as $it) {
				$qty = max(1, (int)($it['qty'] ?? 1));
				$product = Resolver::resolveProductFromItem((array)$it);
				if (!$product) { continue; }
				$item = new \WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);

				$price    = (float) $product->get_price();
				$subtotal = $price * $qty;
				$total    = $subtotal;

				$exclude_gd = !empty($it['exclude_global_discount']);
				$item_pct   = isset($it['item_discount_percent']) ? (float) $it['item_discount_percent'] : null;

				if ($item_pct !== null && $item_pct >= 0) {
					$discounted = $price * (1 - ($item_pct / 100.0));
					$total = max(0.0, $discounted * $qty);
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
			$this->applyAddress($order, 'billing', array_merge($address, ['email' => $email]));
			$this->applyAddress($order, 'shipping', $address);
			// Coupons
			if (!empty($coupons) && is_array($coupons)) {
				foreach ($coupons as $code) {
					$code = wc_format_coupon_code((string)$code);
					if ($code !== '') { $order->apply_coupon($code); }
				}
			}
			// Shipping
			if (!empty($selected_rate) && isset($selected_rate['amount'])) {
				$ship = new WC_Order_Item_Shipping();
				$ship->set_method_title(isset($selected_rate['serviceName']) ? (string)$selected_rate['serviceName'] : 'Shipping');
				$ship->set_total((float)$selected_rate['amount']);
				$order->add_item($ship);
			}
			// First calculation to know products net (points cannot cover shipping/tax)
			$order->calculate_totals(false);
			
			// Load funnel config for discounts
			$fConfig = FunnelConfig::get($funnel_id);
			$global_percent = (float)$fConfig['global_discount_percent'];

			// Apply per-item overrides (exclude global, specific item discount) from config,
			// but only for items that don't already have explicit overrides from the payload.
			foreach ($order->get_items() as $item) {
				if (!$item instanceof \WC_Order_Item_Product) { continue; }

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
				if (!$item instanceof \WC_Order_Item_Product) { continue; }
				if ($item->get_meta('_eao_exclude_global_discount')) { continue; }
				$products_gross += (float)$item->get_subtotal();
			}

			$discount_total = (float)$order->get_discount_total();
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
			
			$all_products_subtotal = (float)$order->get_subtotal();
			$products_net = max(0.0, $all_products_subtotal - $discount_total - $global_discount);

      // Points as negative fee now; webhook will reconcile via YITH. Cap to products_net.
      $ps = new PointsService();
      $points_discount = 0.0;
      if ($points_to_redeem > 0 && $products_net > 0) {
        $points_discount = min($ps->pointsToMoney($points_to_redeem), $products_net);
        if ($points_discount > 0) {
          // Add as a negative fee item (correct API)
          $fee = new \WC_Order_Item_Fee();
          $fee->set_name('Points redemption (pending)');
          $fee->set_amount(-1 * $points_discount);
          $fee->set_total(-1 * $points_discount);
          $order->add_item($fee);
        }
      }
      // Final totals after points fee
      $order->calculate_totals(false);
      // Build amount manually from components
      $items_total_after_discounts = 0.0;
      foreach ($order->get_items() as $it) {
        if ($it instanceof \WC_Order_Item_Product) {
          $items_total_after_discounts += (float) $it->get_total();
        }
      }
      $fees_total = 0.0;
      foreach ($order->get_fees() as $fee) {
        $fees_total += (float) $fee->get_total();
      }
      $shipping_total = (float) $order->get_shipping_total();
      $grand_total = max(0.0, $items_total_after_discounts + $fees_total + $shipping_total);
		} finally {
			if ($order_id > 0) { wp_delete_post($order_id, true); }
		}

		// Create/reuse a Stripe customer so we can support one‑click bumps (off‑session charge)
		$name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
		$user = get_user_by('email', $email);
		$cus = $stripe->createOrGetCustomer($email, $name, $user ? (int)$user->ID : 0);
		if (!$cus) { return new WP_Error('stripe_customer', 'Could not create Stripe customer', ['status' => 502]); }

		// Create draft
		$draftStore = new OrderDraftStore();
		$draft_id = $draftStore->create([
			'funnel_id' => $funnel_id,
			'funnel_name' => $funnel_name,
			'customer' => ['email' => $email, 'name' => $name, 'user_id' => $user ? (int)$user->ID : 0],
			'shipping_address' => $address,
			'items' => $items,
			'coupon_codes' => $coupons,
			'selected_rate' => $selected_rate,
			'points_to_redeem' => $points_to_redeem,
			'analytics' => $analytics,
			'currency' => get_woocommerce_currency('USD') ?: 'USD',
			'amount' => $grand_total,
			'stripe_customer' => $cus,
		]);

		// Stripe PI
		$amount_cents = (int) round($grand_total * 100);
		if ($amount_cents <= 0) {
			return new WP_Error('bad_amount', 'Amount must be greater than zero', ['status' => 400]);
		}
		$params = [
			'amount' => $amount_cents,
			'currency' => strtolower(get_woocommerce_currency('USD') ?: 'usd'),
			'customer' => $cus,
			// Explicitly limit to card to hide Link/Bank
			'payment_method_types[]' => 'card',
			// Persist for one‑click off‑session bump
			'payment_method_options[card][setup_future_usage]' => 'off_session',
			// Helpful description in Stripe dashboard
			'description' => 'HolisticPeople - ' . $funnel_name,
			'metadata[order_draft_id]' => $draft_id,
			'metadata[funnel_id]' => $funnel_id,
			'metadata[funnel_name]' => $funnel_name,
		];
		$pi = $stripe->createPaymentIntent($params);
		if (!$pi || empty($pi['client_secret'])) {
			return new WP_Error('stripe_pi', 'Could not create PaymentIntent', ['status' => 502, 'debug' => $pi]);
		}

		return new WP_REST_Response([
			'client_secret' => (string)$pi['client_secret'],
			'publishable' => $stripe->publishable,
			'order_draft_id' => $draft_id,
			'amount_cents' => $amount_cents,
		]);
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


