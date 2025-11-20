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
		// Load color picker assets on our settings page (for per-funnel payment style)
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
	}

	public static function enqueueAssets($hook): void {
		// Only enqueue on our settings page
		if ($hook !== 'settings_page_hp-funnel-bridge') {
			return;
		}
		// Color pickers for hosted payment style
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('wp-color-picker');
		// Media library for favicon picker
		if (function_exists('wp_enqueue_media')) {
			wp_enqueue_media();
		}
		// Tiny init script for our color fields
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){ $(".hp-fb-color").wpColorPicker(); });'
		);
	}

	public static function registerSettings(): void {
		register_setting('hp_fb_settings_group', 'hp_fb_settings', [
			'type' => 'array',
			'sanitize_callback' => [__CLASS__, 'sanitize'],
			'default' => [
				'env' => 'staging',
				'allowed_origins' => [],
				'funnel_registry' => [], // legacy: array of [id => name]
				'funnels' => [], // new: array of [id, name, origin_staging, origin_production, mode_staging, mode_production]
				'webhook_secret_test' => '',
				'webhook_secret_live' => '',
			],
		]);
		add_settings_section('hp_fb_main', 'General', '__return_false', 'hp-funnel-bridge');
		// Removed global Environment selector; per-funnel modes are used instead.
		add_settings_field('hp_fb_allowed_origins', 'Allowed Origins', [__CLASS__, 'fieldOrigins'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_registry', 'Funnel Registry', [__CLASS__, 'fieldRegistry'], 'hp-funnel-bridge', 'hp_fb_main');
		add_settings_field('hp_fb_webhook_secrets', 'Stripe Webhook Signing Secrets', [__CLASS__, 'fieldWebhookSecrets'], 'hp-funnel-bridge', 'hp_fb_main');
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
				$ms = isset($row['mode_staging']) ? strtolower(trim((string)$row['mode_staging'])) : '';
				$mp = isset($row['mode_production']) ? strtolower(trim((string)$row['mode_production'])) : '';
				$modeStg  = in_array($ms,  ['test','live','off'], true)  ? $ms : 'test';
				$modeProd = in_array($mp,  ['test','live','off'], true)  ? $mp : 'live';
				if ($id === '' || $name === '') { continue; }
				$funnels[] = [
					'id' => $id,
					'name' => $name,
					'origin_staging' => $origStg,
					'origin_production' => $origProd,
					'mode_staging' => $modeStg,
					'mode_production' => $modeProd,
				];
			}
		}
		$out['funnels'] = $funnels;
		// Preserve and lightly sanitize per-funnel configs (used by the Funnel Config UI).
		// This structure is not edited via the main settings form, but when the user
		// saves global settings we don't want to lose previously stored configs.
		$funnel_configs = [];
		if (!empty($value['funnel_configs']) && is_array($value['funnel_configs'])) {
			foreach ($value['funnel_configs'] as $fid => $cfg) {
				$fid_key = sanitize_key((string)$fid);
				if ($fid_key === '' || !is_array($cfg)) { continue; }
				// Shallow sanitize; detailed validation happens in the AJAX save handler.
				$row = [];
				$row['global_discount_percent'] = isset($cfg['global_discount_percent']) ? (float)$cfg['global_discount_percent'] : 0.0;
				$row['products'] = [];
				if (!empty($cfg['products']) && is_array($cfg['products'])) {
					foreach ($cfg['products'] as $p) {
						if (!is_array($p)) { continue; }
						$pid = isset($p['product_id']) ? (int)$p['product_id'] : 0;
						if ($pid <= 0) { continue; }
						$role = isset($p['role']) ? (string)$p['role'] : 'base';
						if (!in_array($role, ['base','optional','upsell'], true)) { $role = 'base'; }
						$row['products'][] = [
							'product_id' => $pid,
							'sku' => isset($p['sku']) ? sanitize_text_field((string)$p['sku']) : '',
							'role' => $role,
							'exclude_global_discount' => !empty($p['exclude_global_discount']) ? 1 : 0,
							'item_discount_percent' => isset($p['item_discount_percent']) ? (float)$p['item_discount_percent'] : 0.0,
						];
					}
				}
				// Preserve simple payment_style colors if present
				$row['payment_style'] = [];
				if (!empty($cfg['payment_style']) && is_array($cfg['payment_style'])) {
					$ps = $cfg['payment_style'];
					if (!empty($ps['background_color'])) {
						$row['payment_style']['background_color'] = sanitize_hex_color((string)$ps['background_color']);
					}
					if (!empty($ps['card_color'])) {
						$row['payment_style']['card_color'] = sanitize_hex_color((string)$ps['card_color']);
					}
					if (!empty($ps['accent_color'])) {
						$row['payment_style']['accent_color'] = sanitize_hex_color((string)$ps['accent_color']);
					}
				}
				// Preserve optional branding (favicon + social text)
				$row['branding'] = [];
				if (!empty($cfg['branding']) && is_array($cfg['branding'])) {
					$br = $cfg['branding'];
					if (!empty($br['favicon_id'])) {
						$row['branding']['favicon_id'] = (int) $br['favicon_id'];
					}
					if (isset($br['social_text'])) {
						$row['branding']['social_text'] = sanitize_text_field((string) $br['social_text']);
					}
				}
				$funnel_configs[$fid_key] = $row;
			}
		}
		$out['funnel_configs'] = $funnel_configs;
		// Webhook secrets (do not trim to avoid accidental spaces removal on paste? we will trim)
		$out['webhook_secret_test'] = isset($value['webhook_secret_test']) ? trim((string)$value['webhook_secret_test']) : '';
		$out['webhook_secret_live'] = isset($value['webhook_secret_live']) ? trim((string)$value['webhook_secret_live']) : '';
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
					'mode_staging' => (string)($f['mode_staging'] ?? 'test'),
					'mode_production' => (string)($f['mode_production'] ?? 'live'),
				];
			}
		} else {
			// Back-compat: transform legacy registry to editable rows
			$reg = isset($opts['funnel_registry']) && is_array($opts['funnel_registry']) ? $opts['funnel_registry'] : [];
			foreach ($reg as $id => $name) {
				$rows[] = ['id' => (string)$id, 'name' => (string)$name, 'origin_staging' => '', 'origin_production' => '', 'mode_staging' => 'test', 'mode_production' => 'live'];
			}
		}
		// Add one empty row for quick additions
		$rows[] = ['id' => '', 'name' => '', 'origin_staging' => '', 'origin_production' => ''];
		?>
		<style>
			/* Compact layout */
			table.hp-fb-table.widefat td, table.hp-fb-table.widefat th { padding:6px 8px; }
			table.hp-fb-table.widefat input[type="text"], table.hp-fb-table.widefat select { width:100%; margin:0; }
		</style>
		<table class="widefat hp-fb-table" style="max-width:100%; table-layout:fixed;">
			<thead><tr><th style="width:12%;">Funnel ID</th><th style="width:18%;">Funnel Name</th><th style="width:24%;">Staging Origin</th><th style="width:10%;">Staging Mode</th><th style="width:24%;">Production Origin</th><th style="width:10%;">Production Mode</th><th style="width:110px;">Actions</th></tr></thead>
			<tbody id="hp-fb-registry-rows">
				<?php foreach ($rows as $i => $r): ?>
					<tr>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][id]" value="<?php echo esc_attr($r['id']); ?>" /></td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][name]" value="<?php echo esc_attr($r['name']); ?>" /></td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][origin_staging]" value="<?php echo esc_attr($r['origin_staging']); ?>" placeholder="https://staging.example.com" /></td>
						<td>
							<select name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][mode_staging]" data-current="<?php echo esc_attr($r['mode_staging'] ?? 'test'); ?>">
								<option value="test" <?php selected(($r['mode_staging'] ?? 'test'), 'test'); ?>>Test</option>
								<option value="live" <?php selected(($r['mode_staging'] ?? 'test'), 'live'); ?>>Live</option>
								<option value="off"  <?php selected(($r['mode_staging'] ?? 'test'), 'off');  ?>>Off</option>
							</select>
						</td>
						<td><input type="text" name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][origin_production]" value="<?php echo esc_attr($r['origin_production']); ?>" placeholder="https://www.example.com" /></td>
						<td>
							<select name="hp_fb_settings[funnels][<?php echo esc_attr((string)$i); ?>][mode_production]" data-current="<?php echo esc_attr($r['mode_production'] ?? 'live'); ?>">
								<option value="live" <?php selected(($r['mode_production'] ?? 'live'), 'live'); ?>>Live</option>
								<option value="test" <?php selected(($r['mode_production'] ?? 'live'), 'test'); ?>>Test</option>
								<option value="off"  <?php selected(($r['mode_production'] ?? 'live'), 'off');  ?>>Off</option>
							</select>
						</td>
						<td>
							<?php if (!empty($r['id'])): ?>
								<a href="<?php echo esc_url( admin_url('options-general.php?page=hp-funnel-bridge&funnel_id=' . urlencode($r['id'])) ); ?>" class="button button-small" title="Configure funnel products and discounts">⚙</a>
							<?php endif; ?>
							<button type="button" class="button button-secondary button-small hp-fb-del-row">Delete</button>
						</td>
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
					'<td><select name="hp_fb_settings[funnels]['+i+'][mode_staging]"><option value="test" selected>Test</option><option value="live">Live</option><option value="off">Off</option></select></td>' +
					'<td><input type="text" name="hp_fb_settings[funnels]['+i+'][origin_production]" value="" placeholder="https://www.example.com" /></td>' +
					'<td><select name="hp_fb_settings[funnels]['+i+'][mode_production]"><option value="live" selected>Live</option><option value="test">Test</option><option value="off">Off</option></select></td>' +
					'<td><span class="button button-small disabled" style="opacity:.5;cursor:default;">⚙</span> <button type="button" class="button button-secondary button-small hp-fb-del-row">Delete</button></td>' +
				'</tr>';
			}
			if (addBtn) addBtn.addEventListener('click', function(){ tbody.insertAdjacentHTML('beforeend', tpl(nextIndex())); });
			tbody.addEventListener('click', function(e){
				if (e.target && e.target.classList.contains('hp-fb-del-row')) {
					const tr = e.target.closest('tr'); if (tr) tr.remove();
				}
			});
			// Ensure selects reflect saved values even if other admin scripts alter them
			document.addEventListener('DOMContentLoaded', function(){
				tbody.querySelectorAll('select[name$="[mode_staging]"], select[name$="[mode_production]"]').forEach(function(sel){
					var cur = sel.getAttribute('data-current');
					if (cur) { sel.value = cur; }
				});
			});
		})();
		</script>
		<?php
	}

	public static function fieldWebhookSecrets(): void {
		$opts = get_option('hp_fb_settings', []);
		$test = isset($opts['webhook_secret_test']) ? (string)$opts['webhook_secret_test'] : '';
		$live = isset($opts['webhook_secret_live']) ? (string)$opts['webhook_secret_live'] : '';
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Test signing secret</th>
				<td>
					<input type="text" name="hp_fb_settings[webhook_secret_test]" value="<?php echo esc_attr($test); ?>" size="60" autocomplete="off" />
					<p class="description">From Stripe → Developers → Webhooks → switch to <strong>Test mode</strong>, open your <em>staging</em> Bridge endpoint, then click “Reveal signing secret”.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Live signing secret</th>
				<td>
					<input type="text" name="hp_fb_settings[webhook_secret_live]" value="<?php echo esc_attr($live); ?>" size="60" autocomplete="off" />
					<p class="description">From Stripe → Developers → Webhooks → switch to <strong>Live mode</strong>, open your <em>production</em> Bridge endpoint, then click “Reveal signing secret”.</p>
				</td>
			</tr>
		</table>
		<p class="description">
			<strong>Note:</strong> Stripe keeps <em>separate</em> webhook endpoints and signing secrets for Test and Live modes.
			If you periodically clone Production to Staging, store <strong>both</strong> secrets here so the Bridge can verify
			events in either environment without breaking after a clone. The Bridge will accept a signature that matches
			<em>either</em> secret.
		</p>
		<?php
	}

	public static function render(): void {
		$funnel_id = isset($_GET['funnel_id']) ? sanitize_key((string)$_GET['funnel_id']) : '';
		if ($funnel_id !== '') {
			self::renderFunnelConfig($funnel_id);
			return;
		}
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

	/**
	 * Render per-funnel configuration page (products, roles, discounts).
	 */
	private static function renderFunnelConfig(string $funnel_id): void {
		$opts = get_option('hp_fb_settings', []);
		$funnels = isset($opts['funnels']) && is_array($opts['funnels']) ? $opts['funnels'] : [];
		$cfgs = isset($opts['funnel_configs']) && is_array($opts['funnel_configs']) ? $opts['funnel_configs'] : [];
		$funnel = null;
		foreach ($funnels as $f) {
			if (!is_array($f)) { continue; }
			if (!empty($f['id']) && (string)$f['id'] === $funnel_id) {
				$funnel = $f;
				break;
			}
		}
		if (!$funnel) {
			?>
			<div class="wrap">
				<h1>HP Funnel Bridge</h1>
				<p style="margin: -6px 0 14px; color:#666;">Version <?php echo esc_html( defined('HP_FB_PLUGIN_VERSION') ? HP_FB_PLUGIN_VERSION : '' ); ?></p>
				<p><?php esc_html_e('Funnel not found. Please go back to the main settings page and ensure the funnel ID exists.', 'hp-funnel-bridge'); ?></p>
				<p><a href="<?php echo esc_url( admin_url('options-general.php?page=hp-funnel-bridge') ); ?>" class="button">&larr; Back to Funnel Registry</a></p>
			</div>
			<?php
			return;
		}
		$name = isset($funnel['name']) ? (string)$funnel['name'] : $funnel_id;
		$config = isset($cfgs[$funnel_id]) && is_array($cfgs[$funnel_id]) ? $cfgs[$funnel_id] : [];
		$global_disc = isset($config['global_discount_percent']) ? (float)$config['global_discount_percent'] : 0.0;
		$style_cfg = isset($config['payment_style']) && is_array($config['payment_style']) ? $config['payment_style'] : [];
		$style_accent = isset($style_cfg['accent_color']) ? (string)$style_cfg['accent_color'] : '#eab308';
		$style_bg     = isset($style_cfg['background_color']) ? (string)$style_cfg['background_color'] : '#020617';
		$style_card   = isset($style_cfg['card_color']) ? (string)$style_cfg['card_color'] : '#0f172a';
		$branding_cfg = isset($config['branding']) && is_array($config['branding']) ? $config['branding'] : [];
		$favicon_id   = isset($branding_cfg['favicon_id']) ? (int) $branding_cfg['favicon_id'] : 0;
		$favicon_url  = $favicon_id > 0 ? wp_get_attachment_image_url($favicon_id, 'thumbnail') : '';
		$social_text  = isset($branding_cfg['social_text']) ? (string) $branding_cfg['social_text'] : '';
		$products_cfg = isset($config['products']) && is_array($config['products']) ? $config['products'] : [];
		// Preload product data for existing rows.
		$rows = [];
		if (!empty($products_cfg)) {
			foreach ($products_cfg as $row) {
				$pid = isset($row['product_id']) ? (int)$row['product_id'] : 0;
				if ($pid <= 0) { continue; }
				$product = wc_get_product($pid);
				if (!$product) { continue; }
				$img = '';
				if (method_exists($product, 'get_image_id')) {
					$img_id = $product->get_image_id();
					if ($img_id) {
						$url = wp_get_attachment_image_url($img_id, 'thumbnail');
						if ($url) { $img = $url; }
					}
				}
				$sku = isset($row['sku']) && $row['sku'] !== '' ? (string)$row['sku'] : (string)$product->get_sku();
				$price = (float)$product->get_regular_price();
				$role = isset($row['role']) ? (string)$row['role'] : 'base';
				if (!in_array($role, ['base','optional','upsell'], true)) { $role = 'base'; }
				$exclude = !empty($row['exclude_global_discount']) ? 1 : 0;
				$item_disc = isset($row['item_discount_percent']) ? (float)$row['item_discount_percent'] : 0.0;
				$rows[] = [
					'product_id' => $pid,
					'name' => $product->get_name(),
					'sku' => $sku,
					'image' => $img,
					'price' => $price,
					'role' => $role,
					'exclude_global_discount' => $exclude,
					'item_discount_percent' => $item_disc,
				];
			}
		}
		$nonce = wp_create_nonce('hp_fb_funnel_config_' . $funnel_id);
		?>
		<div class="wrap hp-fb-funnel-config">
			<h1>HP Funnel: <?php echo esc_html($name); ?></h1>
			<p style="margin: -6px 0 4px; color:#666;">Funnel ID: <code><?php echo esc_html($funnel_id); ?></code></p>
			<p style="margin: 0 0 10px; color:#666;">Bridge version <?php echo esc_html( defined('HP_FB_PLUGIN_VERSION') ? HP_FB_PLUGIN_VERSION : '' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url('options-general.php?page=hp-funnel-bridge') ); ?>" class="button">&larr; Back to Funnel Registry</a></p>

			<hr />

			<h2>Discounts &amp; Products</h2>
			<p class="description">Configure the products this funnel touches, their roles (Base / Optional / Upsell), and any per-product discount overrides.</p>

			<table class="form-table">
				<tr>
					<th scope="row">Global product discount (%)</th>
					<td>
						<input type="number" step="0.1" min="0" max="100" id="hp-fb-global-discount" value="<?php echo esc_attr($global_disc); ?>" />
						<p class="description">Optional global discount applied to non-excluded products for this funnel. Leave at 0 for no global discount.</p>
					</td>
				</tr>
			</table>

			<h3>Hosted payment page style</h3>
			<p class="description">Customize the hosted Stripe payment step for this funnel. These colors affect the background, card, and primary button around the Payment Element.</p>
			<table class="form-table">
				<tr>
					<th scope="row">Background color</th>
					<td>
						<input type="text" id="hp-fb-pay-bg" value="<?php echo esc_attr($style_bg); ?>" class="regular-text hp-fb-color" />
						<p class="description">Main page background (hex), e.g. <code>#020617</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Card color</th>
					<td>
						<input type="text" id="hp-fb-pay-card" value="<?php echo esc_attr($style_card); ?>" class="regular-text hp-fb-color" />
						<p class="description">Payment card background (hex), e.g. <code>#0f172a</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Accent color</th>
					<td>
						<input type="text" id="hp-fb-pay-accent" value="<?php echo esc_attr($style_accent); ?>" class="regular-text hp-fb-color" />
						<p class="description">Accent / button color (hex), e.g. <code>#eab308</code>.</p>
					</td>
				</tr>
			</table>

			<h3>Branding &amp; Social</h3>
			<p class="description">Optional per-funnel branding used by hosted pages and funnels (favicon and default social share text).</p>
			<table class="form-table">
				<tr>
					<th scope="row">Favicon</th>
					<td>
						<input type="hidden" id="hp-fb-favicon-id" value="<?php echo esc_attr($favicon_id); ?>" />
						<button type="button" class="button" id="hp-fb-favicon-btn">Choose favicon</button>
						<span id="hp-fb-favicon-preview" style="margin-left:10px; vertical-align:middle;">
							<?php if ($favicon_id && $favicon_url): ?>
								<img src="<?php echo esc_url($favicon_url); ?>" alt="" style="width:32px;height:32px;object-fit:contain;border-radius:4px;border:1px solid #ccd0d4;" />
							<?php else: ?>
								<span class="description">No favicon selected.</span>
							<?php endif; ?>
						</span>
						<p class="description">Small square icon used for browser tab / social previews. Recommended 32x32px PNG.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Social share text</th>
					<td>
						<textarea id="hp-fb-social-text" rows="2" cols="60" class="large-text"><?php echo esc_textarea($social_text); ?></textarea>
						<p class="description">Optional default text for social network share links (e.g., Facebook/Twitter). Funnels can read this via the Bridge config.</p>
					</td>
				</tr>
			</table>

			<h3>Products</h3>
			<p>
				<label for="hp-fb-product-search">Search products by name or SKU:</label><br />
				<input type="search" id="hp-fb-product-search" style="min-width:260px;" placeholder="Type at least 3 characters to search&hellip;" />
			</p>
			<div id="hp-fb-product-search-results" style="margin-bottom:12px;"></div>

			<table class="widefat striped" id="hp-fb-funnel-products-table">
				<thead>
					<tr>
						<th style="width:40px;">Image</th>
						<th>Name</th>
						<th style="width:110px;">SKU</th>
						<th style="width:80px; text-align:center;">Role</th>
						<th style="width:80px; text-align:center;">Exclude GD</th>
						<th style="width:90px; text-align:right;">Price</th>
						<th style="width:110px; text-align:center;">Discount %</th>
						<th style="width:110px; text-align:right;">Discounted</th>
						<th style="width:60px;">Actions</th>
					</tr>
				</thead>
				<tbody id="hp-fb-funnel-products-body">
				</tbody>
			</table>

			<p>
				<button type="button" class="button button-primary" id="hp-fb-funnel-save">Save Funnel Config</button>
				<span id="hp-fb-funnel-save-status" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		(function(){
			const funnelId = <?php echo wp_json_encode($funnel_id); ?>;
			const nonce = <?php echo wp_json_encode($nonce); ?>;
			const initialRows = <?php echo wp_json_encode($rows); ?>;
			const body = document.getElementById('hp-fb-funnel-products-body');
			const statusEl = document.getElementById('hp-fb-funnel-save-status');
			const faviconIdInput = document.getElementById('hp-fb-favicon-id');
			const faviconPreview = document.getElementById('hp-fb-favicon-preview');
			const faviconBtn = document.getElementById('hp-fb-favicon-btn');
			let faviconFrame = null;

			function fmt(v){ return (typeof v === 'number' && isFinite(v)) ? v.toFixed(2) : ''; }

			// Favicon media picker
			if (faviconBtn && window.wp && window.wp.media) {
				faviconBtn.addEventListener('click', function(e){
					e.preventDefault();
					if (faviconFrame) {
						faviconFrame.open();
						return;
					}
					faviconFrame = wp.media({
						title: 'Select favicon',
						button: { text: 'Use this icon' },
						multiple: false,
						library: { type: 'image' }
					});
					faviconFrame.on('select', function(){
						const attachment = faviconFrame.state().get('selection').first().toJSON();
						if (!attachment) return;
						if (faviconIdInput) {
							faviconIdInput.value = attachment.id || '';
						}
						if (faviconPreview) {
							const thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
							faviconPreview.innerHTML = '<img src="'+ String(thumb || '') +'" alt="" style="width:32px;height:32px;object-fit:contain;border-radius:4px;border:1px solid #ccd0d4;" />';
						}
					});
					faviconFrame.open();
				});
			}

			function syncRowUI(row, tr){
				if (!tr || !row) return;
				const globalInput = document.getElementById('hp-fb-global-discount');
				const globalDisc = parseFloat(globalInput && globalInput.value ? globalInput.value : '0') || 0;
				const excludeCbx = tr.querySelector('.hp-fb-exclude-gd');
				const isExcluded = !!(excludeCbx && excludeCbx.checked);
				const discInput = tr.querySelector('.hp-fb-item-disc');
				const discSpan = tr.querySelector('.hp-fb-disc-display');
				const priceSpan = tr.querySelector('.hp-fb-disc-price-display');
				const discPriceInput = tr.querySelector('.hp-fb-discounted-input');
				let percent = isExcluded ? (parseFloat(row.item_discount_percent || 0) || 0) : globalDisc;
				if (!isFinite(percent)) percent = 0;
				row.item_discount_percent = percent;
				if (discInput) discInput.value = String(percent);
				const price = parseFloat(row.price || 0) || 0;
				const discounted = price > 0 && percent > 0 ? price * (percent >= 100 ? 0 : (1 - percent/100)) : price;
				if (discPriceInput) discPriceInput.value = fmt(discounted);
				if (discSpan) discSpan.textContent = percent.toFixed(1) + '%';
				if (priceSpan) priceSpan.textContent = '$ ' + fmt(discounted);
				// Toggle editable vs display depending on exclude flag
				if (isExcluded) {
					if (discInput) discInput.style.display = 'inline-block';
					if (discPriceInput) discPriceInput.style.display = 'inline-block';
					if (discSpan) discSpan.style.display = 'none';
					if (priceSpan) priceSpan.style.display = 'none';
				} else {
					if (discInput) discInput.style.display = 'none';
					if (discPriceInput) discPriceInput.style.display = 'none';
					if (discSpan) discSpan.style.display = 'inline';
					if (priceSpan) priceSpan.style.display = 'inline';
				}
			}

			function renderRows(rows){
				if (!body) return;
				body.innerHTML = '';
				rows.forEach(function(r, idx){
					const disc = parseFloat(r.item_discount_percent || 0) || 0;
					const price = parseFloat(r.price || 0) || 0;
					const discounted = price > 0 && disc > 0 ? price * (1 - disc/100) : price;
					const tr = document.createElement('tr');
					tr.setAttribute('data-index', String(idx));
					tr.innerHTML =
						'<td>' + (r.image ? '<img src=\"'+r.image+'\" alt=\"\" style=\"width:32px;height:32px;object-fit:contain;\" />' : '') + '</td>' +
						'<td>'+ String(r.name || '') +'</td>' +
						'<td><code>'+ String(r.sku || '') +'</code></td>' +
						'<td style=\"text-align:center;\">' +
							'<select class=\"hp-fb-role\">' +
								'<option value=\"base\"'+(r.role==='base'?' selected':'')+'>Base</option>' +
								'<option value=\"optional\"'+(r.role==='optional'?' selected':'')+'>Optional</option>' +
								'<option value=\"upsell\"'+(r.role==='upsell'?' selected':'')+'>Upsell</option>' +
							'</select>' +
						'</td>' +
						'<td style=\"text-align:center;\"><input type=\"checkbox\" class=\"hp-fb-exclude-gd\"'+(r.exclude_global_discount ? ' checked' : '')+' /></td>' +
						'<td style=\"text-align:right;\">$ '+ fmt(price) +'</td>' +
						'<td style=\"text-align:center;\">' +
							'<span class=\"hp-fb-disc-display\"></span>' +
							'<input type=\"number\" step=\"0.1\" min=\"0\" max=\"100\" class=\"hp-fb-item-disc\" value=\"'+ String(disc) +'\" style=\"width:80px;display:none;\" />' +
						'</td>' +
						'<td style=\"text-align:right;\" class=\"hp-fb-discounted-cell\">' +
							'<span class=\"hp-fb-disc-price-display\"></span>' +
							'<input type=\"number\" step=\"0.01\" min=\"0\" class=\"hp-fb-discounted-input\" value=\"'+ fmt(discounted) +'\" style=\"width:90px;text-align:right;display:none;\" />' +
						'</td>' +
						'<td><button type=\"button\" class=\"button-link hp-fb-remove-row\">Remove</button>' +
							'<input type=\"hidden\" class=\"hp-fb-product-id\" value=\"'+ String(r.product_id) +'\" />' +
							'<input type=\"hidden\" class=\"hp-fb-sku\" value=\"'+ String(r.sku || '') +'\" />' +
						'</td>';
					body.appendChild(tr);
					syncRowUI(r, tr);
				});
			}

			let currentRows = initialRows.slice();
			renderRows(currentRows);

			if (body) {
				body.addEventListener('click', function(e){
					const t = e.target;
					if (t && t.classList.contains('hp-fb-remove-row')) {
						const tr = t.closest('tr');
						if (!tr) return;
						const idx = parseInt(tr.getAttribute('data-index') || '-1', 10);
						if (idx >= 0) {
							currentRows.splice(idx, 1);
							renderRows(currentRows);
						}
					}
				});
				body.addEventListener('change', function(e){
					const t = e.target;
					const tr = t.closest ? t.closest('tr') : null;
					if (!tr) return;
					const idx = parseInt(tr.getAttribute('data-index') || '-1', 10);
					if (idx < 0 || !currentRows[idx]) return;
					if (t.classList.contains('hp-fb-role')) {
						currentRows[idx].role = t.value;
					} else if (t.classList.contains('hp-fb-exclude-gd')) {
						currentRows[idx].exclude_global_discount = t.checked ? 1 : 0;
						syncRowUI(currentRows[idx], tr);
					} else if (t.classList.contains('hp-fb-item-disc')) {
						const v = parseFloat(t.value || '0') || 0;
						currentRows[idx].item_discount_percent = v;
						syncRowUI(currentRows[idx], tr);
					} else if (t.classList.contains('hp-fb-discounted-input')) {
						const price = parseFloat(currentRows[idx].price || 0) || 0;
						const discounted = parseFloat(t.value || '0') || 0;
						let percent = 0;
						if (price > 0) {
							percent = Math.max(0, Math.min(100, ((price - discounted) / price) * 100));
						}
						currentRows[idx].item_discount_percent = percent;
						syncRowUI(currentRows[idx], tr);
					}
				});
			}

			function showStatus(msg, isError){
				if (!statusEl) return;
				statusEl.textContent = msg || '';
				statusEl.style.color = isError ? '#c00' : '#008000';
			}

			// When global discount changes, immediately refresh all row displays
			const globalDiscInput = document.getElementById('hp-fb-global-discount');
			if (globalDiscInput) {
				globalDiscInput.addEventListener('input', function(){
					const trs = body ? Array.prototype.slice.call(body.querySelectorAll('tr')) : [];
					trs.forEach(function(tr){
						const idx = parseInt(tr.getAttribute('data-index') || '-1', 10);
						if (idx >= 0 && currentRows[idx]) {
							syncRowUI(currentRows[idx], tr);
						}
					});
				});
			}

			const saveBtn = document.getElementById('hp-fb-funnel-save');
			if (saveBtn) {
				saveBtn.addEventListener('click', function(){
					const globalDisc = parseFloat(document.getElementById('hp-fb-global-discount').value || '0') || 0;
					const payBg = (document.getElementById('hp-fb-pay-bg') || {}).value || '';
					const payCard = (document.getElementById('hp-fb-pay-card') || {}).value || '';
					const payAccent = (document.getElementById('hp-fb-pay-accent') || {}).value || '';
					const faviconId = (faviconIdInput && faviconIdInput.value) ? faviconIdInput.value : '';
					const socialText = (document.getElementById('hp-fb-social-text') || {}).value || '';
					// Refresh rows from DOM in case indexes shifted
					const trs = body ? Array.prototype.slice.call(body.querySelectorAll('tr')) : [];
					currentRows = trs.map(function(tr){
						const idx = parseInt(tr.getAttribute('data-index') || '0', 10);
						const base = initialRows[idx] || {};
						const pid = parseInt((tr.querySelector('.hp-fb-product-id') || {}).value || '0', 10);
						const sku = (tr.querySelector('.hp-fb-sku') || {}).value || '';
						const roleSel = tr.querySelector('.hp-fb-role');
						const excludeCbx = tr.querySelector('.hp-fb-exclude-gd');
						const discInput = tr.querySelector('.hp-fb-item-disc');
						return {
							product_id: pid,
							sku: sku,
							role: roleSel ? roleSel.value : 'base',
							exclude_global_discount: excludeCbx && excludeCbx.checked ? 1 : 0,
							item_discount_percent: discInput ? (parseFloat(discInput.value || '0') || 0) : 0,
						};
					});
					showStatus('Saving...', false);
					const payload = {
						funnel_id: funnelId,
						nonce: nonce,
						global_discount_percent: globalDisc,
						products: currentRows,
						payment_style: {
							background_color: payBg,
							card_color: payCard,
							accent_color: payAccent,
						},
						branding: {
							favicon_id: faviconId ? parseInt(faviconId, 10) || 0 : 0,
							social_text: socialText,
						},
					};
					fetch(ajaxurl + '?action=hp_fb_save_funnel_config', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(payload),
					})
						.then(function(r){ return r.json(); })
						.then(function(data){
							if (data && data.success) {
								showStatus('Saved.', false);
							} else {
								showStatus((data && data.data && data.data.message) ? data.data.message : 'Save failed', true);
							}
						})
						.catch(function(){
							showStatus('Save failed', true);
						});
				});
			}

			// Product search
			const searchInput = document.getElementById('hp-fb-product-search');
			const resultsBox = document.getElementById('hp-fb-product-search-results');
			let currentSearchResults = [];
			function renderSearchResults(items){
				if (!resultsBox) return;
				currentSearchResults = Array.isArray(items) ? items.slice() : [];
				if (!items || !items.length) { resultsBox.innerHTML = '<p class=\"description\">No products found.</p>'; return; }
				const ul = document.createElement('ul');
				ul.style.listStyle = 'disc';
				ul.style.marginLeft = '18px';
				items.forEach(function(p){
					const li = document.createElement('li');
					li.innerHTML = '<strong>' + p.name + '</strong> <code>' + (p.sku || '') + '</code> &mdash; $ ' + fmt(p.price || 0) +
						' <button type=\"button\" class=\"button-link hp-fb-add-product\" data-product_id=\"'+p.id+'\">Add</button>';
					ul.appendChild(li);
				});
				resultsBox.innerHTML = '';
				resultsBox.appendChild(ul);
			}
			let searchTimer = null;
			function performSearch(){
				const term = searchInput ? (searchInput.value || '').trim() : '';
				if (!term || term.length < 3) { renderSearchResults([]); return; }
				if (resultsBox) { resultsBox.innerHTML = '<p class=\"description\">Searching&hellip;</p>'; }
				const payload = {
					action: 'hp_fb_search_products',
					nonce: nonce,
					term: term,
				};
				fetch(ajaxurl + '?action=hp_fb_search_products&_ajax_nonce='+encodeURIComponent(nonce)+'&funnel_id='+encodeURIComponent(funnelId)+'&term='+encodeURIComponent(term))
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (!data || !data.success) {
							renderSearchResults([]);
							return;
						}
						renderSearchResults(data.data || []);
					})
					.catch(function(){
						renderSearchResults([]);
					});
			}
			if (resultsBox) {
				resultsBox.addEventListener('click', function(e){
					const t = e.target;
					if (t && t.classList.contains('hp-fb-add-product')) {
						const pid = parseInt(t.getAttribute('data-product_id') || '0', 10);
						const prod = currentSearchResults.find(function(x){ return parseInt(x.id,10) === pid; });
						if (!prod) return;
						if (currentRows.some(function(r){ return parseInt(r.product_id,10) === pid; })) {
							showStatus('Product already added.', true);
							return;
						}
						currentRows.push({
							product_id: pid,
							name: prod.name,
							sku: prod.sku || '',
							image: prod.image || '',
							price: prod.price || 0,
							role: 'base',
							exclude_global_discount: 0,
							item_discount_percent: 0,
						});
						renderRows(currentRows);
						showStatus('Product added. Don\'t forget to Save.', false);
					}
				});
			}
			if (searchInput) {
				searchInput.addEventListener('keyup', function(e){
					const term = (searchInput.value || '').trim();
					if (term.length < 3) {
						if (resultsBox) resultsBox.innerHTML = '<p class=\"description\">Type at least 3 characters to search.</p>';
						if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
						return;
					}
					if (searchTimer) clearTimeout(searchTimer);
					searchTimer = setTimeout(function(){ performSearch(); }, 250);
				});
				searchInput.addEventListener('focus', function(){
					const term = (searchInput.value || '').trim();
					if (term.length >= 3) {
						performSearch();
					}
				});
			}
		})();</script>
		<?php
	}
}


