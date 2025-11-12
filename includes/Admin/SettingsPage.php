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
				'funnel_registry' => [], // legacy: array of [id => name]
				'funnels' => [], // new: array of [id, name, origin_staging, origin_production]
			],
		]);
		add_settings_section('hp_fb_main', 'General', '__return_false', 'hp-funnel-bridge');
		add_settings_field('hp_fb_env', 'Environment', [__CLASS__, 'fieldEnv'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_allowed_origins', 'Allowed Origins', [__CLASS__, 'fieldOrigins'], 'hp-funnel-bridge', 'hp_fb_main');
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
		// Legacy registry (id => name)
		$legacy = [];
		if (!empty($value['funnel_registry']) && is_array($value['funnel_registry'])) {
            foreach ($value['funnel_registry'] as $id => $name) {
                $id = sanitize_key((string)$id);
                $name = sanitize_text_field((string)$name);
                if ($id !== '' && $name !== '') { $legacy[$id] = $name; }
            }
        }
        $out['funnel_registry'] = $legacy;
		// New funnels structure
		$funnels = [];
		if (!empty($value['funnels']) && is_array($value['funnels'])) {
			foreach ($value['funnels'] as $row) {
				if (!is_array($row)) { continue; }
				$id = isset($row['id']) ? sanitize_key((string)$row['id']) : '';
				$name = isset($row['name']) ? sanitize_text_field((string)$row['name']) : '';
				$origStg = isset($row['origin_staging']) ? trim((string)$row['origin_staging']) : '';
				$origProd = isset($row['origin_production']) ? trim((string)$row['origin_production']) : '';
				if ($id === '' || $name === '') { continue; }
				$funnels[] = [
					'id' => $id,
					'name' => $name,
					'origin_staging' => $origStg,
					'origin_production' => $origProd,
				];
			}
		}
		$out['funnels'] = $funnels;
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
		$rows = [];
		// Prefer new 'funnels' structure
		if (!empty($opts['funnels']) && is_array($opts['funnels'])) {
			foreach ($opts['funnels'] as $f) {
				$rows[] = [
					'id' => (string)($f['id'] ?? ''),
					'name' => (string)($f['name'] ?? ''),
					'origin_staging' => (string)($f['origin_staging'] ?? ''),
					'origin_production' => (string)($f['origin_production'] ?? ''),
				];
			}
		} else {
			// Back-compat: transform legacy registry to editable rows
			$reg = isset($opts['funnel_registry']) && is_array($opts['funnel_registry']) ? $opts['funnel_registry'] : [];
			foreach ($reg as $id => $name) {
				$rows[] = ['id' => (string)$id, 'name' => (string)$name, 'origin_staging' => '', 'origin_production' => ''];
			}
		}
		// Add one empty row for quick additions
		$rows[] = ['id' => '', 'name' => '', 'origin_staging' => '', 'origin_production' => ''];
		?>
		<table class="widefat" style="max-width:960px;">
			<thead><tr><th style="width:16%;">Funnel ID</th><th style="width:24%;">Funnel Name</th><th style="width:30%;">Staging Origin</th><th>Production Origin</th><th style="width:90px;">Actions</th></tr></thead>
			<tbody id="hp-fb-registry-rows">
				<?php foreach ($rows as $i => $r): ?>
					<tr>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][id]" value="<?php echo esc_attr($r['id']); ?>" /></td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][name]" value="<?php echo esc_attr($r['name']); ?>" /></td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][origin_staging]" value="<?php echo esc_attr($r['origin_staging']); ?>" placeholder="https://staging.example.com" /></td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][origin_production]" value="<?php echo esc_attr($r['origin_production']); ?>" placeholder="https://www.example.com" /></td>
						<td><button type="button" class="button button-secondary hp-fb-del-row">Delete</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Registry rows: ID and Name are required; add origins per environment. Global “Allowed Origins” above is best reserved for localhost or special cases.</p>
		<p><button type="button" class="button" id="hp-fb-add-funnel">Add Funnel</button></p>
		<script>
		(function(){
			const tbody = document.getElementById('hp-fb-registry-rows');
			const addBtn = document.getElementById('hp-fb-add-funnel');
			function nextIndex() { return tbody.querySelectorAll('tr').length; }
			function tpl(i){
				return '<tr>' +
					'<td><input type="text" name="hp_fb_settings[funnels]['+i+'][id]" value="" /></td>' +
					'<td><input type="text" name="hp_fb_settings[funnels]['+i+'][name]" value="" /></td>' +
					'<td><input type="text" name="hp_fb_settings[funnels]['+i+'][origin_staging]" value="" placeholder="https://staging.example.com" /></td>' +
					'<td><input type="text" name="hp_fb_settings[funnels]['+i+'][origin_production]" value="" placeholder="https://www.example.com" /></td>' +
					'<td><button type="button" class="button button-secondary hp-fb-del-row">Delete</button></td>' +
				'</tr>';
			}
			if (addBtn) addBtn.addEventListener('click', function(){ tbody.insertAdjacentHTML('beforeend', tpl(nextIndex())); });
			tbody.addEventListener('click', function(e){
				if (e.target && e.target.classList.contains('hp-fb-del-row')) {
					const tr = e.target.closest('tr'); if (tr) tr.remove();
				}
			});
		})();
		</script>
		<?php
	}

	public static function render(): void {
		?>
		<div class="wrap">
			<h1>HP Funnel Bridge</h1>
			<p style="margin: -6px 0 14px; color:#666;">Version <?php echo esc_html( defined('HP_FB_PLUGIN_VERSION') ? HP_FB_PLUGIN_VERSION : '' ); ?></p>
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


