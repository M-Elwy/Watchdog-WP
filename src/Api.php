<?php

namespace WebsiteWatchdog\Agent;

use WebsiteWatchdog\Agent\Http\Client;

class Api
{
    public static function send_plugin_state_event(string $event_type): void
    {
        if (!class_exists(Client::class)) {
            return;
        }

        $client = new Client();
        $client->dispatch([
            'site_id' => get_option('website_watchdog_site_id', ''),
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => [
                'action' => $event_type,
                'target' => 'Website Watchdog',
                'details' => ['plugin_slug' => 'website-watchdog'],
            ],
        ]);
    }

    /**
     * Send a custom state-change event to Website Watchdog (ARCHITECTURE.md 5.1).
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
            'site_id' => get_option('website_watchdog_site_id', ''),
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => $payload,
        ]);
    }

    /**
     * Send a custom incident to Website Watchdog (ARCHITECTURE.md 5.5).
     */
    public static function capture_incident(string $details, string $severity = 'error', ?string $source = null): bool
    {
        if (!class_exists(Client::class)) {
            return false;
        }

        if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
            $severity = 'info';
        }

        $client = new Client();

        return $client->dispatch([
            'site_id' => get_option('website_watchdog_site_id', ''),
            'timestamp' => gmdate('c'),
            'source' => $source ?? 'on_demand',
            'severity' => $severity,
            'details' => $details,
        ], true, '/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/on-demand');
    }
}
