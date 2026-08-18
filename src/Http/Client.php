<?php

namespace WPWatchdog\Agent\Http;

use WPWatchdog\Agent\CircuitBreaker;
use WPWatchdog\Agent\Sanitizer;

class Client
{
    public function dispatch(array $payload, bool $queue_on_failure = true, string $endpoint = '/events/ingest'): bool
    {
        $sanitized = Sanitizer::clean_payload($payload);

        $breaker = new CircuitBreaker();
        if (!$breaker->is_allowed()) {
            return false;
        }

        $api_key = get_option('wp_watchdog_api_key', '');
        if ('' === $api_key) {
            return false;
        }

        $request_args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($sanitized),
            'timeout' => 10,
            'blocking' => false,
        ];

        $response = wp_remote_post(WP_WATCHDOG_API_URL . $endpoint, $request_args);
        $response_code = wp_remote_retrieve_response_code($response);

        if (is_wp_error($response) || 200 > (int) $response_code || 299 < (int) $response_code) {
            if ($queue_on_failure) {
                $queue = new Queue();
                $queue->add($sanitized);
            }

            return false;
        }

        return true;
    }
}
