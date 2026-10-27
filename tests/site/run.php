<?php
/**
 * WP-CLI entry point. Run from the WordPress root on any site where the plugin is active:
 *
 *   wp eval-file wp-content/plugins/<plugin-dir>/tests/site/run.php [name-filter] [--verbose]
 *
 * Exits non-zero when any test fails, so it can be used in deploy scripts.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WordPress: wp eval-file tests/site/run.php\n");
    exit(1);
}

if (!defined('WEBSITE_WATCHDOG_VERSION')) {
    fwrite(STDERR, "The Website Watchdog plugin is not active on this site.\n");
    exit(1);
}

require_once __DIR__ . '/Runner.php';

$website_watchdog_args = isset($args) && is_array($args) ? $args : [];
$website_watchdog_verbose = in_array('--verbose', $website_watchdog_args, true);
$website_watchdog_filter = '';
foreach ($website_watchdog_args as $website_watchdog_arg) {
    if ('--verbose' !== $website_watchdog_arg) {
        $website_watchdog_filter = (string) $website_watchdog_arg;
        break;
    }
}

$website_watchdog_results = \WebsiteWatchdog\Agent\SelfTest\Runner::run($website_watchdog_filter);
echo \WebsiteWatchdog\Agent\SelfTest\Runner::text($website_watchdog_results, $website_watchdog_verbose);

$website_watchdog_summary = \WebsiteWatchdog\Agent\SelfTest\Runner::summarize($website_watchdog_results);
if ($website_watchdog_summary['fail'] + $website_watchdog_summary['error'] > 0) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
