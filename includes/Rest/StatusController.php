<?php
namespace HP_FB\Rest;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) { exit; }

class StatusController {
	public function register_routes(): void {
		register_rest_route('hp-funnel/v1', '/status', [
			'methods'  => 'GET',
			'callback' => [$this, 'handle'],
			'permission_callback' => '__return_true',
			'args' => [
				'funnel_id' => ['type' => 'string', 'required' => false],
			],
		]);
	}

	public function handle(WP_REST_Request $request) {
		$fid = (string) ($request->get_param('funnel_id') ?? '');
		$opts = get_option('hp_fb_settings', []);
		$env = 'unknown';
		$mode = 'unknown';

		if (!empty($opts['funnels']) && is_array($opts['funnels']) && $fid !== '') {
			$currentHost = parse_url(home_url(), PHP_URL_HOST);
			foreach ($opts['funnels'] as $f) {
				if (!is_array($f) || empty($f['id']) || (string)$f['id'] !== $fid) { continue; }
				$stgHost = !empty($f['origin_staging']) ? parse_url((string)$f['origin_staging'], PHP_URL_HOST) : null;
				$prodHost = !empty($f['origin_production']) ? parse_url((string)$f['origin_production'], PHP_URL_HOST) : null;
				if ($currentHost && $stgHost && $currentHost === $stgHost) {
					$env  = 'staging';
					$mode = (string) ($f['mode_staging'] ?? 'test');
				} elseif ($currentHost && $prodHost && $currentHost === $prodHost) {
					$env  = 'production';
					$mode = (string) ($f['mode_production'] ?? 'live');
				} else {
					$env  = 'staging';
					$mode = (string) ($f['mode_staging'] ?? 'test');
				}
				break;
			}
		}

		return new WP_REST_Response([
			'ok'            => true,
			'funnel_id'     => $fid,
			'environment'   => $env,
			'mode'          => $mode,
			'redirect_url'  => home_url('/'),
		]);
	}
}


