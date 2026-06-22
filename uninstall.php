<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Removes all data this plugin has stored in the database so no sensitive
 * information (GitHub token, build log) is left behind after uninstallation.
 *
 * @package CODESM\DecoupledBundle
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('codesm_decoupled_bundle_settings');
delete_option('codesm_decoupled_bundle_builds_log');

// Remove all transients with the plugin prefix
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    '%codesm_decoupled_bundle_%transient%',
    '%codesm_decoupled_bundle_rl_%'
));

// Remove any pending cron event
require_once ABSPATH . 'wp-includes/class-wp-hook.php';
$timestamp = wp_next_scheduled('codesm_decoupled_bundle_auto_build');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'codesm_decoupled_bundle_auto_build');
}
