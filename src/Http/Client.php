<?php

namespace WPWatchdog\Agent\Http;

use WPWatchdog\Agent\CircuitBreaker;
use WPWatchdog\Agent\Sanitizer;

class Client
{
    public function dispatch(array $payload, bool $queue_on_failure = true, string $endpoint = '/' . SITE_WATCHDOG_API_VERSION . '/events/ingest'): bool
    {
        $sanitized = Sanitizer::clean_payload($payload);

        $breaker = new CircuitBreaker();
        if (!$breaker->is_allowed()) {
            return false;
        }

        $api_key = get_option('site_watchdog_api_key', '');
        $is_verified = (bool) get_option('site_watchdog_api_key_verified', false);
        if ('' === $api_key || !$is_verified) {
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

        $response = wp_remote_post(SITE_WATCHDOG_API_URL . $endpoint, $request_args);
        $response_code = wp_remote_retrieve_response_code($response);

        if (is_wp_error($response) || 200 > (int) $response_code || 299 < (int) $response_code) {
            if (401 === (int) $response_code) {
                update_option('site_watchdog_api_key_verified', false, false);
                error_log('Unauthorized site API key; event delivery paused.');

                return false;
            }

            if ($queue_on_failure) {
                $queue = new Queue();
                $queue->add($sanitized);
            }

            return false;
        }

        return true;
    }
}
