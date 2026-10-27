<?php
/**
 * Browser entry point for sites without WP-CLI. Only loaded when wp-config.php defines
 * WEBSITE_WATCHDOG_SELFTEST as true (see website-watchdog.php). Adds Tools > Watchdog Self-Test.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', static function (): void {
    add_management_page(
        'Website Watchdog Self-Test',
        'Watchdog Self-Test',
        'manage_options',
        'website-watchdog-selftest',
        static function (): void {
            if (!current_user_can('manage_options')) {
                wp_die('You do not have sufficient permissions to access this page.');
            }

            echo '<div class="wrap"><h1>Website Watchdog Self-Test</h1>';
            echo '<p>Runs the plugin test suite against this site. No data is sent to the Watchdog service and all settings are restored afterwards.</p>';

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
            if (isset($_POST['website_watchdog_run_selftest'])) {
                check_admin_referer('website_watchdog_selftest');
                require_once __DIR__ . '/Runner.php';

                $filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : '';
                $results = \WebsiteWatchdog\Agent\SelfTest\Runner::run($filter);
                echo \WebsiteWatchdog\Agent\SelfTest\Runner::html($results); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the reporter.
            }

            echo '<form method="post" style="margin-top:1em">';
            wp_nonce_field('website_watchdog_selftest');
            echo '<input type="text" name="filter" placeholder="Optional name filter, e.g. ClientTest" class="regular-text" /> ';
            echo '<input type="submit" name="website_watchdog_run_selftest" class="button button-primary" value="Run tests" />';
            echo '</form></div>';
        }
    );
});
