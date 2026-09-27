<?php

namespace WPWatchdog\Agent;

use WPWatchdog\Agent\Http\Client;

class Api
{
    public static function send_plugin_state_event(string $event_type): void
    {
        if (!class_exists(Client::class)) {
            return;
        }

        $client = new Client();
        $client->dispatch([
            'event_type' => $event_type,
            'timestamp' => gmdate('c'),
            'payload' => [
                'plugin' => 'site-watchdog',
                'status' => $event_type === 'plugin_activated' ? 'activated' : 'deactivated',
            ],
        ]);
    }

    /**
     * Send a custom state-change event to Site Watchdog (ARCHITECTURE.md 5.1).
     */
    public static function capture_event(string $action, array $details = [], ?string $target = null): bool
    {
        if (!class_exists(Client::class)) {
            return false;
        }

        $payload = ['action' => $action] + $details;

        if (null !== $target) {
            $payload['target'] = $target;
        }

        $client = new Client();

        return $client->dispatch([
            'site_id' => get_option('site_watchdog_site_id', ''),
            'environment' => get_option('site_watchdog_environment', 'production'),
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => $payload,
        ]);
    }

    /**
     * Send a custom incident to Site Watchdog (ARCHITECTURE.md 5.5).
     */
    public static function capture_incident(string $details, string $severity = 'error', ?string $source = null, ?string $channel = null): bool
    {
        if (!class_exists(Client::class)) {
            return false;
        }

        if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
            $severity = 'info';
        }

        $client = new Client();

        return $client->dispatch([
            'site_id' => get_option('site_watchdog_site_id', ''),
            'environment' => get_option('site_watchdog_environment', 'production'),
            'timestamp' => gmdate('c'),
            'source' => $source ?? 'on_demand',
            'severity' => $severity,
            'details' => $details,
            'channel' => $channel,
        ], true, '/' . SITE_WATCHDOG_API_VERSION . '/incidents/on-demand');
    }
}
