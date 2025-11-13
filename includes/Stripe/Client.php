<?php
namespace HP_FB\Stripe;

if (!defined('ABSPATH')) { exit; }

class Client {
	private string $secret;
	public string $publishable;
	public string $mode = 'test'; // 'test' or 'live'

	/**
	 * @param string|null $modeOverride 'test' | 'live' to force a mode (per-funnel), or null to derive from settings env
	 */
	public function __construct(?string $modeOverride = null) {
		$opts = get_option('hp_fb_settings', []);
		$env = isset($opts['env']) && $opts['env'] === 'production' ? 'production' : 'staging';
		$eao = get_option('eao_stripe_settings', []);
		$useMode = $modeOverride;
		if ($useMode !== 'test' && $useMode !== 'live') {
			// Derive from global environment
			if ($env === 'production') {
				$useMode = 'live';
			} else {
				$useMode = 'test';
			}
		}
		if ($useMode === 'test') {
			$this->secret = (string)($eao['test_secret'] ?? '');
			$this->publishable = (string)($eao['test_publishable'] ?? '');
			$this->mode = 'test';
			// If test keys missing, hard-fallback to live to avoid blocking, but record mode
			if ($this->secret === '' || $this->publishable === '') {
				$this->secret = (string)($eao['live_secret'] ?? '');
				$this->publishable = (string)($eao['live_publishable'] ?? '');
				$this->mode = 'live';
			}
		} else { // live
			$this->secret = (string)($eao['live_secret'] ?? '');
			$this->publishable = (string)($eao['live_publishable'] ?? '');
			$this->mode = 'live';
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
			$metaKeyCurrent = $this->mode === 'live' ? '_hp_fb_stripe_customer_id_live' : '_hp_fb_stripe_customer_id_test';
			$existing = get_user_meta($userId, $metaKeyCurrent, true);
			// Back-compat: if a legacy id exists without mode suffix, try it only when modes match pragmatically
			if (!$existing) {
				$legacy = get_user_meta($userId, '_hp_fb_stripe_customer_id', true);
				if (is_string($legacy) && $legacy !== '') {
					// Do NOT return legacy when running in test mode and legacy is likely a live id, or vice versa.
					// Instead, ignore legacy so a proper customer is created in the correct Stripe mode.
					if ($this->mode === 'live') {
						$existing = $legacy; // prefer legacy as live if present
					}
				}
			}
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
			$metaKeyCurrent = $this->mode === 'live' ? '_hp_fb_stripe_customer_id_live' : '_hp_fb_stripe_customer_id_test';
			update_user_meta($userId, $metaKeyCurrent, $cus);
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


