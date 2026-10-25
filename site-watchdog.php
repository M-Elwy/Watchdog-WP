<?php

/**
 * Plugin Name: Website Watchdog - PHP Error & Incident Alerts
 * Plugin URI: https://wd.aidly.net/
 * Description: WordPress Agent plugin for Site Watchdog.
 * Version: 1.0.0
 * Author: Site Watchdog
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-watchdog
 */

defined('ABSPATH') || exit;

if (!defined('SITE_WATCHDOG_VERSION')) {
    define('SITE_WATCHDOG_VERSION', '1.0.0');
}

if (!defined('SITE_WATCHDOG_PATH')) {
    define('SITE_WATCHDOG_PATH', plugin_dir_path(__FILE__));
}

if (!defined('SITE_WATCHDOG_API_URL')) {
    define('SITE_WATCHDOG_API_URL', 'https://wd.aidly.net/api');
}

if (!defined('SITE_WATCHDOG_API_VERSION')) {
    define('SITE_WATCHDOG_API_VERSION', 'v1');
}

spl_autoload_register(static function ($class): void {
    $prefix = 'WPWatchdog\\Agent\\';

    if (0 !== strpos($class, $prefix)) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = SITE_WATCHDOG_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
register_activation_hook(__FILE__, static function (): void {
    \WPWatchdog\Agent\Api::send_plugin_state_event('plugin_activated');
});

register_deactivation_hook(__FILE__, static function (): void {
    \WPWatchdog\Agent\Api::send_plugin_state_event('plugin_deactivated');
});
add_action('plugins_loaded', static function (): void {
    $plugin = new \WPWatchdog\Agent\Plugin();
    $plugin->register();
});

if (!function_exists('watchdog_capture_event')) {
    function watchdog_capture_event(string $action, array $details = [], ?string $target = null): bool
    {
        return \WPWatchdog\Agent\Api::capture_event($action, $details, $target);
    }
}

if (!function_exists('watchdog_capture_incident')) {
    function watchdog_capture_incident(string $details, string $severity = 'error', ?string $source = null): bool
    {
        return \WPWatchdog\Agent\Api::capture_incident($details, $severity, $source);
    }
}
