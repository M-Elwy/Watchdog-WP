<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

function site_watchdog_uninstall(): void
{
    global $wpdb;

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

    $transient_prefixes = [
        '_transient_site_watchdog_cached_incidents_',
        '_transient_timeout_site_watchdog_cached_incidents_',
        '_transient_wp_watchdog_cached_incidents_',
        '_transient_timeout_wp_watchdog_cached_incidents_',
        '_transient_site_watchdog_js_rate_',
        '_transient_timeout_site_watchdog_js_rate_',
    ];

    foreach ($transient_prefixes as $prefix) {
        // One-off cleanup of paginated/rate-limit transients whose names are dynamic.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
    }

    foreach (['site_watchdog_retry_queue', 'site_watchdog_heartbeat', 'wp_watchdog_retry_queue', 'wp_watchdog_heartbeat'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

site_watchdog_uninstall();
