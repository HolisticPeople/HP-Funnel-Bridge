<?php
namespace HP_FB\Stripe;

if (!defined('ABSPATH')) { exit; }

class Client {
	private string $secret;
	public string $publishable;
	public string $mode = 'test'; // 'test' or 'live'

	public function __construct() {
		$opts = get_option('hp_fb_settings', []);
		$env = isset($opts['env']) && $opts['env'] === 'production' ? 'production' : 'staging';
		$eao = get_option('eao_stripe_settings', []);
		if ($env === 'production') {
			$this->secret = (string)($eao['live_secret'] ?? '');
			$this->publishable = (string)($eao['live_publishable'] ?? '');
			$this->mode = 'live';
		} else {
			// Prefer test keys, but if missing, fall back to live keys (user requested live on staging)
			$test_secret = (string)($eao['test_secret'] ?? '');
			$test_pub    = (string)($eao['test_publishable'] ?? '');
			if ($test_secret !== '' && $test_pub !== '') {
				$this->secret = $test_secret;
				$this->publishable = $test_pub;
				$this->mode = 'test';
			} else {
				$this->secret = (string)($eao['live_secret'] ?? '');
				$this->publishable = (string)($eao['live_publishable'] ?? '');
				$this->mode = 'live';
			}
		}
	}

	public function isConfigured(): bool {
		return $this->secret !== '' && $this->publishable !== '';
	}

	public function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret,
			'Content-Type' => 'application/x-www-form-urlencoded',
		];
	}

	public function createOrGetCustomer(string $email, string $name = '', int $userId = 0): ?string {
		// Reuse stored customer id if set
		if ($userId > 0) {
			$existing = get_user_meta($userId, '_hp_fb_stripe_customer_id', true);
			if (is_string($existing) && $existing !== '') {
				return $existing;
			}
		}
		$body = ['email' => $email];
		if ($name !== '') { $body['name'] = $name; }
		$resp = wp_remote_post('https://api.stripe.com/v1/customers', [
			'headers' => $this->headers(),
			'body'    => $body,
			'timeout' => 25,
		]);
		if (is_wp_error($resp)) { return null; }
		$data = json_decode(wp_remote_retrieve_body($resp), true);
		if (!is_array($data) || empty($data['id'])) { return null; }
		$cus = (string)$data['id'];
		if ($userId > 0) {
			update_user_meta($userId, '_hp_fb_stripe_customer_id', $cus);
		}
		return $cus;
	}

	public function createPaymentIntent(array $params): ?array {
		$resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
			'headers' => $this->headers(),
			'body'    => $params,
			'timeout' => 25,
		]);
		if (is_wp_error($resp)) { return null; }
		$data = json_decode(wp_remote_retrieve_body($resp), true);
		return is_array($data) ? $data : null;
	}

	public function retrievePaymentIntent(string $pi): ?array {
		$resp = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi), [
			'headers' => ['Authorization' => 'Bearer ' . $this->secret],
			'timeout' => 25,
		]);
		if (is_wp_error($resp)) { return null; }
		$data = json_decode(wp_remote_retrieve_body($resp), true);
		return is_array($data) ? $data : null;
	}
}


