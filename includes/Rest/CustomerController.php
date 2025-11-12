<?php
namespace HP_FB\Rest;

use HP_FB\Services\PointsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

class CustomerController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/customer', [
			'methods'  => 'POST',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
		]);
	}

	public function handle(WP_REST_Request $request) {
		$email = trim((string)$request->get_param('email'));
		if ($email === '' || !is_email($email)) {
			return new WP_Error('bad_request', 'Valid email required', ['status' => 400]);
		}
		$user = get_user_by('email', $email);
		$points = 0;
		$billing = [];
		$shipping = [];
		if ($user) {
			$uid = (int)$user->ID;
			$ps = new PointsService();
			$points = $ps->getCustomerPoints($uid);
			$billing = $this->getAddress($uid, 'billing');
			$shipping = $this->getAddress($uid, 'shipping');
		}
		return new WP_REST_Response([
			'user_id' => $user ? (int)$user->ID : 0,
			'default_billing' => $billing,
			'default_shipping' => $shipping,
			'points_balance' => $points,
		]);
	}

	private function getAddress(int $userId, string $type): array {
		$fields = ['first_name','last_name','company','address_1','address_2','city','state','postcode','country','phone','email'];
		$out = [];
		foreach ($fields as $f) {
			$metaKey = $type . '_' . $f;
			$out[$f] = (string) get_user_meta($userId, $metaKey, true);
		}
		return $out;
	}
}


