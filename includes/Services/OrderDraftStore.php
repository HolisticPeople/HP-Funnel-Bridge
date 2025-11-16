<?php
namespace HP_FB\Services;

if (!defined('ABSPATH')) { exit; }

class OrderDraftStore {
	private const TRANSIENT_PREFIX = 'hp_fb_draft_';
	private const TTL = 2 * HOUR_IN_SECONDS;

	public function create(array $payload): string {
		$id = uniqid('hpfb_', true);
		set_transient(self::TRANSIENT_PREFIX . $id, wp_json_encode($payload), self::TTL);
		return $id;
	}

	public function get(string $id): ?array {
		$raw = get_transient(self::TRANSIENT_PREFIX . $id);
		if (!$raw) { return null; }
		$data = json_decode((string)$raw, true);
		return is_array($data) ? $data : null;
	}

	public function delete(string $id): void {
		delete_transient(self::TRANSIENT_PREFIX . $id);
	}
}


