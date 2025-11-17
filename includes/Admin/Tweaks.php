<?php
namespace HP_FB\Admin;

if (!defined('ABSPATH')) { exit; }

class Tweaks {
	public function register(): void {
		add_action('admin_head', [$this, 'injectCss']);
		add_action('admin_footer', [$this, 'injectOrderListHighlight']);
	}

	/**
	 * Inject minimal CSS fixes for the custom order editor layout when needed.
	 * Scope narrowly to the EAO editor page to avoid side effects elsewhere.
	 */
	public function injectCss(): void {
		if (!is_admin()) { return; }
		$page = isset($_GET['page']) ? (string) $_GET['page'] : '';
		?>
		<style id="hp-fb-admin-tweaks">
			<?php if ($page === 'eao_custom_order_editor_page') : ?>
				/* Avoid excessive whitespace in products table holder on some themes */
				#eao-order-items .tabulator-tableholder { height: auto !important; min-height: 0 !important; }
				#eao-order-items .tabulator { min-height: 0 !important; }
			<?php endif; ?>
			/* Emphasize test-mode payment method in order list once JS adds the class */
			.hp-fb-pm-test { color:#d63638 !important; font-weight:600; }
		</style>
		<?php
	}

	/**
	 * Add a tiny script on the orders list to highlight "Stripe - Test" payment method rows.
	 */
	public function injectOrderListHighlight(): void {
		if (!is_admin()) { return; }
		if (!function_exists('get_current_screen')) { return; }
		$screen = get_current_screen();
		if (!$screen || $screen->id !== 'edit-shop_order') { return; }
		?>
		<script>
		(function($){
			$(function(){
				$('td.column-order_payment_method').each(function(){
					var txt = $(this).text().trim();
					if (/Stripe\s*-\s*Test/i.test(txt)) {
						$(this).addClass('hp-fb-pm-test');
					}
				});
				// Also handle dynamic updates after quick search/filter
				$(document).ajaxComplete(function(){
					$('td.column-order_payment_method').each(function(){
						var txt = $(this).text().trim();
						if (/Stripe\s*-\s*Test/i.test(txt)) {
							$(this).addClass('hp-fb-pm-test');
						}
					});
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}


