<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$options = [
    'site_watchdog_api_key',
    'site_watchdog_api_key_verified',
    'site_watchdog_site_id',
    'site_watchdog_offline_queue',
    'wp_watchdog_api_key',
    'wp_watchdog_site_id',
    'wp_watchdog_retry_queue',
];

foreach ($options as $option) {
    delete_option($option);
}

delete_transient('site_watchdog_cached_incidents');
delete_transient('wp_watchdog_cached_incidents');

global $wpdb;
$transient_prefixes = [
    $wpdb->esc_like('_transient_site_watchdog_cached_incidents_') . '%',
    $wpdb->esc_like('_transient_timeout_site_watchdog_cached_incidents_') . '%',
    $wpdb->esc_like('_transient_wp_watchdog_cached_incidents_') . '%',
    $wpdb->esc_like('_transient_timeout_wp_watchdog_cached_incidents_') . '%',
];
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $transient_prefixes
    )
);

foreach (['site_watchdog_retry_queue', 'site_watchdog_heartbeat', 'wp_watchdog_retry_queue', 'wp_watchdog_heartbeat'] as $hook) {
    wp_clear_scheduled_hook($hook);
}
