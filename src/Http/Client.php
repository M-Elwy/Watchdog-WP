<?php

namespace WPWatchdog\Agent\Http;

use WPWatchdog\Agent\CircuitBreaker;
use WPWatchdog\Agent\Sanitizer;

class Client
{
    public function dispatch(array $payload): void
    {
        $sanitized = Sanitizer::clean_payload($payload);

        $breaker = new CircuitBreaker();
        if (!$breaker->is_allowed()) {
            return;
        }

        $api_key = get_option('wp_watchdog_api_key', '');
        if ('' === $api_key) {
            return;
        }

        $request_args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($sanitized),
            'timeout' => 1,
            'blocking' => false,
        ];

        wp_remote_post(WP_WATCHDOG_API_URL . '/events/ingest', $request_args);
    }
}
