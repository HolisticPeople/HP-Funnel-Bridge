<?php
namespace HP_FB\Gateway;

use HP_FB\Stripe\Client as StripeClient;

if (!defined('ABSPATH')) { exit; }

/**
 * Minimal WooCommerce gateway used only to enable refunds for Bridge orders.
 * This gateway is not exposed at checkout.
 */
class StripeGateway extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'hp_fb_stripe';
		$this->method_title       = __('HP Funnel Bridge (Stripe)', 'hp-funnel-bridge');
		$this->method_description = __('Used by HP Funnel Bridge to process refunds for Stripe charges.', 'hp-funnel-bridge');
		$this->has_fields         = false;
		$this->supports           = array('refunds');
		$this->enabled            = 'yes';
	}

	/**
	 * Hide from checkout.
	 */
	public function is_available() {
		// Never available at checkout.
		if (is_checkout()) {
			return false;
		}
		return true;
	}

	/**
	 * Process a refund via Stripe for the given order.
	 *
	 * @param int    $order_id
	 * @param float  $amount
	 * @param string $reason
	 * @return bool|\WP_Error
	 */
	public function process_refund($order_id, $amount = null, $reason = '') {
		$order = wc_get_order($order_id);
		if (!$order) {
			return new \WP_Error('hp_fb_refund_no_order', 'Order not found.');
		}

		$amount = is_null($amount) ? (float) $order->get_total() : (float) $amount;
		if ($amount <= 0) {
			return new \WP_Error('hp_fb_refund_amount', 'Invalid refund amount.');
		}

		// Retrieve Stripe identifiers stored on order by Bridge webhook.
		$pi_id  = (string) $order->get_meta('_hp_fb_stripe_payment_intent_id', true);
		$ch_id  = (string) $order->get_meta('_hp_fb_stripe_charge_id', true);

		$client = new StripeClient(); // will resolve test/live by site host/funnel mapping
		if (!$client->isConfigured()) {
			return new \WP_Error('hp_fb_refund_cfg', 'Stripe keys are not configured.');
		}

		$headers = $client->headers();
		$body    = array(
			'amount'   => (int) round($amount * 100),
			'reason'   => 'requested_by_customer',
			'metadata[order_id]' => $order_id,
		);

		if ($ch_id !== '') {
			$body['charge'] = $ch_id;
		} elseif ($pi_id !== '') {
			$body['payment_intent'] = $pi_id;
		} else {
			return new \WP_Error('hp_fb_refund_no_ids', 'No Stripe identifiers were stored on this order.');
		}

		$resp = wp_remote_post('https://api.stripe.com/v1/refunds', array(
			'headers' => $headers,
			'timeout' => 25,
			'body'    => $body,
		));

		if (is_wp_error($resp)) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode(wp_remote_retrieve_body($resp), true);

		if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['id'])) {
			$order->add_order_note(sprintf(
				'HP Funnel Bridge: Refunded $%s via Stripe (%s).',
				number_format($amount, 2),
				(string) $data['id']
			));
			return true;
		}

		$message = isset($data['error']['message']) ? (string) $data['error']['message'] : 'Stripe refund failed.';
		return new \WP_Error('hp_fb_refund_fail', $message);
	}
}


