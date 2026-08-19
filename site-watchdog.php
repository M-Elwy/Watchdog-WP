<?php

/**
 * Plugin Name: Site Watchdog
 * Plugin URI: https://github.com/wordpress-dev/site-watchdog
 * Description: WordPress Agent plugin for Site Watchdog.
 * Version: 1.0.0
 * Author: Site Watchdog
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
    define('SITE_WATCHDOG_API_URL', 'https://saas-wd.aidly.net');
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
$send_plugin_state_event = static function (string $event_type): void {
    if (!class_exists('\WPWatchdog\Agent\Http\Client')) {
        return;
    }

    $client = new \WPWatchdog\Agent\Http\Client();
    $client->dispatch([
        'event_type' => $event_type,
        'timestamp' => gmdate('c'),
        'site_id' => get_option('site_watchdog_site_id', ''),
        'payload' => [
            'plugin' => 'site-watchdog',
            'status' => $event_type === 'plugin_activated' ? 'activated' : 'deactivated',
        ],
    ], false, '/events/ingest');
};

register_activation_hook(__FILE__, static function () use ($send_plugin_state_event): void {
    $send_plugin_state_event('plugin_activated');
});

register_deactivation_hook(__FILE__, static function () use ($send_plugin_state_event): void {
    $send_plugin_state_event('plugin_deactivated');
});
add_action('plugins_loaded', static function (): void {
    $plugin = new \WPWatchdog\Agent\Plugin();
    $plugin->register();
});
