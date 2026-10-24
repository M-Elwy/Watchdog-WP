<?php

namespace WPWatchdog\Agent\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WPWatchdog\Agent\Http\Client;
use WPWatchdog\Agent\Sanitizer;

class JsErrorEndpoint
{
    private const MAX_BODY_BYTES = 16384;
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW = 60;
    private const ALLOWED_FIELDS = ['type', 'message', 'source', 'line', 'column', 'stack'];

    protected $http_client;

    public function __construct(?Client $http_client = null)
    {
        $this->http_client = $http_client ?: new Client();
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('site-watchdog/' . SITE_WATCHDOG_API_VERSION, '/js-error', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_request'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        $client_address = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $rate_key = 'site_watchdog_js_rate_' . md5($client_address);
        $requests = get_transient($rate_key);
        $requests = is_array($requests) ? $requests : [];
        $requests = array_values(array_filter($requests, static function ($timestamp): bool {
            return is_int($timestamp) && (time() - $timestamp) < self::RATE_WINDOW;
        }));
        if (count($requests) >= self::RATE_LIMIT) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Rate limit exceeded.'], 429);
        }
        $requests[] = time();
        set_transient($rate_key, $requests, self::RATE_WINDOW);

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        $payload = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        foreach (['type', 'message', 'source', 'stack'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = is_scalar($payload[$field]) ? substr(sanitize_textarea_field((string) $payload[$field]), 0, 2000) : '';
            }
        }
        foreach (['line', 'column'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = absint($payload[$field]);
            }
        }

        $sanitized = Sanitizer::clean_payload($payload);

        $forward_payload = [
            'event_type' => 'js_error',
            'timestamp' => gmdate('c'),
            'payload' => $sanitized,
        ];

        $this->http_client->dispatch($forward_payload);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
