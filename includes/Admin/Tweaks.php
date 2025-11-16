<?php
namespace HP_FB\Admin;

if (!defined('ABSPATH')) { exit; }

class Tweaks {
	public function register(): void {
		add_action('admin_head', [$this, 'injectCss']);
	}

	/**
	 * Inject minimal CSS fixes for the custom order editor layout when needed.
	 * Scope narrowly to the EAO editor page to avoid side effects elsewhere.
	 */
	public function injectCss(): void {
		// Only run in wp-admin pages
		if (!is_admin()) { return; }
		$page = isset($_GET['page']) ? (string) $_GET['page'] : '';
		if ($page !== 'eao_custom_order_editor_page') { return; }
		?>
		<style id="hp-fb-admin-tweaks">
			/* Avoid excessive whitespace in products table holder on some themes */
			#eao-order-items .tabulator-tableholder { height: auto !important; min-height: 0 !important; }
			#eao-order-items .tabulator { min-height: 0 !important; }
		</style>
		<?php
	}
}


