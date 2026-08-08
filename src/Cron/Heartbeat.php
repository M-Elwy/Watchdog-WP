<?php

namespace WPWatchdog\Agent\Cron;

use WPWatchdog\Agent\Http\Client;

class Heartbeat
{
    public function register(): void
    {
        if (!wp_next_scheduled('wp_watchdog_retry_queue')) {
            wp_schedule_event(time(), '15minutes', 'wp_watchdog_retry_queue');
        }

        add_action('wp_watchdog_retry_queue', [$this, 'run_queue_flush']);
        add_action('wp_watchdog_heartbeat', [$this, 'send_ping']);

        if (!wp_next_scheduled('wp_watchdog_heartbeat')) {
            wp_schedule_event(time(), '15minutes', 'wp_watchdog_heartbeat');
        }
    }

    public function run_queue_flush(): void
    {
        $queue = new \WPWatchdog\Agent\Http\Queue();
        $queue->flush();
    }

    public function send_ping(): void
    {
        $client = new Client();
        $client->dispatch([
            'event_type' => 'site_ping',
            'timestamp' => gmdate('c'),
            'site_id' => get_option('wp_watchdog_site_id', ''),
            'payload' => [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ],
        ], false, '/sites/ping');
    }
}
