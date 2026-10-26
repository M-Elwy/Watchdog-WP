<?php

namespace WebsiteWatchdog\Agent\Http;

use WebsiteWatchdog\Agent\CircuitBreaker;
use WebsiteWatchdog\Agent\Sanitizer;

class Client
{
    /**
     * Side-effect-free key check against /sites/verify.
     *
     * 'valid' is true if accepted, false if rejected (401/403 or no site_id), null if the server was unreachable or errored.
     *
     * @return array{valid: ?bool, site_id: string, error: string}
     */
    public function check_key(string $api_key): array
    {
        if ('' === $api_key) {
            return ['valid' => false, 'site_id' => '', 'error' => 'Empty API key'];
        }

        $version_path = '/' . WEBSITE_WATCHDOG_API_VERSION;
        $url = rtrim(WEBSITE_WATCHDOG_API_URL, '/') . (false !== strpos(WEBSITE_WATCHDOG_API_URL, $version_path) ? '' : $version_path) . '/sites/verify';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['site_url' => home_url()]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return ['valid' => null, 'site_id' => '', 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (401 === $code || 403 === $code) {
            return ['valid' => false, 'site_id' => '', 'error' => 'HTTP ' . $code];
        }

        if (200 !== $code) {
            return ['valid' => null, 'site_id' => '', 'error' => 'HTTP ' . $code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $site_id = isset($body['data']['site_id']) ? sanitize_text_field((string) $body['data']['site_id']) : '';

        return $site_id
            ? ['valid' => true, 'site_id' => $site_id, 'error' => '']
            : ['valid' => false, 'site_id' => '', 'error' => 'Missing site_id in response'];
    }

    /**
     * Verifies the stored key and persists the result. A null (unreachable) result leaves the flag unchanged.
     */
    public function verify(): ?bool
    {
        $result = $this->check_key((string) get_option('website_watchdog_api_key', ''));

        if (null !== $result['valid']) {
            update_option('website_watchdog_api_key_verified', $result['valid'], false);
            if ($result['valid']) {
                update_option('website_watchdog_site_id', $result['site_id']);
            } else {
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('Site API key rejected; event delivery paused. ' . $result['error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }

        return $result['valid'];
    }
    public function dispatch(array $payload, bool $queue_on_failure = true, string $endpoint = '/' . WEBSITE_WATCHDOG_API_VERSION . '/events/ingest'): bool
    {
        $sanitized = Sanitizer::clean_payload($payload);

        $breaker = new CircuitBreaker();
        if (!$breaker->is_allowed()) {
            return false;
        }

        $api_key = get_option('website_watchdog_api_key', '');
        if ('' === $api_key) {
            return false;
        }

        // Unverified key: keep the event; Queue::flush() re-verifies before retrying.
        if (!get_option('website_watchdog_api_key_verified', false)) {
            if ($queue_on_failure) {
                (new Queue())->add($sanitized);
            }

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

        $response = wp_remote_post(WEBSITE_WATCHDOG_API_URL . $endpoint, $request_args);
        // Non-blocking requests return no response; a non-error result means the request was sent.
        if (!is_wp_error($response) && empty($request_args['blocking'])) {
            return true;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if (is_wp_error($response) || 200 > (int) $response_code || 299 < (int) $response_code) {
            if (401 === (int) $response_code) {
                update_option('website_watchdog_api_key_verified', false, false);
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('Unauthorized site API key; event delivery paused.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }

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
