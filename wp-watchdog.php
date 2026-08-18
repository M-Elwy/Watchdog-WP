<?php

/**
 * Plugin Name: WordPress Watchdog
 * Plugin URI: https://github.com/wordpress-dev/wp-watchdog
 * Description: WordPress Agent plugin for WordPress Watchdog.
 * Version: 1.0.0
 * Author: WordPress Watchdog
 * Text Domain: wp-watchdog
 */

defined('ABSPATH') || exit;

if (!defined('WP_WATCHDOG_VERSION')) {
    define('WP_WATCHDOG_VERSION', '1.0.0');
}

if (!defined('WP_WATCHDOG_PATH')) {
    define('WP_WATCHDOG_PATH', plugin_dir_path(__FILE__));
}

if (!defined('WP_WATCHDOG_API_URL')) {
    define('WP_WATCHDOG_API_URL', 'https://saas-wd.aidly.net');
}

if (!defined('WP_WATCHDOG_API_VERSION')) {
    define('WP_WATCHDOG_API_VERSION', 'v1');
}

spl_autoload_register(static function ($class): void {
    $prefix = 'WPWatchdog\\Agent\\';

    if (0 !== strpos($class, $prefix)) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = WP_WATCHDOG_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

add_action('plugins_loaded', static function (): void {
    $plugin = new \WPWatchdog\Agent\Plugin();
    $plugin->register();
});
