<?php
namespace HP_FB\Admin;

if (!defined('ABSPATH')) { exit; }

class SettingsPage {
	public static function registerMenu(): void {
		add_options_page(
			'HP Funnel Bridge',
			'HP Funnel Bridge',
			'manage_options',
			'hp-funnel-bridge',
			[__CLASS__, 'render']
		);
		add_action('admin_init', [__CLASS__, 'registerSettings']);
	}

	public static function registerSettings(): void {
		register_setting('hp_fb_settings_group', 'hp_fb_settings', [
			'type' => 'array',
			'sanitize_callback' => [__CLASS__, 'sanitize'],
			'default' => [
				'env' => 'staging',
				'allowed_origins' => [],
				'hmac_secret' => '',
				'funnel_registry' => [], // array of [id => name]
			],
		]);
		add_settings_section('hp_fb_main', 'General', '__return_false', 'hp-funnel-bridge');
		add_settings_field('hp_fb_env', 'Environment', [__CLASS__, 'fieldEnv'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_allowed_origins', 'Allowed Origins', [__CLASS__, 'fieldOrigins'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_hmac', 'HMAC Shared Secret (optional)', [__CLASS__, 'fieldHmac'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_registry', 'Funnel Registry', [__CLASS__, 'fieldRegistry'], 'hp-funnel-bridge', 'hp_fb_main');
	}

	public static function sanitize($value) {
		$out = is_array($value) ? $value : [];
		$out['env'] = isset($value['env']) && $value['env'] === 'production' ? 'production' : 'staging';
		$origins = [];
		if (!empty($value['allowed_origins'])) {
			if (is_array($value['allowed_origins'])) {
				foreach ($value['allowed_origins'] as $o) {
					$o = trim((string)$o);
					if ($o !== '') { $origins[] = $o; }
				}
			} else {
				foreach (explode(',', (string)$value['allowed_origins']) as $o) {
					$o = trim((string)$o);
					if ($o !== '') { $origins[] = $o; }
				}
			}
		}
		$out['allowed_origins'] = array_values(array_unique($origins));
		$out['hmac_secret'] = isset($value['hmac_secret']) ? trim((string)$value['hmac_secret']) : '';
		$registry = [];
		if (!empty($value['funnel_registry']) && is_array($value['funnel_registry'])) {
			foreach ($value['funnel_registry'] as $id => $name) {
				$id = sanitize_key((string)$id);
				$name = sanitize_text_field((string)$name);
				if ($id !== '' && $name !== '') {
					$registry[$id] = $name;
				}
			}
		}
		$out['funnel_registry'] = $registry;
		return $out;
	}

	public static function fieldEnv(): void {
		$opts = get_option('hp_fb_settings', []);
		$env = isset($opts['env']) ? (string)$opts['env'] : 'staging';
		?>
		<select name="hp_fb_settings[env]">
			<option value="staging" <?php selected($env, 'staging'); ?>>Staging (Stripe test keys)</option>
			<option value="production" <?php selected($env, 'production'); ?>>Production (Stripe live keys)</option>
		</select>
		<?php
	}

	public static function fieldOrigins(): void {
		$opts = get_option('hp_fb_settings', []);
		$origins = isset($opts['allowed_origins']) && is_array($opts['allowed_origins']) ? $opts['allowed_origins'] : [];
		?>
		<textarea name="hp_fb_settings[allowed_origins]" rows="3" cols="60" placeholder="https://funnel.example.com, https://staging-funnel.example.com"><?php echo esc_textarea(implode(', ', $origins)); ?></textarea>
		<p class="description">Comma-separated list of allowed origins for CORS.</p>
		<?php
	}

	public static function fieldHmac(): void {
		$opts = get_option('hp_fb_settings', []);
		$val = isset($opts['hmac_secret']) ? (string)$opts['hmac_secret'] : '';
		?>
		<input type="text" name="hp_fb_settings[hmac_secret]" value="<?php echo esc_attr($val); ?>" size="60" />
		<p class="description">Optional shared secret to validate requests (X-HPFB-HMAC). Leave blank to disable.</p>
		<?php
	}

	public static function fieldRegistry(): void {
		$opts = get_option('hp_fb_settings', []);
		$reg = isset($opts['funnel_registry']) && is_array($opts['funnel_registry']) ? $opts['funnel_registry'] : [];
		?>
		<table class="widefat" style="max-width:760px;">
			<thead><tr><th style="width:30%;">Funnel ID</th><th>Funnel Name</th></tr></thead>
			<tbody id="hp-fb-registry-rows">
				<?php if (empty($reg)) : ?>
					<tr><td><input type="text" name="hp_fb_settings[funnel_registry][default]" value="default" /></td><td><input type="text" name="hp_fb_settings[funnel_registry_names][default]" value="Default Funnel" disabled /></td></tr>
				<?php else : foreach ($reg as $id => $name) : ?>
					<tr><td><input type="text" name="hp_fb_settings[funnel_registry][<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr($id); ?>" /></td><td><input type="text" value="<?php echo esc_attr($name); ?>" disabled /></td></tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p class="description">Enter IDs only; names can be stored in analytics meta by the calling funnel.</p>
		<?php
	}

	public static function render(): void {
		?>
		<div class="wrap">
			<h1>HP Funnel Bridge</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('hp_fb_settings_group');
				do_settings_sections('hp-funnel-bridge');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}


