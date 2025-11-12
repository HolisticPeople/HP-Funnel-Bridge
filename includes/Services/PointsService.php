<?php
namespace HP_FB\Services;

if (!defined('ABSPATH')) { exit; }

class PointsService {
	public function getCustomerPoints(int $userId): int {
		if ($userId <= 0) { return 0; }
		if (function_exists('eao_yith_get_customer_points')) {
			return (int) \eao_yith_get_customer_points($userId);
		}
		// Fallback YITH meta (best effort)
		$raw = get_user_meta($userId, '_ywpar_user_total_points', true);
		return is_numeric($raw) ? (int)$raw : 0;
	}

	public function pointsToMoney(int $points): float {
		// EAO default is 10 points per $1.00
		$pointsPerDollar = (int) apply_filters('eao_points_dollar_rate', 10);
		if ($pointsPerDollar <= 0) { $pointsPerDollar = 10; }
		return round($points / $pointsPerDollar, 2);
	}
}


