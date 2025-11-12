<?php
namespace HP_FB\Rest;

use HP_FB\Services\OrderDraftStore;
use HP_FB\Services\PointsService;
use HP_FB\Stripe\Client as StripeClient;
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
		$stripe = new StripeClient();
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
				$product_id = isset($it['product_id']) ? (int)$it['product_id'] : 0;
				$variation_id = isset($it['variation_id']) ? (int)$it['variation_id'] : 0;
				$qty = max(1, (int)($it['qty'] ?? 1));
				if ($product_id <= 0) { continue; }
				$product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
				if (!$product) { continue; }
				$item = new \WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($qty);
				$item->set_subtotal($product->get_price() * $qty);
				$item->set_total($product->get_price() * $qty);
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
      $products_gross = (float)$order->get_subtotal();
      $discount_total = (float)$order->get_discount_total();
      $products_net = max(0.0, $products_gross - $discount_total);

      // Points as negative fee now; webhook will reconcile via YITH. Cap to products_net.
      $ps = new PointsService();
      $points_discount = 0.0;
      if ($points_to_redeem > 0 && $products_net > 0) {
        $points_discount = min($ps->pointsToMoney($points_to_redeem), $products_net);
        if ($points_discount > 0) {
          $order->add_fee('Points redemption (pending)', -1 * $points_discount);
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

		// Stripe customer
		$name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
		$user = get_user_by('email', $email);
		$cus = $stripe->createOrGetCustomer($email, $name, $user ? (int)$user->ID : 0);
		if (!$cus) {
			return new WP_Error('stripe_customer', 'Could not create Stripe customer', ['status' => 502]);
		}

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
			'automatic_payment_methods[enabled]' => 'true',
			'setup_future_usage' => 'off_session',
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


