<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}
// Clean plugin transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hp_fb_draft_%' OR option_name LIKE '_transient_timeout_hp_fb_draft_%'");


